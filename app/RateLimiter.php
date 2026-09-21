<?php

declare(strict_types=1);

namespace BingWallpaper;

/**
 * 按 key（通常是被限流方的 IP）的固定窗口限流。
 *
 * 对应原 Node 版的 express-rate-limit（windowMs=60s, max=200, standardHeaders）。
 * PHP 没有常驻进程，所以计数落文件；用 flock 做读-改-写，保证并发下不漏计。
 *
 * 设计取向：**失败开放**。拿不到锁、写不了文件时一律放行。
 * 这是一个公开只读 API，限流是防滥用而不是业务规则，
 * 为了限流把整个站点拖垮是本末倒置。
 */
final class RateLimiter
{
    public function __construct(
        private readonly string $dir,
        private readonly int $window,
        private readonly int $max,
    ) {
    }

    /**
     * @return array{allowed:bool, limit:int, window:int, remaining:int, reset:int}
     *         reset 是距离窗口重置的秒数
     */
    public function hit(string $key): array
    {
        $now = time();
        $windowStart = intdiv($now, $this->window) * $this->window;
        $resetIn = max(0, $windowStart + $this->window - $now);

        // 拿不到文件句柄就放行（失败开放，见类注释）
        $failOpen = [
            'allowed' => true,
            'limit' => $this->max,
            'window' => $this->window,
            'remaining' => $this->max,
            'reset' => $resetIn,
        ];

        $this->maybeGc($now);

        $file = $this->fileFor($key);
        $fh = @fopen($file, 'c+');
        if ($fh === false) {
            return $failOpen;
        }

        try {
            if (!flock($fh, LOCK_EX)) {
                return $failOpen;
            }

            $raw = stream_get_contents($fh);
            $state = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
            if (!is_array($state) || ($state['window_start'] ?? null) !== $windowStart) {
                $state = ['window_start' => $windowStart, 'count' => 0];
            }

            $state['count'] = (int) ($state['count'] ?? 0) + 1;

            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, (string) json_encode($state, JSON_UNESCAPED_UNICODE));
            fflush($fh);
            flock($fh, LOCK_UN);

            $count = $state['count'];

            return [
                'allowed' => $count <= $this->max,
                'limit' => $this->max,
                'window' => $this->window,
                'remaining' => max(0, $this->max - $count),
                'reset' => $resetIn,
            ];
        } finally {
            fclose($fh);
        }
    }

    /**
     * 修正响应头里的限流信息。
     * 头名与 express-rate-limit 的 standardHeaders 输出保持一致
     * （RateLimit-Policy / Limit / Remaining / Reset），
     * 这样从 Node 版切过来时，依赖这些头的消费方（含监控）不用改。
     */
    public static function applyHeaders(array $result): void
    {
        header(sprintf('RateLimit-Policy: %d;w=%d', $result['limit'], $result['window']));
        header('RateLimit-Limit: ' . $result['limit']);
        header('RateLimit-Remaining: ' . $result['remaining']);
        header('RateLimit-Reset: ' . $result['reset']);
    }

    private function fileFor(string $key): string
    {
        // 用哈希做文件名：IP 可能含 ':'（IPv6）等不适合直接做文件名的字符
        return rtrim($this->dir, "/\\") . '/rl_' . sha1($key) . '.json';
    }

    /**
     * 概率性清理过期计数文件。
     * 不用定时任务：每个 IP 一个文件，如果只在窗口切换时清，
     * 长期运行会攒下大量只被访问过一次的文件。
     */
    private function maybeGc(int $now): void
    {
        if (random_int(1, 100) !== 1) {
            return;
        }

        $threshold = $now - ($this->window * 2);
        foreach ((array) glob(rtrim($this->dir, "/\\") . '/rl_*.json') as $path) {
            if (is_string($path) && @filemtime($path) < $threshold) {
                @unlink($path);
            }
        }
    }
}
