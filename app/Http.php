<?php

declare(strict_types=1);

namespace BingWallpaper;

/**
 * 出站 HTTP 请求。
 *
 * 优先用 curl 扩展，没有则回退到 stream wrapper —— 很多廉价虚拟主机不装 curl，
 * 但 openssl + stream 基本都有。两条路径都实测过。
 *
 * 关于编码，这里有一个语言差异值得记下：
 *   原 Node 版必须先把响应按 Buffer 收集、Buffer.concat 之后再整体 decode 成 UTF-8，
 *   否则中文（每字 3 字节）被 TCP chunk 边界切开就会变成乱码。
 *   PHP 的字符串本身就是字节序列，不存在「按 chunk 拼接」这个层面，
 *   这个坑在 PHP 里天然不存在 —— 只需要处理 charset 转换。
 */
final class Http
{
    private const USER_AGENT = 'BingWallpaper/1.0 (+https://bing.hecady.com)';

    /**
     * GET 一个 URL。
     *
     * @return array{status:int, headers:array<string,string>, body:string}
     *
     * @throws \RuntimeException 网络层失败（连接不上 / 超时）
     */
    public static function get(string $url, int $timeout = 10): array
    {
        return self::hasCurl() ? self::getViaCurl($url, $timeout) : self::getViaStream($url, $timeout);
    }

    public static function hasCurl(): bool
    {
        return function_exists('curl_init');
    }

    /** 驱动名，用于启动日志里说明实际走的是哪条路径 */
    public static function driver(): string
    {
        return self::hasCurl() ? 'curl' : 'stream';
    }

    /**
     * 从 Content-Type 里取 charset，默认 utf-8。
     *
     * @param array<string,string> $headers 头名一律小写
     */
    public static function charsetOf(array $headers): string
    {
        $ct = strtolower($headers['content-type'] ?? '');
        if (preg_match('/charset\s*=\s*"?([^";]+)"?/', $ct, $m) === 1) {
            return trim($m[1]);
        }

        return 'utf-8';
    }

    /**
     * 把响应体转成 UTF-8。json_decode 只认 UTF-8，
     * 所以这一步必须在解码之前做。
     */
    public static function toUtf8(string $body, string $charset): string
    {
        $charset = strtolower(trim($charset));
        if ($charset === '' || $charset === 'utf-8' || $charset === 'utf8') {
            return $body;
        }

        if (function_exists('mb_convert_encoding')) {
            return mb_convert_encoding($body, 'UTF-8', $charset);
        }

        if (function_exists('iconv')) {
            $converted = @iconv($charset, 'UTF-8//IGNORE', $body);
            if ($converted !== false) {
                return $converted;
            }
        }

        // 转换能力都不可用时原样返回：让 json_decode 显式失败，
        // 好过悄悄返回一份乱码数据。
        return $body;
    }

    /**
     * @return array{status:int, headers:array<string,string>, body:string}
     */
    private static function getViaCurl(string $url, int $timeout): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('curl_init 失败: ' . $url);
        }

        $headers = [];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
            CURLOPT_ENCODING => '', // 自动协商并解压 gzip
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_HTTPHEADER => ['Accept: application/json, text/plain, */*'],
            CURLOPT_HEADERFUNCTION => static function ($_ch, string $line) use (&$headers): int {
                $pos = strpos($line, ':');
                if ($pos !== false) {
                    $name = strtolower(trim(substr($line, 0, $pos)));
                    $headers[$name] = trim(substr($line, $pos + 1));
                }

                return strlen($line);
            },
        ]);

        $body = curl_exec($ch);
        if ($body === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException('请求失败: ' . $err);
        }

        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return ['status' => $status, 'headers' => $headers, 'body' => (string) $body];
    }

    /**
     * @return array{status:int, headers:array<string,string>, body:string}
     */
    private static function getViaStream(string $url, int $timeout): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $timeout,
                'ignore_errors' => true, // 非 2xx 也要拿到 body 和状态码
                'follow_location' => 1,
                'max_redirects' => 4,
                'header' => "User-Agent: " . self::USER_AGENT . "\r\n"
                    . "Accept: application/json, text/plain, */*\r\n"
                    . "Accept-Encoding: identity\r\n",
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);

        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            throw new \RuntimeException('请求失败（stream 通道）: ' . $url);
        }

        $status = 0;
        $headers = [];
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
                // 跟随重定向时会收到多段响应头，以最后一段为准
                $status = (int) $m[1];
                $headers = [];
                continue;
            }
            $pos = strpos($line, ':');
            if ($pos !== false) {
                $headers[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
            }
        }

        return ['status' => $status, 'headers' => $headers, 'body' => $body];
    }
}
