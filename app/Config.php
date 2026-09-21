<?php

declare(strict_types=1);

namespace BingWallpaper;

/**
 * 全局配置：.env / 环境变量 + 默认值。
 *
 * 对应原 Node 版的 backend/config.js，语义逐条保持一致：
 *   - 开发环境 CORS 放行全部；生产环境未显式配置时「不下发 CORS 头」
 *     （注意：不是发 '*'，也不是反射 Origin —— 前者等于放行全网）
 *   - trust proxy 生产默认信任 1 层反向代理，否则按 IP 限流会退化成全场共用一个桶
 *   - Bing 数据缓存 1 小时
 *
 * 配置优先级：真实环境变量 > .env 文件 > 代码默认值。
 * 这样容器/CI 里注入的环境变量能覆盖 .env，符合通行约定。
 */
final class Config
{
    private static ?string $projectRoot = null;

    /** @var array<string,string> */
    private static array $envFile = [];

    private static ?string $appEnv = null;

    public static function bootstrap(string $projectRoot): void
    {
        self::$projectRoot = rtrim($projectRoot, "/\\");
        self::$envFile = self::parseEnvFile(self::$projectRoot . '/.env');
    }

    public static function projectRoot(): string
    {
        if (self::$projectRoot === null) {
            throw new \RuntimeException('Config::bootstrap() 尚未调用');
        }

        return self::$projectRoot;
    }

    /**
     * 数据目录（缓存 / 限流计数）。
     *
     * 刻意**不抛异常**：目录建不出来时，缓存会退化成「每次都重新抓」（get 返回 null），
     * 限流会退化成放行（RateLimiter 失败开放）。这两件事都不该让整站 500 ——
     * 一个只读展示站，宁可慢一点、少一层保护，也不能因为磁盘权限问题全站不可用。
     */
    public static function dataDir(): string
    {
        $dir = self::projectRoot() . '/data';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return $dir;
    }

    /** 数据目录下的子目录，同样不抛异常 */
    public static function dataSubDir(string $name): string
    {
        $dir = self::dataDir() . '/' . $name;
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return $dir;
    }

    /**
     * 读取配置项。真实环境变量优先于 .env。
     */
    public static function env(string $key, ?string $default = null): ?string
    {
        $value = getenv($key);
        if ($value !== false && $value !== '') {
            return $value;
        }

        return self::$envFile[$key] ?? $default;
    }

    public static function appEnv(): string
    {
        if (self::$appEnv === null) {
            self::$appEnv = self::env('APP_ENV', 'development') ?? 'development';
        }

        return self::$appEnv;
    }

    public static function isProduction(): bool
    {
        return self::appEnv() === 'production';
    }

    public static function port(): int
    {
        $port = (int) (self::env('PORT', '2665') ?? '2665');

        return $port > 0 && $port < 65536 ? $port : 2665;
    }

    /**
     * 监听地址。默认只绑本机 —— PHP 内置服务器没有鉴权，
     * 默认 0.0.0.0 会把服务直接暴露给同网段。需要对外提供时显式设 HOST=0.0.0.0
     * （生产建议用 nginx + php-fpm，而不是内置服务器）。
     */
    public static function host(): string
    {
        return self::env('HOST', '127.0.0.1') ?? '127.0.0.1';
    }

    public static function siteOrigin(): string
    {
        return rtrim(self::env('SITE_ORIGIN', 'https://bing.hecady.com') ?? '', '/');
    }

    /**
     * bingimages 数据集（历史壁纸归档）所在目录。
     *
     * 刻意用「配置指向数据目录」而不是把 DB 复制进 app/：数据集由
     * bingimages/ 下的 merge 脚本独立产出与更新，复制进来就会有两份、
     * 并且必然漂移。这里只读引用它。
     *
     * 默认取项目根下的 bingimages/（它不在 Web 根 public/ 内，HTTP 拿不到），
     * 可用 BINGIMAGES_DIR 覆盖。
     */
    public static function bingImagesDir(): string
    {
        $raw = trim((string) self::env('BINGIMAGES_DIR', ''));

        if ($raw === '') {
            // 项目根下的 bingimages/ —— 与 app/ public/ 同级
            return str_replace('\\', '/', self::projectRoot()) . '/bingimages';
        }

        $normalized = str_replace('\\', '/', $raw);

        // 绝对路径（Windows 盘符或 POSIX 根）直接用；否则相对项目根解析
        $isAbsolute = preg_match('#^[A-Za-z]:/#', $normalized) === 1 || str_starts_with($normalized, '/');

        return $isAbsolute ? rtrim($normalized, '/') : self::projectRoot() . '/' . trim($normalized, '/');
    }

    /** 归档数据集的主库文件 */
    public static function archiveDbPath(): string
    {
        return self::bingImagesDir() . '/bing_wallpapers.db';
    }

    /** 归档分页的默认 / 最大每页条数 */
    public static function archivePageSize(): int
    {
        $v = (int) (self::env('ARCHIVE_PAGE_SIZE', '24') ?? '24');

        return $v > 0 ? min($v, 100) : 24;
    }

    public static function archiveMaxPageSize(): int
    {
        return 100;
    }

    /**
     * CORS 允许的来源。
     *
     * @return string|array<int,string>|false
     *        false 表示「不下发 CORS 头」——生产环境未配置时用这个，
     *        浏览器会按同源策略自行限制，是真正的收紧。
     */
    public static function corsOrigin(): string|array|false
    {
        $raw = trim((string) self::env('CORS_ORIGIN', ''));

        if ($raw === '' || $raw === '*') {
            if (self::isProduction()) {
                self::warn(
                    'CORS_ORIGIN ' . ($raw === '' ? '未设置' : '为 *') . '，生产环境不下发 CORS 头（浏览器按同源策略限制）。'
                    . ' 如需跨域访问，请显式配置 CORS_ORIGIN=https://your-domain.com'
                );

                return false;
            }

            return '*';
        }

        $list = array_values(array_filter(array_map('trim', explode(',', $raw)), static fn (string $s): bool => $s !== ''));

        return count($list) === 1 ? $list[0] : $list;
    }

    /**
     * 反向代理信任层数。
     *
     * @return int|false false 表示不信任任何代理（直接用 REMOTE_ADDR）
     */
    public static function trustProxy(): int|false
    {
        $raw = self::env('TRUST_PROXY');

        if ($raw === null || trim($raw) === '') {
            return self::isProduction() ? 1 : false;
        }

        $t = strtolower(trim($raw));
        if (in_array($t, ['0', 'false', 'off'], true)) {
            return false;
        }
        if (in_array($t, ['true', 'on'], true)) {
            return 1;
        }

        $n = (int) $t;

        return $n > 0 ? $n : false;
    }

    /** Bing 数据缓存 TTL（毫秒口径改为秒，1 小时） */
    public static function cacheTtl(): int
    {
        $ttl = (int) (self::env('BING_CACHE_TTL', '3600') ?? '3600');

        return $ttl > 0 ? $ttl : 3600;
    }

    /** 限流窗口（秒） */
    public static function rateLimitWindow(): int
    {
        $v = (int) (self::env('API_RATE_LIMIT_WINDOW', '60') ?? '60');

        return $v > 0 ? $v : 60;
    }

    /** 单窗口内允许的请求数 */
    public static function rateLimitMax(): int
    {
        $v = (int) (self::env('API_RATE_LIMIT_MAX', '200') ?? '200');

        return $v > 0 ? $v : 200;
    }

    /**
     * Bing 首页归档接口。
     *
     * 可被 BING_API_URL 覆盖：一是 Bing 换域名时不用改代码，
     * 二是可以指向一个不可达地址来验证「抓取失败」这条分支（见 DEPLOY.md）。
     */
    public static function bingApiUrl(): string
    {
        return self::env('BING_API_URL', 'https://www.bing.com/HPImageArchive.aspx')
            ?? 'https://www.bing.com/HPImageArchive.aspx';
    }

    public static function bingImageBase(): string
    {
        return 'https://www.bing.com';
    }

    /**
     * 壁纸库数据集里的图片域名。
     *
     * ⚠️ 刻意与 bingImageBase() 分开，两者**不能混用**：
     *   - bingImageBase() 给 /api/bing/today 用，是 www.bing.com，跟 Bing 接口原文一致；
     *   - 这里给壁纸库（历史归档 + 每日写入）用，必须是 cn.bing.com。
     *
     * 为什么必须是 cn.bing.com：app/Archive.php 的 upgradeableTo4k() 只认这个域名
     * （实测 cdn.bimg.cc 与 bing.com 的老图 Bing 侧根本没有 4K 版本，给了地址也是 404），
     * 换成 www.bing.com 会让每一天的新行 url_4k 全变 null、4K 按钮集体失效。
     * 数据集自身的约定也是 cn.bing.com —— 见 bingimages/merge_bing_wallpapers.py
     * 的 BING_URL_PREFIX。
     */
    public static function archiveImageBase(): string
    {
        return rtrim(self::env('ARCHIVE_IMAGE_BASE', 'https://cn.bing.com') ?? '', '/');
    }

    public static function debug(): bool
    {
        $raw = strtolower((string) self::env('APP_DEBUG', ''));

        return in_array($raw, ['1', 'true', 'on', 'yes'], true) || !self::isProduction();
    }

    /** 走统一的日志出口 —— 不能直接用 STDERR，见 Log 类的说明 */
    public static function warn(string $message): void
    {
        Log::warn($message);
    }

    /**
     * 极简 .env 解析：支持 KEY=VALUE、# 注释、可选引号包裹。
     * 刻意不引入 vlucas/phpdotenv —— 这点需求不值得多一个依赖。
     *
     * @return array<string,string>
     */
    private static function parseEnvFile(string $path): array
    {
        if (!is_readable($path)) {
            return [];
        }

        $out = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            if ($line[0] === 'export ') {
                $line = substr($line, 7);
            }

            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }

            $key = trim(substr($line, 0, $pos));
            $value = trim(substr($line, $pos + 1));

            // 去掉成对的引号
            $len = strlen($value);
            if ($len >= 2 && (($value[0] === '"' && $value[$len - 1] === '"') || ($value[0] === "'" && $value[$len - 1] === "'"))) {
                $value = substr($value, 1, -1);
            }

            if ($key !== '') {
                $out[$key] = $value;
            }
        }

        return $out;
    }
}
