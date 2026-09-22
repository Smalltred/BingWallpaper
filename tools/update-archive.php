<?php

declare(strict_types=1);

/**
 * 壁纸库每日更新（CLI 入口）。
 *
 * 这个文件现在只是个「薄壳」：参数、日志格式、退出码在这儿，
 * 抓取 / 组装 / 写库 / 加锁 / 标记全部在 BingWallpaper\ArchiveUpdater 里
 * —— 因为「每天第一个访客自动更新」（public/index.php 的收尾钩子）要用同一份逻辑。
 *
 * 用法：
 *   php tools/update-archive.php            # 抓取 → 写库
 *   php tools/update-archive.php --dry-run  # 只看会写什么，不落库、不写标记
 *   php tools/update-archive.php --help
 *
 * 退出码：
 *   0  成功（含「已有实例在跑」与「Bing 还没换图」两种正常情况）
 *   1  失败（网络不通 / 库不可写 / 表结构不对）
 *   2  参数错误
 *
 * 宝塔计划任务里配的命令见 DEPLOY.md「壁纸库每日自动更新」。
 * 它是**兜底**通道：没有访客的日子靠它，同时负责自愈（标记被删、数据集被 merge
 * 重建、上一次失败），所以它每次都会真的跑，不看当日标记。
 *
 * ⚠️ bingimages/merge_bing_wallpapers.py 重建库时会整表 DROP 重建，
 *    会抹掉 daily-api 行 —— 本地重建后要重跑本脚本补回。详见 DEPLOY.md。
 */

// 项目根 = tools/ 的上一级。
// ⚠️ 不用 dirname(__DIR__)：与 public/index.php 同一个坑（opcache 常量折叠 +
// 非 ASCII 路径会折叠出错误结果），写法统一以免几处推导出不同的根。
$here = __DIR__;
$projectRoot = realpath($here . '/..');
if ($projectRoot === false) {
    $projectRoot = dirname($here, 1);
}

// CLI 不走 public/index.php 的自动加载器，这里按依赖顺序手动引入。
require $projectRoot . '/app/Config.php';
require $projectRoot . '/app/Log.php';
require $projectRoot . '/app/Http.php';
require $projectRoot . '/app/Archive.php';
require $projectRoot . '/app/ArchiveUpdater.php';

use BingWallpaper\ArchiveUpdater;
use BingWallpaper\Config;

/**
 * 本脚本是纯 CLI 的，所以自己往 stdout/stderr 写带时间戳的行。
 *
 * 不复用 app/Log.php：那个类存在的理由是「cli-server / php-fpm 下没有 STDERR 常量」，
 * 而 CLI 下这个前提不存在；而且计划任务是每天跑的历史日志，没有时间戳根本没法看。
 * （Log 仍然在用 —— ArchiveUpdater 内部的告警走 Log，会落在 stderr 上。）
 */
function out(string $message): void
{
    fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL);
}

function fail(string $message): void
{
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] [Error] ' . $message . PHP_EOL);
}

function usage(): void
{
    echo <<<'TXT'
    壁纸库每日更新 —— 抓 Bing 最新壁纸写入 bing_wallpapers 表

    用法：
      php tools/update-archive.php [选项]

    选项：
      --dry-run   只抓取并报告会写入哪些行，不修改数据库、不写标记
      --help      显示本帮助

    退出码：0 成功 / 1 失败 / 2 参数错误

    TXT;
}

// ============================================================
//  参数
// ============================================================

$dryRun = false;
foreach (array_slice($argv, 1) as $arg) {
    switch ($arg) {
        case '--dry-run':
            $dryRun = true;
            break;
        case '--help':
        case '-h':
            usage();
            exit(0);
        default:
            fwrite(STDERR, "未知参数：{$arg}\n\n");
            usage();
            exit(2);
    }
}

Config::bootstrap($projectRoot);

out('数据集：' . Config::archiveDbPath());
out('模式：' . ($dryRun ? 'dry-run（不写库）' : '写入'));

// ============================================================
//  跑一次更新（加锁 / 抓取 / 写库 / 标记都在 ArchiveUpdater 里）
// ============================================================

$updater = new ArchiveUpdater();

try {
    $result = $updater->run($dryRun);
} catch (Throwable $e) {
    fail($e->getMessage());
    exit(1);
}

// 锁被别人拿着（多半是首访触发的那次 / 另一次手动执行）不是错误：干的是同一件事
if ($result['locked']) {
    out('已有实例在运行（锁 ' . ArchiveUpdater::lockPath() . '），本次跳过。');
    exit(0);
}

if ($dryRun) {
    out('--- dry-run 明细 ---');
    foreach ($result['rows'] as $row) {
        printf(
            "  %s  %-9s  %s  %s\n",
            $row['date'],
            $row['weekday'],
            $row['status'] === 'new' ? '将新增' : '已存在',
            $row['title'] !== '' ? $row['title'] : '(无标题)'
        );
    }
    out(sprintf(
        '抓到 %d 条 / 将新增 %d 条 / 已存在 %d 条（dry-run，未写库）',
        $result['fetched'],
        $result['inserted'],
        $result['skipped']
    ));
    exit(0);
}

out(sprintf('抓到 %d 条 / 新增 %d 条 / 跳过 %d 条', $result['fetched'], $result['inserted'], $result['skipped']));
out(sprintf(
    '当前库：共 %d 行，日期范围 %s ~ %s',
    $result['total'],
    $result['min_date'],
    $result['max_date']
));

if ($result['pending_today']) {
    // 不是失败：库里该有的都在，只是 Bing 还没切图。已设退避，稍后重试。
    out(sprintf(
        '注意：Bing 尚未切换今天的壁纸（最新一张为 %s），已设 %d 秒退避，稍后重试。',
        $result['latest_date'],
        ArchiveUpdater::FAILURE_BACKOFF_SECONDS
    ));
}

exit(0);
