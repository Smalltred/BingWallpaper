<?php

declare(strict_types=1);

namespace BingWallpaper;

/**
 * Bing 壁纸代理业务。
 *
 * 对应原 Node 版的 backend/routes/bing.js，行为逐条对齐：
 *   - 1080p / 4K 两个端点共享同一份缓存，只在输出时重写 URL 参数
 *   - 抓取失败时降级返回过期缓存，而不是直接 500
 *   - 单图重定向端点做入参校验
 */
final class Bing
{
    /** 单图重定向的入参约束：不校验的话，这个端点等于对外提供了一个可任意指定尺寸的抓取入口 */
    private const MIN_W = 320;
    private const MAX_W = 3840;
    private const MIN_H = 240;
    private const MAX_H = 2160;
    private const ID_PATTERN = '/^[A-Za-z0-9._-]+$/';

    private const IMAGES_PER_FETCH = 8;

    public function __construct(private readonly Cache $cache)
    {
    }

    /**
     * /api/bing/today 与 /api/bing/today-4k 的共用逻辑。
     *
     * @param string $resolution '1080p' | '4k'
     *
     * @return array{status:int, body:array}
     */
    public function today(string $resolution): array
    {
        $fresh = $this->cache->getFresh();
        if ($fresh !== null) {
            $this->log('/' . ($resolution === '4k' ? 'today-4k' : 'today') . ' 缓存命中（剩余 ' . $this->cache->ttlRemaining() . 's）');

            return ['status' => 200, 'body' => $this->applyResolution($fresh, $resolution)];
        }

        try {
            $payload = $this->fetch();

            // 缓存只存原始 1080p 数据，4K 按需重写 —— 与 Node 版一致
            $this->cache->put($payload);
            $this->log('抓到 ' . count($payload['data']) . ' 条壁纸数据 (uhd=1)');

            return ['status' => 200, 'body' => $this->applyResolution($payload, $resolution)];
        } catch (\Throwable $e) {
            Log::error('获取今日壁纸失败: ' . $e->getMessage());

            $stale = $this->cache->getStale();
            if ($stale !== null) {
                $this->log('降级返回缓存数据');

                return ['status' => 200, 'body' => $this->applyResolution($stale, $resolution)];
            }

            // fetch() 抛出的业务异常带 status（如 Bing 返回格式异常 -> 502）
            $status = $e instanceof BingException ? $e->status : 500;

            return [
                'status' => $status,
                'body' => ['error' => $status === 502 ? 'Bing API 返回数据格式异常' : '获取 Bing 壁纸数据失败'],
            ];
        }
    }

    /**
     * 单图重定向。
     *
     * @return array{status:int, location?:string, error?:string}
     */
    public function imageRedirect(string $resolution, string $id): array
    {
        if (preg_match('/^(\d{2,4})x(\d{2,4})$/', $resolution, $m) !== 1) {
            return ['status' => 400, 'error' => '分辨率格式应为 宽x高，例如 1920x1080'];
        }

        $w = (int) $m[1];
        $h = (int) $m[2];
        if ($w < self::MIN_W || $w > self::MAX_W || $h < self::MIN_H || $h > self::MAX_H) {
            return [
                'status' => 400,
                'error' => sprintf(
                    '分辨率超出允许范围（宽 %d-%d，高 %d-%d）',
                    self::MIN_W,
                    self::MAX_W,
                    self::MIN_H,
                    self::MAX_H
                ),
            ];
        }

        if ($id === '') {
            return ['status' => 400, 'error' => '缺少图片 ID 参数 (id)'];
        }

        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            return ['status' => 400, 'error' => '图片 ID 含非法字符'];
        }

        return [
            'status' => 302,
            'location' => sprintf(
                '%s/th?id=%s&w=%d&h=%d',
                Config::bingImageBase(),
                rawurlencode($id),
                $w,
                $h
            ),
        ];
    }

    /**
     * 抓 Bing 官方接口并映射成对外结构。
     *
     * @return array{message:string, code:int, data:array<int,array<string,string>>}
     *
     * @throws BingException Bing 返回结构异常（502）
     * @throws \RuntimeException 网络层失败（上层转 500）
     */
    private function fetch(): array
    {
        $url = Config::bingApiUrl() . '?' . http_build_query([
            'cc' => 'zh',
            'format' => 'js',
            'idx' => 0,
            'n' => self::IMAGES_PER_FETCH,
            'uhd' => 1,
        ]);

        $response = Http::get($url, 12);

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new \RuntimeException('Bing API 返回 HTTP ' . $response['status']);
        }

        // Bing 实际返回 charset=utf-8；这里仍按 charset 转换，
        // 是为了不让响应头的变化直接把中文变成乱码。
        $body = Http::toUtf8($response['body'], Http::charsetOf($response['headers']));

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new BingException('解析 Bing API 响应失败: ' . $e->getMessage(), 502);
        }

        if (!is_array($decoded) || !isset($decoded['images']) || !is_array($decoded['images'])) {
            throw new BingException('Bing API 返回数据格式异常', 502);
        }

        $data = [];
        foreach ($decoded['images'] as $image) {
            if (!is_array($image) || !isset($image['url'])) {
                continue;
            }

            $data[] = [
                'url' => Config::bingImageBase() . (string) $image['url'],
                'date' => (string) ($image['startdate'] ?? ''),
                'title' => (string) ($image['title'] ?? ''),
                'location' => (string) ($image['copyright'] ?? ''),
            ];
        }

        if ($data === []) {
            throw new BingException('Bing API 返回数据格式异常', 502);
        }

        return ['message' => 'success', 'code' => 200, 'data' => $data];
    }

    /**
     * 把 URL 参数重写为 4K（w=3840&h=2160）。
     *
     * Bing CDN 对同一资源的 ?w=3840&h=2160 会按需切到 4K JPG。
     * 非 Bing URL（如前端降级用的 Unsplash）原样返回。
     */
    public function rewriteTo4k(string $url): string
    {
        if ($url === '') {
            return '';
        }
        if (preg_match('/[?&]w=\d+/', $url) !== 1) {
            return $url;
        }

        $url = preg_replace('/([?&])w=\d+/', '$1w=3840', $url, 1) ?? $url;

        return preg_replace('/([?&])h=\d+/', '$1h=2160', $url, 1) ?? $url;
    }

    /**
     * 按分辨率重写整份数据。缓存里存的是原始 1080p，只有 4K 请求才改写。
     *
     * @param array{message?:string, code?:int, data?:array} $payload
     *
     * @return array{message?:string, code?:int, data?:array}
     */
    public function applyResolution(array $payload, string $resolution): array
    {
        if ($resolution !== '4k' || !isset($payload['data']) || !is_array($payload['data'])) {
            return $payload;
        }

        $rewritten = [];
        foreach ($payload['data'] as $item) {
            if (is_array($item) && isset($item['url']) && is_string($item['url'])) {
                $item['url'] = $this->rewriteTo4k($item['url']);
            }
            $rewritten[] = $item;
        }

        $payload['data'] = $rewritten;

        return $payload;
    }

    private function log(string $message): void
    {
        if (Config::debug()) {
            Log::info($message);
        }
    }
}
