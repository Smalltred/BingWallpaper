<?php

declare(strict_types=1);

/**
 * 壁纸库每日更新：抓 Bing 最新壁纸（1080p）写进 bing_wallpapers 表。
 *
 * 为什么要有它：数据集 bingimages/bing_wallpapers.db 是 bingimages 项目用 merge 脚本
 * 离线产出的，最后一天停在产出那一刻。之后每天的新壁纸没人补 —— 壁纸库页就会
 * 「越放越旧」。这个脚本用宝塔计划任务每天跑一次，把缺口补上。
 *
 * 用法：
 *   php tools/update-archive.php            # 抓取 → 写库
 *   php tools/update-archive.php --dry-run  # 只看会写什么，不落库
 *   php tools/update-archive.php --help
 *
 * 退出码：
 *   0  成功（含「已有实例在跑」与「没有新数据」两种正常情况）
 *   1  失败（网络不通 / 库不可写 / 表结构不对）
 *   2  参数错误
 *
 * ─────────────────────────────────────────────────────────────
 *  几个必须说清的口径（都是实测确认过的，不是推测）
 * ─────────────────────────────────────────────────────────────
 *
 * 1) 日期用**北京时间**，不是接口的 startdate。
 *
 *    接口每条给的 startdate=20260920、fullstartdate=202609201600（UTC）。
 *    后者换算到 Asia/Shanghai 是 2026-09-21 00:00 —— Bing 的壁纸是在北京时间
 *    零点切换的。数据集 3850 行遵循的正是这个口径（实测：DB 的 date 恒等于
 *    startdate + 1 天）。若直接用 startdate，新写入的行会比历史行整体早一天，
 *    并且「今天的壁纸」永远查不到，壁纸库首页第一张也不会是今天。
 *
 * 2) url 的域名必须是 cn.bing.com（见 Config::archiveImageBase() 的说明）。
 *    用 Config::bingImageBase()（www.bing.com）会让 url_4k 全变 null。
 *
 * 3) region 用 'zh-cn'（不是提示词里写的 'cn'）：既有 3850 行的取值就是 'zh-cn'，
 *    前端把 region 直接当标签展示，写 'cn' 会出现两种写法混排。
 *
 * 4) title_en / copyright_en 留空。实测接口**无论传什么市场参数都只返回中文**
 *    （mkt=en-US、cc=us、cc=jp、setlang=en 全试过，返回的仍是中文标题），
 *    所以英文标题在这个数据源上取不到。前端 displayTitle()/displayCopyright()
 *    本来就对这两个字段做了回退，留空不影响展示。
 *
 * 5) 只 INSERT、不 UPDATE/DELETE。date 是主键，INSERT OR IGNORE 天然幂等，
 *    重复跑不会覆盖数据集里已有的（可能更完整的）行。
 *
 * ⚠️ bingimages/merge_bing_wallpapers.py 重建库时会整表 DROP 重建，
 *    会抹掉这里写进去的 daily-api 行 —— 本地重建后要重跑本脚本补回。
 *    详见 DEPLOY.md「壁纸库每日自动更新」。
 */

// 项目根 = tools/ 的上一级。
// ⚠️ 不用 dirname(__DIR__)：与 public/index.php 同一个坑（opcache 常量折叠 +
// 非 ASCII 路径会折叠出错误结果），写法统一以免两处推导出不同的根。
$here = __DIR__;
$projectRoot = realpath($here . '/..');
if ($projectRoot === false) {
    $projectRoot = dirname($here, 1);
}

require $projectRoot . '/app/Config.php';
require $projectRoot . '/app/Log.php';
require $projectRoot . '/app/Http.php';
require $projectRoot . '/app/Archive.php';

use BingWallpaper\Archive;
use BingWallpaper\Config;
use BingWallpaper\Http;

/** 表结构（15 列）—— 用来在写之前确认「打开的是不是那张表」 */
const EXPECTED_COLUMNS = [
    'date', 'date_compact', 'year', 'month', 'day', 'weekday',
    'title', 'title_en', 'copyright', 'copyright_en', 'copyrightlink',
    'url', 'urlbase', 'region', 'sources',
];

/** 数据来源标记，写进 sources 列 —— 用来区分「merge 产出」与「每日抓取」 */
const SOURCE_TAG = 'daily-api';

/** 数据集里的地区标记，与既有 3850 行一致（见文件头第 3 条） */
const REGION_TAG = 'zh-cn';

/** Bing 的 n 参数上限就是 8，多传也只返回 8 条 */
const FETCH_COUNT = 8;

/**
 * 本脚本是纯 CLI 的，所以自己往 stdout/stderr 写带时间戳的行。
 *
 * 不复用 app/Log.php：那个类存在的理由是「cli-server / php-fpm 下没有 STDERR 常量」，
 * 而 CLI 下这个前提不存在；而且计划任务是每天跑的历史日志，没有时间戳根本没法看。
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
      --dry-run   只抓取并报告会写入哪些行，不修改数据库
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

$dbPath = Config::archiveDbPath();
out('数据集：' . $dbPath);
out('模式：' . ($dryRun ? 'dry-run（不写库）' : '写入'));

// ============================================================
//  防重叠：拿不到锁说明上一次还没跑完，直接退出 0（不堆积）
// ============================================================

$lockPath = Config::dataDir() . '/update-archive.lock';
$lockHandle = fopen($lockPath, 'c');
if ($lockHandle === false) {
    fail('无法打开锁文件：' . $lockPath . '（请检查 data/ 目录权限）');
    exit(1);
}
if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
    out('已有实例在运行（锁 ' . $lockPath . '），本次跳过。');
    fclose($lockHandle);
    exit(0);
}

// 无论后面怎么退出都要放锁
register_shutdown_function(static function () use ($lockHandle): void {
    if (is_resource($lockHandle)) {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
});

// ============================================================
//  抓取
// ============================================================

/**
 * 取 Bing 首页归档。
 *
 * mkt=zh-CN 与 app/Bing.php 用的 cc=zh 实测返回完全一致的中文数据，
 * 这里沿用 mkt 写法（更明确）。
 *
 * @return list<array<string,mixed>>
 */
function fetchImages(): array
{
    $url = Config::bingApiUrl() . '?' . http_build_query([
        'mkt' => 'zh-CN',
        'format' => 'js',
        'idx' => 0,
        'n' => FETCH_COUNT,
        'uhd' => 1,
    ]);

    $response = Http::get($url, 20);

    if ($response['status'] < 200 || $response['status'] >= 300) {
        throw new RuntimeException('Bing 接口返回 HTTP ' . $response['status']);
    }

    $body = Http::toUtf8($response['body'], Http::charsetOf($response['headers']));

    try {
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        throw new RuntimeException('解析 Bing 接口响应失败: ' . $e->getMessage(), 0, $e);
    }

    if (!is_array($decoded) || !isset($decoded['images']) || !is_array($decoded['images'])) {
        throw new RuntimeException('Bing 接口返回结构不符合预期（缺少 images）');
    }

    return array_values(array_filter($decoded['images'], 'is_array'));
}

try {
    $images = fetchImages();
} catch (Throwable $e) {
    fail('抓取失败，本次不写库：' . $e->getMessage());
    exit(1);
}

out('抓到 ' . count($images) . ' 条');

if ($images === []) {
    fail('Bing 接口没有返回任何壁纸数据，本次不写库');
    exit(1);
}

// ============================================================
//  组装 15 列
// ============================================================

$utc = new DateTimeZone('UTC');
$shanghai = new DateTimeZone('Asia/Shanghai');

$rows = [];
$skippedMalformed = 0;

foreach ($images as $image) {
    $relativeUrl = (string) ($image['url'] ?? '');
    $startDate = (string) ($image['startdate'] ?? '');
    $fullStart = (string) ($image['fullstartdate'] ?? '');

    if ($relativeUrl === '' || $startDate === '') {
        ++$skippedMalformed;
        continue;
    }

    // 北京时间：fullstartdate 是 UTC 的 YmdHi（12 位，没有秒 —— 用 YmdHis 会解析失败）。
    // 前缀 ! 把未指定的字段归零，否则秒/微秒会取「当前时刻」，跨日边界时算出错误日期。
    $date = null;
    $stamp = DateTimeImmutable::createFromFormat('!YmdHi', $fullStart, $utc);
    if ($stamp !== false) {
        $date = $stamp->setTimezone($shanghai);
    }

    if ($date === null) {
        // 退路：接口没给 fullstartdate 时按 startdate + 1 天（= 实测确认的换算关系）
        $fallback = DateTimeImmutable::createFromFormat('!Ymd', $startDate, $shanghai);
        if ($fallback === false) {
            ++$skippedMalformed;
            continue;
        }
        $date = $fallback->modify('+1 day');
        out('警告：' . $startDate . ' 缺少可解析的 fullstartdate，按 startdate + 1 天处理（'
            . $date->format('Y-m-d') . '），该行日期可能不准');
    }

    // 一致性自检：Bing 历史上 fullstartdate 恒为 UTC 16:00，
    // 因此北京时间应当正好比 startdate 晚一天。若哪天不等了，
    // 说明 Bing 改了口径，数据集会出现新旧两套日期基准 —— 值得留一行日志。
    $byStartPlusOne = DateTimeImmutable::createFromFormat('!Ymd', $startDate, $shanghai);
    if ($byStartPlusOne !== false && $byStartPlusOne->modify('+1 day')->format('Y-m-d') !== $date->format('Y-m-d')) {
        out('警告：' . $startDate . ' 的北京时间(' . $date->format('Y-m-d')
            . ')不再等于 startdate + 1 天，Bing 的日期口径可能变了');
    }

    $dateStr = $date->format('Y-m-d');

    // 域名必须是 cn.bing.com，否则 Archive::upgradeableTo4k() 会返回 null（见文件头第 2 条）
    $url = Config::archiveImageBase() . $relativeUrl;
    // urlbase = 第一个 & 之前的部分 —— 与 merge 脚本的 _extract_urlbase() 同口径
    $amp = strpos($url, '&');
    $urlbase = $amp === false ? $url : substr($url, 0, $amp);

    $rows[$dateStr] = [
        'date' => $dateStr,
        'date_compact' => $date->format('Ymd'),
        'year' => (int) $date->format('Y'),
        'month' => (int) $date->format('n'),
        'day' => (int) $date->format('j'),
        // 前端的 WEEKDAY_ZH 用英文全名做键，这里必须是 'Monday' 这种
        'weekday' => $date->format('l'),
        'title' => (string) ($image['title'] ?? ''),
        'title_en' => '',
        'copyright' => (string) ($image['copyright'] ?? ''),
        'copyright_en' => '',
        'copyrightlink' => (string) ($image['copyrightlink'] ?? ''),
        'url' => $url,
        'urlbase' => $urlbase,
        'region' => REGION_TAG,
        'sources' => SOURCE_TAG,
    ];
}

if ($skippedMalformed > 0) {
    out('警告：' . $skippedMalformed . ' 条数据字段不全，已跳过');
}

$candidateRows = array_values($rows);

if ($candidateRows === []) {
    fail('组装后没有任何可用记录，本次不写库');
    exit(1);
}

// 自检：每行的 url_4k 必须算得出来，否则写进去的是「4K 按钮点不动」的行
$no4k = array_filter($candidateRows, static fn (array $r): bool => Archive::upgradeableTo4k($r['url']) === null);
if ($no4k !== []) {
    fail(sprintf(
        '有 %d 行的 url 推导不出 4K（域名应为 %s，实际如 %s），本次不写库',
        count($no4k),
        Config::archiveImageBase(),
        $no4k[array_key_first($no4k)]['url']
    ));
    exit(1);
}

// ============================================================
//  写库
// ============================================================

if (!is_file($dbPath)) {
    fail('数据集不存在：' . $dbPath . '（本脚本只往已有库里补行，不建库）');
    exit(1);
}
if (!$dryRun && !is_writable($dbPath)) {
    fail('数据集不可写：' . $dbPath . '（检查文件属主与权限，见 DEPLOY.md 第 6 节）');
    exit(1);
}

try {
    $pdo = new PDO('sqlite:' . $dbPath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        // 与 Web 端的只读连接抢锁时最多等 10 秒，别一碰就 SQLITE_BUSY
        PDO::ATTR_TIMEOUT => 10,
    ]);
} catch (PDOException $e) {
    fail('打开数据集失败：' . $e->getMessage());
    exit(1);
}

// 双保险：ATTR_TIMEOUT 之外再显式设一次（要求 ≥5 秒）
$pdo->exec('PRAGMA busy_timeout = 10000');

// 不碰 journal_mode：Web 端把这个库当单文件读，切成 WAL 会多出 -wal / -shm
// 两个附属文件，部署与备份都得跟着变，没必要。

// 写之前先确认打开的是那张表 —— 打错库时否则要等到 INSERT 才报错，信息很难读
$tableSql = $pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='bing_wallpapers'")->fetchColumn();
if (!is_string($tableSql) || $tableSql === '') {
    fail('数据集里没有 bing_wallpapers 表，确认路径是否正确：' . $dbPath);
    exit(1);
}

$actualColumns = [];
foreach ($pdo->query('PRAGMA table_info(bing_wallpapers)') as $col) {
    $actualColumns[] = (string) $col['name'];
}
$missing = array_diff(EXPECTED_COLUMNS, $actualColumns);
if ($missing !== []) {
    fail('表结构与预期不符，缺少列：' . implode(', ', $missing));
    exit(1);
}

$insertSql = 'INSERT OR IGNORE INTO bing_wallpapers ('
    . implode(', ', EXPECTED_COLUMNS)
    . ') VALUES (:' . implode(', :', EXPECTED_COLUMNS) . ')';

if ($dryRun) {
    // dry-run 也要如实报告「哪些是新的」，所以还是查一次库（只读，不写）
    $existingStmt = $pdo->prepare('SELECT 1 FROM bing_wallpapers WHERE date = :date');

    out('--- dry-run 明细 ---');
    $wouldInsert = 0;
    foreach ($candidateRows as $row) {
        $existingStmt->execute([':date' => $row['date']]);
        $exists = $existingStmt->fetchColumn() !== false;
        if (!$exists) {
            ++$wouldInsert;
        }
        printf(
            "  %s  %-9s  %s  %s\n",
            $row['date'],
            $row['weekday'],
            $exists ? '已存在' : '将新增',
            $row['title'] !== '' ? $row['title'] : '(无标题)'
        );
    }
    out(sprintf(
        '抓到 %d 条 / 将新增 %d 条 / 已存在 %d 条（dry-run，未写库）',
        count($candidateRows),
        $wouldInsert,
        count($candidateRows) - $wouldInsert
    ));
    exit(0);
}

$inserted = 0;
$ignored = 0;

try {
    // BEGIN IMMEDIATE：立刻拿写锁，避免「读到一半才发现写不了」再回滚
    $pdo->exec('BEGIN IMMEDIATE');

    $stmt = $pdo->prepare($insertSql);
    foreach ($candidateRows as $row) {
        $params = [];
        foreach (EXPECTED_COLUMNS as $column) {
            $params[':' . $column] = $row[$column];
        }
        $stmt->execute($params);
        // SQLite 下 INSERT OR IGNORE 命中主键冲突时 changes() 为 0
        if ($stmt->rowCount() > 0) {
            ++$inserted;
        } else {
            ++$ignored;
        }
    }

    $pdo->exec('COMMIT');
} catch (PDOException $e) {
    try {
        $pdo->exec('ROLLBACK');
    } catch (PDOException) {
        // 回滚失败无所谓，下面就要退出了
    }
    fail('写库失败，已回滚：' . $e->getMessage());
    exit(1);
}

out(sprintf('抓到 %d 条 / 新增 %d 条 / 跳过 %d 条', count($candidateRows), $inserted, $ignored));

$total = (int) $pdo->query('SELECT COUNT(*) FROM bing_wallpapers')->fetchColumn();
$range = $pdo->query('SELECT MIN(date) mn, MAX(date) mx FROM bing_wallpapers')->fetch() ?: [];
out(sprintf(
    '当前库：共 %d 行，日期范围 %s ~ %s',
    $total,
    (string) ($range['mn'] ?? '?'),
    (string) ($range['mx'] ?? '?')
));

exit(0);
