<?php

declare(strict_types=1);

namespace BingWallpaper;

/**
 * 文件缓存（带 TTL）。
 *
 * 为什么不用内存变量：原 Node 版把缓存放在模块级变量里，进程常驻所以能跨请求复用。
 * PHP 每个请求都是独立生命周期（内置服务器 / php-fpm 都一样），请求结束变量就没了。
 * 所以这里落到文件。可选方案里：
 *   - APCu：快，但 Windows 上通常没编译，且 FPM 多进程池需要共享内存段，部署要求高
 *   - Redis/Memcached：为了 8 条 JSON 引入一个外部服务，不成比例
 *   - 文件：零依赖，任何环境都能跑，1 小时才写一次、读的是几 KB 的小文件，成本可忽略
 *
 * 写入用「临时文件 + rename」。rename 在同一文件系统上是原子的，
 * 读方要么看到旧版本要么看到新版本，不会读到写了一半的残缺 JSON；
 * 比 flock 更适合这种「一写多读」的场景。
 */
final class Cache
{
    private string $file;

    public function __construct(
        private readonly string $dir,
        private readonly string $key,
        private readonly int $ttl,
    ) {
        $this->file = rtrim($dir, "/\\") . '/' . $key . '.json';
    }

    /** 命中且未过期时返回 payload，否则 null */
    public function getFresh(): ?array
    {
        $entry = $this->read();
        if ($entry === null) {
            return null;
        }

        return $entry['expires_at'] > time() ? $entry['payload'] : null;
    }

    /**
     * 不管是否过期都返回 payload。
     * 用于「抓 Bing 失败时降级返回旧数据」——这份数据虽过期，但比 500 强。
     */
    public function getStale(): ?array
    {
        $entry = $this->read();

        return $entry['payload'] ?? null;
    }

    public function put(array $payload): bool
    {
        $entry = [
            'expires_at' => time() + $this->ttl,
            'cached_at' => date(DATE_ATOM),
            'payload' => $payload,
        ];

        $json = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return false;
        }

        $tmp = $this->file . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
            return false;
        }

        // Windows 上 rename 覆盖已存在文件会失败，需先尝试直接替换
        if (!@rename($tmp, $this->file)) {
            @unlink($this->file);
            if (!@rename($tmp, $this->file)) {
                @unlink($tmp);

                return false;
            }
        }

        return true;
    }

    /** 距离过期还有多少秒（已过期或不存在返回 0） */
    public function ttlRemaining(): int
    {
        $entry = $this->read();
        if ($entry === null) {
            return 0;
        }

        return max(0, $entry['expires_at'] - time());
    }

    /**
     * @return array{expires_at:int, payload:array}|null
     */
    private function read(): ?array
    {
        if (!is_readable($this->file)) {
            return null;
        }

        $raw = @file_get_contents($this->file);
        if ($raw === false || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        // 缓存文件损坏（写到一半断电、手工改坏）不应让整个接口挂掉，
        // 当作未命中，下次请求会重新抓取并覆盖。
        if (!is_array($decoded) || !isset($decoded['expires_at'], $decoded['payload']) || !is_array($decoded['payload'])) {
            return null;
        }

        return [
            'expires_at' => (int) $decoded['expires_at'],
            'payload' => $decoded['payload'],
        ];
    }
}
