<?php

declare(strict_types=1);

namespace BingWallpaper;

/**
 * 请求读取。
 *
 * 这里最需要小心的是「谁才是真实客户端 IP」——它直接决定限流是否可被绕过。
 */
final class Request
{
    public static function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    /**
     * 原始请求路径（不含查询串，**不做 urldecode**）。
     * 刻意不解码：把 %2F 解成 '/' 会凭空造出新的路径层级，是典型的绕过手法。
     * 需要解码时按段解码。
     */
    public static function path(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $pos = strpos($uri, '?');
        if ($pos !== false) {
            $uri = substr($uri, 0, $pos);
        }

        // 折叠重复斜杠，避免 //api/bing/today 之类走到歧义分支
        $uri = preg_replace('#/{2,}#', '/', $uri) ?? $uri;

        return $uri === '' ? '/' : $uri;
    }

    public static function query(string $key, ?string $default = null): ?string
    {
        $value = $_GET[$key] ?? null;

        return is_string($value) ? $value : $default;
    }

    /**
     * 解析真实客户端 IP。
     *
     * 规则与 Express 的 trust proxy 一致：从最靠近应用的一跳开始往外数，
     * 跳过 N 个受信代理，第一个非受信地址就是客户端。
     * 链路 = [REMOTE_ADDR, X-Forwarded-For 逆序...]，取第 N 个。
     *
     * 安全性取决于反代怎么设置 XFF：
     *   - nginx 用 proxy_set_header X-Forwarded-For $remote_addr;（**覆盖**）
     *     → 最右一段就是真实客户端，客户端无法伪造
     *   - nginx 用默认的 $proxy_add_x_forwarded_for（**追加**）
     *     → 客户端可以自带一个假 XFF 混进来，此时应改用 X-Real-IP 或改覆盖写法
     * nginx.conf.example 采用的是覆盖写法。
     */
    public static function clientIp(int|false $trustProxy): string
    {
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

        if ($trustProxy === false || $trustProxy < 1) {
            return $remote;
        }

        $xff = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($xff === '') {
            return $remote;
        }

        $chain = [$remote];
        foreach (array_reverse(explode(',', $xff)) as $hop) {
            $hop = trim($hop);
            if ($hop !== '') {
                $chain[] = $hop;
            }
        }

        $candidate = $chain[$trustProxy] ?? end($chain);

        // 取出来的地址必须是合法 IP 才采信；否则退回 REMOTE_ADDR，
        // 把垃圾输入收敛到同一个限流桶，而不是让每个假值各开一桶。
        return is_string($candidate) && filter_var($candidate, FILTER_VALIDATE_IP) !== false
            ? $candidate
            : $remote;
    }

    /** 请求方 Origin（用于 CORS 判定） */
    public static function origin(): string
    {
        return (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    }
}
