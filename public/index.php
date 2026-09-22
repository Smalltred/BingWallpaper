<?php

declare(strict_types=1);

/**
 * 前端控制器 —— 所有请求的唯一入口。
 *
 * 对应原 Node 版 backend/server.js 的路由与中间件部分：
 *   安全头 → CORS → 路由 → 限流（仅 /api）→ 静态 → SPA 回退 → 404 → 错误兜底
 *
 * 为什么用单一入口而不是多个 .php 文件直连：
 *   一是路由、限流、安全头这些横切逻辑只需要写一遍；
 *   二是 nginx / Apache 只要把「文件不存在的请求」都转到这里就够了，配置简单；
 *   三是目录穿越这类路径层面的安全校验集中在一处，好审。
 */

// 缓冲输出：任何意外的提前输出（BOM、php 文件尾随空白）
// 都不会导致 header() 失败。真正的响应在 Response 里 echo 后统一吐出。
ob_start();

use BingWallpaper\Archive;
use BingWallpaper\ArchiveUpdater;
use BingWallpaper\Bing;
use BingWallpaper\Cache;
use BingWallpaper\Config;
use BingWallpaper\Log;
use BingWallpaper\RateLimiter;
use BingWallpaper\Request;
use BingWallpaper\Response;
use BingWallpaper\StaticFiles;

// ---- 自动加载 ----
// 手写 PSR-4 加载器，因此这个项目**不需要 composer**。
// 存在 vendor/autoload.php 时优先使用，方便日后真引入依赖。
//
// 目录约定：public/ 是 Web 根（宝塔里把「运行目录」指向它），
// 应用代码在 public/ 之外的 app/ 里 —— 这样 src 永远不可能被直接访问，
// 也就不需要在 nginx/Apache 里写任何拒绝规则。
// 项目根 = public/ 的上一级。
//
// ⚠️ 这里刻意**不写** dirname(__DIR__)。
//
// 原因：开启 opcache 的 SAPI（php -S 内置服务器、php-fpm）会对它做**常量折叠**，
// 而这颗 PHP 8.2.33 (Windows) 在**路径含非 ASCII** 时折叠出的结果是错的 ——
// 实测 __DIR__ 已是 ...\必应图片展示\public，折叠出来的却是 ...\WorkBuddy（少了两级），
// 于是 is_file($projectRoot . '/app/Config.php') 为 false，autoloader 拿不到文件，
// 全站以 `Class "BingWallpaper\Config" not found` 500。
//
// 为什么以前没暴露：CLI 下 opcache.enable_cli 默认 Off（不折叠）→ 正常；
// 纯 ASCII 路径下也不折叠出错 → 正常（所以线上 Linux 站点通常没事）。
// 这个坑只在「本机中文路径 + 内置服务器」这一组合下才亮出来，且症状是全站 500、无堆栈，
// 极易被误判成 PHP 扩展缺失。
//
// 规避：先把 __DIR__ 落到变量，再用 realpath 求文件系统真值 ——
// 变量参与的函数调用不会被折叠成错值。realpath 失败时退回 dirname($here, 1)：
// 显式传 levels 的写法同样不参与折叠（实测两种写法都返回正确值）。
$here = __DIR__;
$projectRoot = realpath($here . '/..');
if ($projectRoot === false) {
    $projectRoot = dirname($here, 1);
}

if (is_file($projectRoot . '/vendor/autoload.php')) {
    require $projectRoot . '/vendor/autoload.php';
} else {
    spl_autoload_register(static function (string $class) use ($projectRoot): void {
        $prefix = 'BingWallpaper\\';
        if (!str_starts_with($class, $prefix)) {
            return;
        }

        $file = $projectRoot . '/app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    });
}

Config::bootstrap($projectRoot);

// ============================================================
//  壁纸库「每天首次访问」自动更新
// ============================================================
//
// 背景：壁纸库的数据集是另一个项目离线产出的，最后一天停在产出那一刻。
// 补新壁纸原先只靠宝塔计划任务（每天 08:00），没配 cron 的站点就会一直停在旧日期。
// 这里让**每天第一个访客**顺手把它补上，不依赖 cron 也能保持最新。
//
// 位置与开销：只在启动阶段做一次 needsRun()（就是两次小文件读），
// 不命中时开销可忽略 —— 命中概率每天只有寥寥几次。
//
// 关键：命中时**绝不在这个请求里同步跑更新**（那会让第一个访客白等 Bing 的 RTT）。
// 实际执行放在请求收尾阶段，并且先把响应放给访客 —— 见 ArchiveUpdater::deferAfterResponse()。
//
// 与计划任务的关系：共用同一把锁与同一个当日标记，谁先跑成另一个当天就不必再跑；
// cron 保留作兜底（没有访客的日子靠它）并且每次都会真跑，兼作自愈通道。
$archiveUpdater = new ArchiveUpdater();
try {
    if ($archiveUpdater->needsRun()) {
        $archiveUpdater->deferAfterResponse();
    }
} catch (Throwable $e) {
    // 更新相关的任何问题都不能影响正常请求
    Log::error('首访更新检查失败: ' . $e->getMessage());
}

// ---- 未捕获异常 → 统一 JSON 500 ----
// 响应体绝不带异常细节：这是公开服务，堆栈只应进日志。
set_exception_handler(static function (Throwable $e): void {
    Log::error('未捕获错误: ' . $e->getMessage() . PHP_EOL . $e->getTraceAsString());

    if (ob_get_level() > 0) {
        ob_end_clean();
    }

    if (!headers_sent()) {
        Response::securityHeaders();
        Response::json(['error' => '服务器内部错误'], 500);
    }
});

// ---- 全站响应头 ----
Response::securityHeaders();
Response::cors();

$method = Request::method();
$path = Request::path();

// /index.php 视同首页。
// 不加这条的话，直接访问入口文件会走到「带扩展名的路径按静态资源处理」那一支，
// 得到一个 404 JSON —— 而 .php 又必须被静态服务拒绝（否则会吐源码），
// 所以只能在这里归一化。
if ($path === '/index.php') {
    $path = '/';
}

// ============================================================
//  /api —— 统一限流
// ============================================================
//
// ⚠️ 这里的判断必须是「正好是 /api」或「/api/ 开头」，
// 不能图省事写成 str_starts_with($path, '/api') —— 那样 /api-docs
// （SPA 里的接口文档页，见 router.js）也会被归进接口：
//   1. 白吃一份接口限流额度（刷新文档页会消耗 200 次/分钟的配额）；
//   2. 更致命的是下面 SPA 回退处的同款判断会把 /api-docs 当作
//      「打错的接口路径」直接 404，SPA 永远看不到这个路由。
//   3. 还会给一个 HTML 页面下发 RateLimit-* 头。
// 所以抽成 $isApiPath，两处共用同一个语义。
$isApiPath = $path === '/api' || str_starts_with($path, '/api/');

if ($isApiPath) {
    if ($method === 'OPTIONS') {
        Response::preflight();
    }

    $limiter = new RateLimiter(
        Config::dataSubDir('ratelimit'),
        Config::rateLimitWindow(),
        Config::rateLimitMax()
    );

    $result = $limiter->hit(Request::clientIp(Config::trustProxy()));
    RateLimiter::applyHeaders($result);

    if (!$result['allowed']) {
        header('Retry-After: ' . $result['reset']);
        Response::json(['error' => '请求过于频繁，请稍后再试'], 429);
    }
}

$cache = static fn (): Cache => new Cache(Config::dataDir(), 'bing-wallpapers', Config::cacheTtl());

if ($method === 'GET' && $path === '/api/bing/today') {
    $result = (new Bing($cache()))->today('1080p');
    Response::json($result['body'], $result['status']);
}

if ($method === 'GET' && $path === '/api/bing/today-4k') {
    $result = (new Bing($cache()))->today('4k');
    Response::json($result['body'], $result['status']);
}

// /api/bing/image/:resolution —— 放在两条固定路径之后，避免误吞
if ($method === 'GET' && preg_match('#^/api/bing/image/([^/]+)$#', $path, $m) === 1) {
    $outcome = (new Bing($cache()))->imageRedirect(
        rawurldecode($m[1]),
        (string) Request::query('id', '')
    );

    if ($outcome['status'] === 302 && isset($outcome['location'])) {
        Response::redirect($outcome['location']);
    }

    Response::json(['error' => $outcome['error'] ?? '请求无效'], $outcome['status']);
}

// ============================================================
//  /api/archive —— 历史壁纸归档（数据来自 bingimages 数据集）
// ============================================================

/**
 * 读取可选的整数查询参数并做范围校验。
 * 越界一律 400，与 /api/bing/image 的入参校验保持同样的严格度 ——
 * 静默夹取值会掩盖调用方的 bug。
 */
$intParam = static function (string $name, int $default, int $min, int $max): int {
    $raw = Request::query($name);
    if ($raw === null || trim($raw) === '') {
        return $default;
    }

    $trimmed = trim($raw);
    if (preg_match('/^\d+$/', $trimmed) !== 1) {
        Response::json(['error' => "参数 {$name} 必须是正整数"], 400);
    }

    $value = (int) $trimmed;
    if ($value < $min || $value > $max) {
        Response::json(['error' => "参数 {$name} 超出允许范围（{$min}-{$max}）"], 400);
    }

    return $value;
};

$archive = static function () use ($intParam): Archive {
    $store = new Archive(Config::archiveDbPath());

    if (!$store->isAvailable()) {
        // 具体路径只进日志：公开服务不应把服务器目录结构回给调用方
        Log::error('数据集不可用: ' . $store->dbPath());
        Response::json(['error' => '壁纸数据集未就绪'], 503);
    }

    return $store;
};

if ($method === 'GET' && $path === '/api/archive/stats') {
    Response::json([
        'message' => 'success',
        'code' => 200,
        'data' => $archive()->stats(),
    ]);
}

if ($method === 'GET' && $path === '/api/archive/wallpapers') {
    $page = $intParam('page', 1, 1, 100000);
    $size = $intParam('size', Config::archivePageSize(), 1, Config::archiveMaxPageSize());

    $rawYear = trim((string) Request::query('year', ''));
    $year = $rawYear === '' ? null : $intParam('year', 0, 2016, 2100);

    $keyword = trim((string) Request::query('keyword', ''));
    if (mb_strlen($keyword) > 100) {
        Response::json(['error' => '参数 keyword 过长（最多 100 字符）'], 400);
    }

    Response::json([
        'message' => 'success',
        'code' => 200,
        'data' => $archive()->page($page, $size, $keyword, $year),
    ]);
}

// ============================================================
//  /docs —— 接口文档（正文在 app/views/docs.html）
// ============================================================
if ($method === 'GET' && ($path === '/docs' || $path === '/docs/')) {
    $docsFile = $projectRoot . '/app/views/docs.html';

    if (!is_readable($docsFile)) {
        Log::error('docs.html 不存在或不可读: ' . $docsFile);
        Response::html('<h1>文档加载失败</h1><p>请检查 app/views/docs.html 是否存在。</p>', 500);
    }

    $html = (string) file_get_contents($docsFile);

    // embed=1：SPA 用 iframe 内嵌本页时（见 frontend/src/views/docs.vue），
    // 给 <html> 打上 .embed —— docs.html 里的 .embed 规则会隐藏它自带的 topbar
    // 和底部「← 返回首页」，避免出现「SPA 导航 + 文档 topbar」双头部。
    //
    // 为什么在这边做而不是在 docs.html 里写一段内联 <script> 判断 location.search：
    // 响应头里的 CSP 是 script-src 'self'，内联脚本会被浏览器直接拦掉。
    // 服务端注入还顺带得到两个好处 —— 不闪一下 topbar（脚本版会先渲染再隐藏），
    // 以及 JS 被禁用时也能正确内嵌。不带参数访问时输出与原先逐字节一致。
    if (Request::query('embed') === '1') {
        $injected = preg_replace('/<html\b/', '<html class="embed"', $html, 1);
        // 注入点没匹配上就老实输出原文，不要因为「美化」把文档搞坏
        if (is_string($injected)) {
            $html = $injected;
        } else {
            Log::warn('docs.html 的 <html> 标签未匹配到，embed 模式未生效');
        }
    }

    Response::html($html);
}

// ============================================================
//  SEO 端点
// ============================================================
if ($method === 'GET' && $path === '/sitemap.xml') {
    $today = date('Y-m-d');
    $origin = htmlspecialchars(Config::siteOrigin(), ENT_XML1);

    Response::text(
        '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
        . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n"
        . "  <url>\n    <loc>{$origin}/</loc>\n"
        . "    <lastmod>{$today}</lastmod>\n"
        . "    <changefreq>daily</changefreq>\n    <priority>1.0</priority>\n  </url>\n"
        . "  <url>\n    <loc>{$origin}/archive</loc>\n"
        . "    <lastmod>{$today}</lastmod>\n"
        . "    <changefreq>daily</changefreq>\n    <priority>0.9</priority>\n  </url>\n"
        . "  <url>\n    <loc>{$origin}/docs</loc>\n"
        . "    <lastmod>{$today}</lastmod>\n"
        . "    <changefreq>weekly</changefreq>\n    <priority>0.6</priority>\n  </url>\n"
        . "</urlset>\n",
        'application/xml; charset=utf-8'
    );
}

if ($method === 'GET' && $path === '/robots.txt') {
    Response::text(
        "User-agent: *\nAllow: /\nDisallow: /api/\n"
        . 'Sitemap: ' . Config::siteOrigin() . "/sitemap.xml\n",
        'text/plain; charset=utf-8'
    );
}

// ============================================================
//  静态文件 → SPA 回退 → 404
// ============================================================
if ($method === 'GET') {
    // Web 根就是 public/ —— 构建产物（assets/ fonts/ 图标…）直接放在它下面，
    // 所以 URL 路径与物理路径是一致的，不需要任何拒绝规则：
    // public/ 里本来就只有「可以公开」的东西。
    $static = new StaticFiles([$projectRoot . '/public']);

    if ($static->trySend($path)) {
        exit;
    }

    // 带扩展名的路径按静态资源处理：缺失就老实 404。
    // 否则构建产物换 hash 后，旧页面请求旧 chunk 会拿到 HTML 而不是 404，
    // 排查报错时极易被误导。
    //
    // 另：/api 开头的路径绝不能走 SPA 回退，否则打错的接口路径会返回一份
    // 200 + HTML，调用方拿到的是网页而不是错误码，排查成本极高。
    // （Node 版是靠正则 /^\/(?!api)/ 做到这一点的。）
    // 判据复用上面的 $isApiPath：只覆盖真正的接口命名空间，
    // 不误伤 /api-docs 这类「名字以 api 开头、其实是页面」的路径。
    if (!$isApiPath && pathinfo($path, PATHINFO_EXTENSION) === '') {
        $index = $projectRoot . '/public/index.html';
        if (is_file($index)) {
            // index.html 引用的是带 hash 的资源名，它本身绝不能被长缓存，
            // 否则发新版后老访客会一直请求已删除的旧 chunk
            Response::text((string) file_get_contents($index), 'text/html; charset=utf-8', 200, [
                'Cache-Control' => 'no-cache',
            ]);
        }
    }
}

Response::json(['error' => '接口不存在'], 404);
