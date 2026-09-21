<?php

declare(strict_types=1);

namespace BingWallpaper;

/**
 * 日志出口。
 *
 * 为什么需要这一层封装：**`STDERR` 常量只在 cli SAPI 下存在**。
 * 用 `php tools/serve.php` 启动时，启动器本身跑在 cli SAPI 下，STDERR 可用；
 * 但真正处理 HTTP 请求的是它拉起的 `cli-server` SAPI，那里 STDERR **未定义**，
 * 直接写 `fwrite(STDERR, ...)` 会抛 "Undefined constant STDERR" 并把每个请求打成 500。
 * php-fpm 同理（fastcgi SAPI）。
 *
 * 所以判据是「常量是否存在」，而不是「是否 CLI」：
 *   有 STDERR  → 写标准错误（开发时直接落在终端上，最方便）
 *   没有       → 交给 error_log()，由 SAPI 决定去向
 *                （cli-server 打印到控制台，php-fpm 进 fpm 的 error log）
 *
 * 注意：error_log() 受 php.ini 的 log_errors 开关控制，
 * php.ini.example 里已设为 On。
 */
final class Log
{
    public static function info(string $message): void
    {
        self::write('[Info] ' . $message);
    }

    public static function warn(string $message): void
    {
        self::write('[Warn] ' . $message);
    }

    public static function error(string $message): void
    {
        self::write('[Error] ' . $message);
    }

    private static function write(string $line): void
    {
        if (defined('STDERR')) {
            fwrite(STDERR, $line . PHP_EOL);

            return;
        }

        error_log($line);
    }
}
