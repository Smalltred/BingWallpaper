<?php

declare(strict_types=1);

/**
 * PHP 内置服务器启动器。
 *
 * 用法：
 *   php tools/serve.php
 *
 * 为什么单独写一个启动脚本而不是在 README 里写一长串 php -S 命令：
 *   1. 端口 / 监听地址来自 .env，和线上 php-fpm 用的是同一份配置，不分叉；
 *   2. 启动前能做环境自检（扩展缺失、php.ini 没加载），当场把问题说清楚；
 *   3. 少一次「参数抄错」的机会。
 *
 * 生产环境请用 nginx + php-fpm（见 nginx.conf.example），
 * 内置服务器是单进程的，只适合开发和小流量自用。
 */

use BingWallpaper\Config;
use BingWallpaper\Http;
use BingWallpaper\Log;

// 启动器不经过前端控制器的自动加载器，这里手动引入它用到的三个类。
// 本文件在 tools/ 下，应用代码在 app/ 下 —— 项目根 = tools/ 的上一级。
//
// ⚠️ 写法刻意与 public/index.php 保持一致，不用 dirname(__DIR__)：理由见那边的注释
// （opcache 常量折叠 + 非 ASCII 路径会折叠出错误结果）。
// 本文件目前只跑在 CLI 下（opcache.enable_cli 默认 Off）所以暂时不会犯，
// 但两处必须推导出同一个值 —— 否则启动器自检打印的「数据目录」
// 和真正跑起来的站点会指向不同位置，排查时极难发现。
$here = __DIR__;
$projectRoot = realpath($here . '/..');
if ($projectRoot === false) {
    $projectRoot = dirname($here, 1);
}

require $projectRoot . '/app/Log.php';
require $projectRoot . '/app/Config.php';
require $projectRoot . '/app/Http.php';

Config::bootstrap($projectRoot);

/**
 * 把「来自 Windows ANSI 代码页的路径」转成 UTF-8。
 *
 * 为什么需要：Windows 上 `php_ini_loaded_file()` 返回的是**本地代码页（简体中文机器上是
 * GBK）**的字节，而本文件里的中文提示是 **UTF-8** 字面量。两者被 echo 到同一段输出里，
 * 必然是「一边正常、另一边乱码」，取决于终端的编码设置 —— 实测终端按 UTF-8 显示时，
 * 整段自检信息里唯独 php.ini 那一行是乱码，反过来按 GBK 显示时只有那一行是对的。
 * 这是纯粹的输出问题，不影响服务运行，但自检信息本来就是给人看的，乱码等于白写。
 *
 * 非 Windows（路径本来就是 UTF-8）或已经是合法 UTF-8 时原样返回，所以对 Linux 零影响。
 */
function toUtf8(string $value): string
{
    // preg_match 的 /u 修饰符会校验整个 subject 是否是合法 UTF-8，
    // 非法时返回 false —— 这正是我们要的判断依据。
    if (preg_match('//u', $value) === 1) {
        return $value;
    }

    if (PHP_OS_FAMILY === 'Windows' && function_exists('sapi_windows_cp_get')) {
        $encoding = 'CP' . sapi_windows_cp_get('ansi');
        $converted = @mb_convert_encoding($value, 'UTF-8', $encoding);
        if (is_string($converted) && $converted !== '' && preg_match('//u', $converted) === 1) {
            return $converted;
        }
    }

    // 转不动就退化成「把非法字节丢掉」，至少不往终端里灌坏字节
    return (string) @mb_convert_encoding($value, 'UTF-8', 'UTF-8');
}

$host = Config::host();
$port = Config::port();
$projectRoot = Config::projectRoot();

$hasBuild = is_file($projectRoot . '/public/index.html');
$iniLoaded = php_ini_loaded_file();
$iniDisplay = $iniLoaded !== false ? toUtf8($iniLoaded) : '未加载（扩展可能不可用！）';

echo "\n========================================\n";
echo "  BingWallpaper 服务已启动（PHP " . PHP_VERSION . "）\n";
printf("  地址: http://%s:%d\n", $host === '0.0.0.0' ? 'localhost' : $host, $port);
echo '  前端: ' . ($hasBuild ? 'Vue SPA (public/)' : '未构建（先 npm run build）') . "\n";
echo "  出站 HTTP: " . Http::driver() . "\n";
echo '  CORS: ' . json_encode(Config::corsOrigin()) . ' · trust proxy: ' . json_encode(Config::trustProxy()) . "\n";
echo '  文档: /docs' . "  ·  接口: /api/bing/today\n";
echo '  数据目录: ' . Config::dataDir() . "\n";
echo '  php.ini: ' . $iniDisplay . "\n";
echo "========================================\n\n";

// 启动前把最容易踩的三个环境问题当场说清楚，而不是等运行时报错
if ($iniLoaded === false) {
    Log::warn('没有加载 php.ini，curl / openssl 等扩展将不可用。');
    Log::warn('可执行 `php --ini` 查看它会去哪里找配置文件。');
}
if (!Http::hasCurl()) {
    Log::warn('未启用 curl 扩展，出站请求将走 stream 通道（功能等价，稍慢）。');
}
if ($host === '0.0.0.0') {
    Log::warn('正在监听 0.0.0.0，服务对本机所在网络可见。');
}

// ---- 启动内置服务器 ----
//
// Windows 上的坑：**命令行参数里的非 ASCII 路径会被编码坏**。
// 一旦项目路径含中文（例如 C:\Users\何凯迪\...），把绝对路径传下去，
// 子进程收到的是「UTF-8 字节被当成 ANSI 代码页解释」的乱码，直接报：
//   Directory C:\Users\浣曞嚡杩猏AppData\...\public does not exist.
// 路径是纯 ASCII 时完全正常，所以这个坑只在部分机器上暴露。
// （用 proc_open 的参数数组绕过 shell 也**不足以**解决 —— 问题不在 shell，在参数传递本身。）
//
// 规避办法：先 chdir 到项目根，只传**纯 ASCII 的相对路径**。
// chdir 走 PHP 自己的文件系统 API，对中文路径没问题；
// 这样跨进程边界就没有一个非 ASCII 字符了。
$docroot = 'public';
$router = 'public/index.php';

$previousCwd = getcwd();
if (!chdir($projectRoot)) {
    Log::error('无法切换到项目根目录: ' . $projectRoot);
    exit(1);
}

$command = [
    PHP_BINARY,
    '-S',
    $host . ':' . $port,
    '-t',
    $docroot,
    $router,
];

$descriptors = [
    0 => STDIN,
    1 => STDOUT,
    2 => STDERR,
];

$process = proc_open($command, $descriptors, $pipes);
if (!is_resource($process)) {
    Log::error('无法启动内置服务器（proc_open 失败）');
    $previousCwd !== false && chdir($previousCwd);
    exit(1);
}

// 透传子进程退出码：Ctrl+C 也能正确终止
exit(proc_close($process));
