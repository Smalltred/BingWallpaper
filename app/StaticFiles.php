<?php

declare(strict_types=1);

namespace BingWallpaper;

/**
 * 静态文件服务（SPA 构建产物 + 自托管字体 + 图标）。
 *
 * 让 PHP 自己发静态文件，是为了让这套后端**单独就能跑起来**——
 * 不依赖「必须配好 nginx 才能正常显示页面」。生产环境仍建议交给 nginx
 * （nginx.conf.example 里就是这么配的），PHP 这条路径是保底 + 开发便利。
 *
 * 安全上最要紧的是防目录穿越。这里的做法是「先粗筛、再用 realpath 兜底」：
 * 只靠字符串替换（strip '..'）是不够的，因为 URL 编码、符号链接都能绕过。
 */
final class StaticFiles
{
    /** 扩展名 → MIME。覆盖本站实际会发的类型即可，其余交给 fileinfo */
    private const MIME = [
        'html' => 'text/html; charset=utf-8',
        'css' => 'text/css; charset=utf-8',
        'js' => 'text/javascript; charset=utf-8',
        'mjs' => 'text/javascript; charset=utf-8',
        'json' => 'application/json; charset=utf-8',
        'map' => 'application/json; charset=utf-8',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
        'avif' => 'image/avif',
        'ico' => 'image/x-icon',
        'woff2' => 'font/woff2',
        'woff' => 'font/woff',
        'ttf' => 'font/ttf',
        'txt' => 'text/plain; charset=utf-8',
        'xml' => 'application/xml; charset=utf-8',
    ];

    /**
     * @param list<string> $roots 按优先级排列的静态根目录
     */
    public function __construct(private readonly array $roots)
    {
    }

    /**
     * 命中并发送文件返回 true；未命中返回 false（交由上层做 SPA 回退）。
     */
    public function trySend(string $path): bool
    {
        $relative = $this->resolve($path);
        if ($relative === null) {
            return false;
        }

        $this->send($relative);

        return true;
    }

    /**
     * 把 URL 路径解析成一个「确实位于某个静态根目录内」的真实文件路径。
     */
    private function resolve(string $path): ?string
    {
        if ($path === '/' || str_contains($path, "\0")) {
            return null;
        }

        // URL 里是百分号编码，要还原成真实文件名（如中文文件名、空格）
        $decoded = rawurldecode($path);
        if (str_contains($decoded, "\0")) {
            return null;
        }

        // 绝不把 PHP 文件当静态内容读出去。
        // Web 根（public/）里就住着入口 index.php —— 这条是防止
        // 「请求恰好被路由到这里」时把源码原样吐给客户端。
        // 正常部署下 nginx/Apache 会把 .php 交给 PHP 执行、轮不到这里，
        // 但内置服务器（php -S）和某些反代配置会。宁可多一层。
        if (preg_match('/\.(php|phtml|phar|php[0-9])$/i', $decoded) === 1) {
            return null;
        }

        // 粗筛：含 .. 直接拒。真正确认靠下面的 realpath 包含判断。
        if (str_contains($decoded, '..')) {
            return null;
        }

        $relative = ltrim(str_replace('\\', '/', $decoded), '/');

        foreach ($this->roots as $root) {
            $rootReal = realpath($root);
            if ($rootReal === false) {
                continue;
            }

            $candidate = $rootReal . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $real = realpath($candidate);

            if ($real === false || !is_file($real)) {
                continue;
            }

            // 兜底：真实路径仍必须在根目录之内。
            // 这一步能挡住编码绕过和指向外部的符号链接。
            if (!str_starts_with($real, $rootReal . DIRECTORY_SEPARATOR)) {
                continue;
            }

            return $real;
        }

        return null;
    }

    private function send(string $file): void
    {
        $size = (int) filesize($file);
        $mtime = (int) filemtime($file);
        $etag = 'W/"' . dechex($size) . '-' . dechex($mtime) . '"';

        header('Content-Type: ' . $this->mimeOf($file));
        header('Cache-Control: ' . $this->cacheControl($file));
        header('ETag: ' . $etag);
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
        header('Accept-Ranges: none');

        // 条件请求：命中就让浏览器用本地缓存，省一整轮传输
        $ifNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
        if (is_string($ifNoneMatch) && trim($ifNoneMatch) === $etag) {
            http_response_code(304);
            header('Content-Length: 0');
            exit;
        }

        http_response_code(200);
        header('Content-Length: ' . $size);

        // 先把已缓冲的内容刷出去，再直接流式读文件，
        // 避免把整个文件先读进内存缓冲
        if (ob_get_level() > 0) {
            ob_end_flush();
        }

        readfile($file);
        exit;
    }

    private function mimeOf(string $file): string
    {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (isset(self::MIME[$ext])) {
            return self::MIME[$ext];
        }

        if (function_exists('mime_content_type')) {
            $detected = @mime_content_type($file);
            if (is_string($detected) && $detected !== '') {
                return $detected;
            }
        }

        // 兜底不让浏览器做 MIME 嗅探
        return 'application/octet-stream';
    }

    /**
     * 缓存策略按「文件名是否带内容指纹」区分：
     *   - dist/assets/*  由 Vite 生成，文件名内含 hash，内容变了文件名就变
     *     → 可以放心 immutable 长期缓存
     *   - fonts/         本项目字体按字族命名（DMSans.woff2），没有指纹
     *     → 只给 30 天，不能 immutable，否则换字体会被老缓存挡住
     *   - 其余（icon / og-image）指纹也没有，给 1 天
     */
    private function cacheControl(string $file): string
    {
        $normalized = str_replace('\\', '/', $file);

        if (preg_match('#/dist/assets/#', $normalized) === 1) {
            return 'public, max-age=31536000, immutable';
        }

        if (preg_match('#/public/fonts/|/dist/fonts/#', $normalized) === 1) {
            return 'public, max-age=2592000';
        }

        return 'public, max-age=86400';
    }
}
