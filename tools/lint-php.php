<?php

declare(strict_types=1);

/**
 * 对 app/、public/ 与 tools/ 下所有 PHP 文件做语法检查（php -l）。
 *
 * 为什么需要它：PHP 的语法错误只在**运行时**才暴露，而这个项目没有测试套件。
 * 一个多余的括号就能让线上某个端点直接 500，而本地开发时如果没有走到那条分支
 * 就完全发现不了。所以「提交前把每个文件都 -l 一遍」是这里最便宜的保险。
 *
 * tools/ 也要扫：update-archive.php 是**跑在生产 cron 里**的，
 * 它一旦有语法错误，表现是「壁纸库悄悄停止更新」——没有任何人会收到报错。
 * 这类静默失败必须挡在提交前，不能靠运行时才发现。
 *
 * 用法：
 *   php tools/lint-php.php        # 或 npm run lint:php
 *
 * 退出码：0 全部通过；1 存在语法错误。
 *
 * ⚠️ 本脚本刻意**不把绝对路径传给 php 子进程**（原因与 tools/serve.php 相同）：
 * Windows 上命令行参数里的非 ASCII 路径会被按 ANSI 代码页重新编码，
 * 项目路径含中文时（H:\...\必应图片展示\...）子进程收到的是乱码，php 直接报
 *   Could not open input file: H:\...\蹇呭簲鍥剧墖灞曠ず\...\app\Config.php
 * 于是「全量检查」静默退化成「一个文件都没检查」，还返回成功。
 * 规避办法：先 chdir 到项目根，只传纯 ASCII 的相对路径。
 */

// 项目根 = tools/ 的上一级。
// ⚠️ 不用 dirname(__DIR__)：与 public/index.php 同一个坑（opcache 常量折叠 +
// 非 ASCII 路径会折叠出错误结果），写法统一以免两处推导出不同的根。
$here = __DIR__;
$projectRoot = realpath($here . '/..');
if ($projectRoot === false) {
    $projectRoot = dirname($here, 1);
}
$scanDirs = [$projectRoot . '/app', $projectRoot . '/public', $projectRoot . '/tools'];

$files = [];
foreach ($scanDirs as $root) {
    if (!is_dir($root)) {
        fwrite(STDERR, "找不到目录: {$root}\n");
        exit(2);
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }
}

sort($files);

if ($files === []) {
    fwrite(STDERR, "app/ 与 public/ 下没有找到 PHP 文件\n");
    exit(2);
}

$failed = [];

// 切到项目根之后只用相对路径，非 ASCII 字符不跨进程边界（见文件头说明）
$previousCwd = getcwd();
if (!chdir($projectRoot)) {
    fwrite(STDERR, "无法切换到项目根目录: {$projectRoot}\n");
    exit(2);
}

foreach ($files as $path) {
    // 统一分隔符再取相对路径。
    // 注意不能用 str_replace($projectRoot . DIRECTORY_SEPARATOR, ...)：迭代器给出的路径是
    // 「根 + /app + \File.php」这种混合分隔符（SplFileInfo 用 DIRECTORY_SEPARATOR 拼接），
    // 带尾分隔符的前缀匹配不上。按长度截断才稳。
    $relative = str_replace('\\', '/', substr($path, strlen($projectRoot) + 1));

    $output = [];
    $exitCode = 0;
    exec(
        sprintf('%s -l %s 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg($relative)),
        $output,
        $exitCode
    );

    if ($exitCode === 0) {
        printf("  OK    %s\n", $relative);
        continue;
    }

    $failed[$relative] = implode("\n", $output);
    printf("  FAIL  %s\n", $relative);
}

$previousCwd !== false && chdir($previousCwd);

printf("\n%d 个文件，%d 个通过，%d 个失败\n", count($files), count($files) - count($failed), count($failed));

if ($failed !== []) {
    echo "\n失败详情：\n";
    foreach ($failed as $file => $message) {
        echo "\n--- {$file} ---\n{$message}\n";
    }

    exit(1);
}

exit(0);
