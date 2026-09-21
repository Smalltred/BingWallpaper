<?php

declare(strict_types=1);

namespace BingWallpaper;

/**
 * 带 HTTP 状态码的领域异常。
 *
 * 存在的意义是把「Bing 返回了但结构不对」（502）与
 * 「根本连不上 Bing」（500，网络层 RuntimeException）区分开，
 * 这两种情况对调用方的含义完全不同。
 */
final class BingException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $status = 502)
    {
        parent::__construct($message, $status);
    }
}
