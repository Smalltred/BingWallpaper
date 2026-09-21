<?php

declare(strict_types=1);

namespace BingWallpaper;

/**
 * 响应输出 + 全站响应头。
 *
 * 对应原 Node 版里 cors / helmet 那部分中间件的行为，
 * 但改用原生 header() 实现，不引入任何依赖。
 */
final class Response
{
    /**
     * 允许直连的图片图床。
     *
     * 这些域名是实测出来的，不是猜的 —— Bing 壁纸的图床随年代换过三次：
     *   www.bing.com / cn.bing.com  当前与近期
     *   cdn.bimg.cc                 2016–2018 的历史图（920 条）
     *   bing.com                    2018-09 ~ 2019-05 那一批（241 条）
     *   images.unsplash.com         前端降级示例图
     *
     * 漏掉任何一个，对应那批图都会被浏览器按 CSP 直接拦掉 ——
     * 而 curl 不执行 CSP，所以只有真浏览器验证才能发现。
     */
    private const IMAGE_HOSTS = [
        'https://www.bing.com',
        'https://cn.bing.com',
        'https://cdn.bimg.cc',
        'https://bing.com',
        'https://images.unsplash.com',
    ];

    /**
     * 允许 JS fetch 的地址。
     * 壁纸库的「下载原图」是用 fetch 取图片再转 blob 的，
     * 那条请求走 connect-src 而不是 img-src，必须单独放行。
     */
    private const FETCH_HOSTS = [
        'https://www.bing.com',
        'https://cn.bing.com',
        'https://cdn.bimg.cc',
        'https://bing.com',
    ];

    /**
     * 基础安全响应头。逐条都有明确理由，不是照抄一份清单：
     *   - nosniff：防止浏览器把 JSON 当 HTML 解析（老 IE 的 MIME 嗅探问题）
     *   - X-Frame-Options / frame-ancestors：禁止被 iframe 嵌套，防点击劫持
     *   - Referrer-Policy：跨站时只发 origin，不泄露完整路径
     *   - Permissions-Policy：这个站点用不到任何敏感设备 API，直接全禁
     *   - CSP：默认同源；图片放行上面那几个图床；样式允许内联（docs 页与 SPA 都有内联样式）
     */
    public static function securityHeaders(): void
    {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
        header(
            'Content-Security-Policy: '
            . "default-src 'self'; "
            . "img-src 'self' data: " . implode(' ', self::IMAGE_HOSTS) . '; '
            . "style-src 'self' 'unsafe-inline'; "
            . "script-src 'self'; "
            . "font-src 'self' data:; "
            . "connect-src 'self' " . implode(' ', self::FETCH_HOSTS) . '; '
            . "frame-ancestors 'self'; "
            . "base-uri 'self'; "
            . "form-action 'self'"
        );
    }

    /**
     * 按配置下发 CORS 头。
     *
     * 关键点：生产环境未配置 CORS_ORIGIN 时 config 返回 false，
     * 这里就一个字都不发 —— 浏览器按同源策略限制。
     * 不要用「反射请求方 Origin」来代替这个判断，那等于放行全网。
     */
    public static function cors(): void
    {
        $allowed = Config::corsOrigin();

        if ($allowed === false) {
            return;
        }

        if ($allowed === '*') {
            header('Access-Control-Allow-Origin: *');

            return;
        }

        $requestOrigin = Request::origin();

        if (is_array($allowed)) {
            // 白名单：命中才回显，并声明 Vary 以免被中间缓存串味
            header('Vary: Origin');
            if ($requestOrigin !== '' && in_array($requestOrigin, $allowed, true)) {
                header('Access-Control-Allow-Origin: ' . $requestOrigin);
            }

            return;
        }

        header('Access-Control-Allow-Origin: ' . $allowed);
    }

    /** 预检请求（本站全是 GET + 简单头，正常不会触发，但别让客户端卡住） */
    public static function preflight(): never
    {
        header('Access-Control-Allow-Methods: GET, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        header('Access-Control-Max-Age: 86400');
        http_response_code(204);
        exit;
    }

    public static function json(array $data, int $status = 200): never
    {
        // 与 Node 的 res.json 对齐：不转义 Unicode（中文原样输出）、不转义斜杠
        $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($body === false) {
            $body = '{"error":"响应序列化失败"}';
            $status = 500;
        }

        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Length: ' . strlen($body));
        echo $body;
        exit;
    }

    public static function text(string $body, string $contentType, int $status = 200, array $extraHeaders = []): never
    {
        http_response_code($status);
        header('Content-Type: ' . $contentType);
        header('Content-Length: ' . strlen($body));
        foreach ($extraHeaders as $name => $value) {
            header($name . ': ' . $value);
        }
        echo $body;
        exit;
    }

    public static function html(string $body, int $status = 200): never
    {
        self::text($body, 'text/html; charset=utf-8', $status);
    }

    public static function redirect(string $url, int $status = 302): never
    {
        http_response_code($status);
        header('Location: ' . $url);
        // 重定向响应不留 body，Content-Length: 0 让客户端明确知道到此为止
        header('Content-Length: 0');
        exit;
    }
}
