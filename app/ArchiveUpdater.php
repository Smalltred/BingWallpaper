<?php

declare(strict_types=1);

namespace BingWallpaper;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * 壁纸库「每日更新」的核心逻辑 —— 抓 Bing 最新壁纸（1080p）写进 bing_wallpapers 表。
 *
 * 为什么需要它：数据集 bingimages/bing_wallpapers.db 是 bingimages 项目用 merge 脚本
 * 离线产出的，最后一天停在产出那一刻。之后每天的新壁纸没人补 —— 壁纸库页就会
 * 「越放越旧」。
 *
 * ─────────────────────────────────────────────────────────────
 *  两个入口，共用这一份逻辑
 * ─────────────────────────────────────────────────────────────
 *
 *   1. 宝塔计划任务（兜底）→ tools/update-archive.php
 *      没访客的日子靠它 —— 每天 08:00 无条件跑一次，同时也是「自愈」通道：
 *      标记文件被误删、数据集被 merge 重建、上一次失败，它都会把状态拉回来。
 *      所以它**不读**当日标记（读了反而会失去自愈能力），只**写**。
 *
 *   2. 每天第一个访客 → public/index.php 的收尾钩子（deferAfterResponse()）
 *      不依赖 cron 也能更新。命中时**不在请求里同步跑**，而是先把响应放给访客
 *      （见 deferAfterResponse()），再在收尾阶段执行。
 *
 * 两边共用：
 *   - 同一把锁 data/update-archive.lock  → 同时来只会有一个真的动手
 *   - 同一个当日标记 data/archive-update.date → 谁先跑成，另一个当天就不必再跑
 *   - 同一个失败退避 data/archive-update.failed → 上游挂了时不会每个请求都去试一遍
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
 *    同理，当日标记也必须按北京时间记 —— 服务器时区若是 UTC，
 *    北京时间 00:05（= UTC 前一天 16:05）算出来的「今天」会是昨天，
 *    标记就永远对不上、每天反复触发。
 *
 * 2) url 的域名必须是 cn.bing.com（见 Config::archiveImageBase() 的说明）。
 *    用 Config::bingImageBase()（www.bing.com）会让 url_4k 全变 null。
 *
 * 3) region 用 'zh-cn'：既有 3850 行的取值就是 'zh-cn'，前端把 region 直接当标签展示，
 *    写 'cn' 会出现两种写法混排。
 *
 * 4) title_en / copyright_en 留空。实测接口**无论传什么市场参数都只返回中文**
 *    （mkt=en-US、cc=us、cc=jp、setlang=en 全试过，返回的仍是中文标题），
 *    所以英文标题在这个数据源上取不到。前端 displayTitle()/displayCopyright()
 *    本来就对这两个字段做了回退，留空不影响展示。
 *
 * 5) 只 INSERT、不 UPDATE/DELETE。date 是主键，INSERT OR IGNORE 天然幂等，
 *    重复跑不会覆盖数据集里已有的（可能更完整的）行。
 *
 * 6) 「今天已更新」的判据不只看「跑成功了」，还要看**最新一张图是不是今天**。
 *    北京时间刚过零点那一两分钟，Bing 可能还没切图（或接口/CDN 有缓存），
 *    这时抓回来的最新一张仍是昨天 —— 属于正常现象，但**不能记成当日已完成**，
 *    否则今天这张就永远补不上（要等到明天）。此时改设 10 分钟退避，
 *    由下一个访客重试；退避到期前 cron 也会兜住。
 *
 * ⚠️ bingimages/merge_bing_wallpapers.py 重建库时会整表 DROP 重建，
 *    会抹掉这里写进去的 daily-api 行 —— 本地重建后要重跑一次更新补回。
 *    详见 DEPLOY.md「壁纸库每日自动更新」。
 */
final class ArchiveUpdater
{
    /** 表结构（15 列）—— 写之前用它确认「打开的是不是那张表」 */
    public const EXPECTED_COLUMNS = [
        'date', 'date_compact', 'year', 'month', 'day', 'weekday',
        'title', 'title_en', 'copyright', 'copyright_en', 'copyrightlink',
        'url', 'urlbase', 'region', 'sources',
    ];

    /** 数据来源标记，写进 sources 列 —— 用来区分「merge 产出」与「每日抓取」 */
    public const SOURCE_TAG = 'daily-api';

    /** 数据集里的地区标记，与既有 3850 行一致（见类说明第 3 条） */
    public const REGION_TAG = 'zh-cn';

    /** Bing 的 n 参数上限就是 8，多传也只返回 8 条 */
    private const FETCH_COUNT = 8;

    /** 一切「今天」的判断都按北京时间（见类说明第 1 条） */
    private const TIMEZONE = 'Asia/Shanghai';

    /** 失败后的退避窗口（秒）：上游不可达时不要每个请求都去试一遍 */
    public const FAILURE_BACKOFF_SECONDS = 600;

    // ============================================================
    //  路径：两个入口 + 诊断脚本共用，避免各写一份
    // ============================================================

    /** 与 tools/update-archive.php 共用同一把锁，所以两边同时来不会互相踩 */
    public static function lockPath(): string
    {
        return Config::dataDir() . '/update-archive.lock';
    }

    /** 当日标记：内容 = 最近一次「确实抓到今天这张」的日期（北京时间 YYYY-MM-DD） */
    public static function successMarkerPath(): string
    {
        return Config::dataDir() . '/archive-update.date';
    }

    /** 失败/退避标记：第一行是 Unix 时间戳，第二行给人看 */
    public static function failureMarkerPath(): string
    {
        return Config::dataDir() . '/archive-update.failed';
    }

    // ============================================================
    //  首访触发的两个判断
    // ============================================================

    /**
     * 今天这一张是不是还没拿到？
     *
     * 这个方法会被**每个请求**调用一次，所以刻意做得极便宜：两次小文件读。
     * 命中概率每天只有寥寥几次（成功或退避之后一律为 false）。
     *
     * 三种返回 false 的情况：
     *   - 今天已经成功更新过（标记 = 今天）
     *   - 上次失败且还在 10 分钟退避窗口内
     *   - data/ 不可写 —— 见下面的说明
     */
    public function needsRun(): bool
    {
        // 总开关（ARCHIVE_AUTO_UPDATE=0 可关闭）。放最前面：关掉时连文件都不用读。
        // 关掉只是不自动触发，计划任务照旧兜底。
        if (!Config::archiveAutoUpdate()) {
            return false;
        }

        // data/ 不可写就整个放弃。
        //
        // 为什么不是「照样试一次」：标记写不下去 ⇒ needsRun() 永远为 true
        // ⇒ 每个请求都会往 Bing 发一次请求再失败一次，形成请求风暴。
        // 宁可在这里静默跳过（真正要靠的是计划任务），也不能把站点变成打 Bing 的机器。
        // 这个前提由 DEPLOY.md 保证：data/ 本来就要求可写（缓存、限流计数、锁都在这儿），
        // 真不可写的话站点自身也已经在退化了，见「常见问题」。
        if (!self::markerIsWritable()) {
            return false;
        }

        if ($this->lastSuccessDate() === $this->today()) {
            return false;
        }

        return $this->failureBackoffRemaining() <= 0;
    }

    /**
     * 挂到请求收尾：**先把响应放给访客，再跑更新**。
     *
     * 「访客不得因为更新变慢」这条硬要求全靠这里：
     *   1. 先 fastcgi_finish_request()（php-fpm）或冲掉输出缓冲（内置服务器），
     *      等响应真的离开进程之后才开始干活 —— 实测两种 SAPI 都能做到
     *      「客户端 3ms 拿到响应、后台工作再跑 3 秒」；
     *   2. 整段包在 try/catch 里 —— 更新出任何问题都不能影响已经发出去的响应。
     */
    public function deferAfterResponse(): void
    {
        // 访客中途刷新/关页面时也要把更新跑完，否则这一趟等于白抓
        ignore_user_abort(true);

        register_shutdown_function(function (): void {
            self::releaseResponse();

            // 放在放行响应之后：正常请求的执行时限不受影响，只给这段收尾工作放宽
            @set_time_limit(120);

            try {
                $result = $this->run();

                if ($result['locked']) {
                    // 计划任务（或另一个访客）正在跑，干的是同一件事 —— 静默让路
                    Log::info('首访自动更新：已有实例在运行，本次跳过');

                    return;
                }

                Log::info(sprintf(
                    '首访自动更新完成：抓到 %d 条 / 新增 %d 条 / 跳过 %d 条（最新 %s，库内 %d 行）',
                    $result['fetched'],
                    $result['inserted'],
                    $result['skipped'],
                    $result['latest_date'],
                    $result['total']
                ));

                if ($result['pending_today']) {
                    Log::warn('首访自动更新：Bing 尚未切换今天的壁纸，已设退避，稍后由下一个访客或计划任务重试');
                }
            } catch (Throwable $e) {
                // 失败已在 run() 里落过退避标记，这里只负责不把异常漏给响应
                Log::error('首访自动更新失败（不影响本次响应）：' . $e->getMessage());
            }
        });
    }

    // ============================================================
    //  真正干活
    // ============================================================

    /**
     * 执行一次更新。
     *
     * @param bool $dryRun 只抓取、只报告，不写库也不写任何标记
     *
     * @return array{
     *     fetched:int, inserted:int, skipped:int,
     *     dry_run:bool, locked:bool, pending_today:bool, latest_date:string,
     *     total:int, min_date:string, max_date:string,
     *     rows:list<array{date:string, weekday:string, title:string, status:string}>
     * }
     *     locked=true 表示没抢到锁（已有实例在跑），其余字段都是占位值 ——
     *     这是正常情况，不是错误。
     *
     * @throws RuntimeException 抓取失败 / 数据集不可用 / 表结构不符 / 写库失败
     */
    public function run(bool $dryRun = false): array
    {
        $lock = $this->tryLock();
        if ($lock === null) {
            return self::emptyReport($dryRun, locked: true);
        }

        try {
            $report = $this->perform($dryRun);
        } catch (Throwable $e) {
            // 失败必须留痕（否则下一个访客立刻又去试一遍）；
            // --dry-run 是「只看不写」，连标记都不写。
            if (!$dryRun) {
                $this->recordFailure($e->getMessage());
            }
            throw $e;
        } finally {
            self::releaseLock($lock);
        }

        if (!$dryRun) {
            if ($report['pending_today']) {
                // 抓到了、也写了，但 Bing 最新一张还不是今天 —— 见类说明第 6 条。
                // 故意不写当日标记：写了今天就再也不会补，只能等明天。
                $this->recordFailure('Bing 尚未切换今天的壁纸，最新一张为 ' . $report['latest_date']);
            } else {
                $this->recordSuccess($this->today());
            }
        }

        return $report;
    }

    /** 当日标记里的日期（北京时间 YYYY-MM-DD）；读不到或读不懂返回 null（fail-open，让系统能自愈） */
    public function lastSuccessDate(): ?string
    {
        $raw = @file_get_contents(self::successMarkerPath());
        if ($raw === false) {
            return null;
        }

        $first = trim((string) strtok($raw, "\n"));

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $first) === 1 ? $first : null;
    }

    /** 退避还剩多少秒；0 表示可以跑 */
    public function failureBackoffRemaining(): int
    {
        $raw = @file_get_contents(self::failureMarkerPath());
        if ($raw === false) {
            return 0;
        }

        $first = trim((string) strtok($raw, "\n"));
        // 读不懂就当没有退避：坏掉的标记文件不该把功能永久卡死
        if (!ctype_digit($first)) {
            return 0;
        }

        $stamp = (int) $first;
        $now = time();
        // 时间戳来自未来（改过系统时间/时区）同样按无效处理，否则会长时间卡住
        if ($stamp <= 0 || $stamp > $now) {
            return 0;
        }

        return max(0, self::FAILURE_BACKOFF_SECONDS - ($now - $stamp));
    }

    /** 今天的北京时间日期 */
    public function today(): string
    {
        return (new DateTimeImmutable('now', self::timezone()))->format('Y-m-d');
    }

    // ============================================================
    //  内部：抓取 → 组装 → 写库
    // ============================================================

    /**
     * @return array<string,mixed> 见 run() 的返回值说明
     */
    private function perform(bool $dryRun): array
    {
        $rows = $this->buildRows($this->fetchImages());

        if ($rows === []) {
            throw new RuntimeException('组装后没有任何可用记录，本次不写库');
        }

        // 自检：每行的 url 都必须能推导出 4K，否则写进去的是「4K 按钮点不动」的行。
        // 这条最值钱 —— 域名写错（比如误用 www.bing.com）会当场炸，
        // 而不是悄悄往壁纸库里灌一批降级数据。
        $no4k = array_filter($rows, static fn (array $r): bool => Archive::upgradeableTo4k($r['url']) === null);
        if ($no4k !== []) {
            throw new RuntimeException(sprintf(
                '有 %d 行的 url 推导不出 4K（域名应为 %s，实际如 %s），本次不写库',
                count($no4k),
                Config::archiveImageBase(),
                $no4k[array_key_first($no4k)]['url']
            ));
        }

        $today = $this->today();
        $latest = (string) max(array_column($rows, 'date'));
        // 字符串比较即可：两边都是 YYYY-MM-DD。
        // 用 < 而不是 !==：万一接口给出的日期比「今天」还新（服务器时区/时钟问题），
        // 那也不该被当成「还没换图」而反复重试。
        $pending = $latest < $today;

        $pdo = $this->connectDataset($dryRun ? 'read' : 'write');
        $this->assertSchema($pdo);

        if ($dryRun) {
            $existsStmt = $pdo->prepare('SELECT 1 FROM bing_wallpapers WHERE date = :date');
            $listed = [];
            $wouldInsert = 0;
            foreach ($rows as $row) {
                $existsStmt->execute([':date' => $row['date']]);
                $exists = $existsStmt->fetchColumn() !== false;
                if (!$exists) {
                    ++$wouldInsert;
                }
                $listed[] = [
                    'date' => $row['date'],
                    'weekday' => $row['weekday'],
                    'title' => $row['title'],
                    'status' => $exists ? 'exists' : 'new',
                ];
            }

            return [
                'fetched' => count($rows),
                'inserted' => $wouldInsert,
                'skipped' => count($rows) - $wouldInsert,
                'dry_run' => true,
                'locked' => false,
                'pending_today' => $pending,
                'latest_date' => $latest,
                'total' => 0,
                'min_date' => '',
                'max_date' => '',
                'rows' => $listed,
            ];
        }

        $insertSql = 'INSERT OR IGNORE INTO bing_wallpapers ('
            . implode(', ', self::EXPECTED_COLUMNS)
            . ') VALUES (:' . implode(', :', self::EXPECTED_COLUMNS) . ')';

        $inserted = 0;
        $skipped = 0;
        $listed = [];

        try {
            // BEGIN IMMEDIATE：立刻拿写锁，避免「读到一半才发现写不了」再回滚
            $pdo->exec('BEGIN IMMEDIATE');
            $stmt = $pdo->prepare($insertSql);

            foreach ($rows as $row) {
                $params = [];
                foreach (self::EXPECTED_COLUMNS as $column) {
                    $params[':' . $column] = $row[$column];
                }
                $stmt->execute($params);

                // SQLite 下 INSERT OR IGNORE 命中主键冲突时 changes() 为 0
                $isNew = $stmt->rowCount() > 0;
                if ($isNew) {
                    ++$inserted;
                } else {
                    ++$skipped;
                }

                $listed[] = [
                    'date' => $row['date'],
                    'weekday' => $row['weekday'],
                    'title' => $row['title'],
                    'status' => $isNew ? 'inserted' : 'skipped',
                ];
            }

            $pdo->exec('COMMIT');
        } catch (PDOException $e) {
            try {
                $pdo->exec('ROLLBACK');
            } catch (PDOException) {
                // 回滚失败无所谓，下面就要抛出去了
            }
            throw new RuntimeException('写库失败，已回滚：' . $e->getMessage(), 0, $e);
        }

        $stats = $this->datasetStats($pdo);

        return [
            'fetched' => count($rows),
            'inserted' => $inserted,
            'skipped' => $skipped,
            'dry_run' => false,
            'locked' => false,
            'pending_today' => $pending,
            'latest_date' => $latest,
            'total' => $stats['total'],
            'min_date' => $stats['min_date'],
            'max_date' => $stats['max_date'],
            'rows' => $listed,
        ];
    }

    /**
     * 取 Bing 首页归档。
     *
     * mkt=zh-CN 与 app/Bing.php 用的 cc=zh 实测返回完全一致的中文数据，
     * 这里沿用 mkt 写法（更明确）。
     *
     * @return list<array<string,mixed>>
     *
     * @throws RuntimeException 网络失败 / 非 2xx / 响应不是预期结构
     */
    private function fetchImages(): array
    {
        $url = Config::bingApiUrl() . '?' . http_build_query([
            'mkt' => 'zh-CN',
            'format' => 'js',
            'idx' => 0,
            'n' => self::FETCH_COUNT,
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

        $images = array_values(array_filter($decoded['images'], 'is_array'));

        if ($images === []) {
            throw new RuntimeException('Bing 接口没有返回任何壁纸数据，本次不写库');
        }

        return $images;
    }

    /**
     * 组装 15 列。按 date 去重（接口偶尔会给同一天两条），保留先出现的那条
     * （数组顺序 = 接口顺序，最新的在最前）。
     *
     * @param list<array<string,mixed>> $images
     *
     * @return list<array<string,mixed>>
     */
    private function buildRows(array $images): array
    {
        $utc = new DateTimeZone('UTC');
        $shanghai = self::timezone();

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
                Log::warn('壁纸库更新：' . $startDate . ' 缺少可解析的 fullstartdate，'
                    . '按 startdate + 1 天处理（' . $date->format('Y-m-d') . '），该行日期可能不准');
            }

            // 一致性自检：Bing 历史上 fullstartdate 恒为 UTC 16:00，
            // 因此北京时间应当正好比 startdate 晚一天。若哪天不等了，
            // 说明 Bing 改了口径，数据集会出现新旧两套日期基准 —— 值得留一行日志。
            $byStartPlusOne = DateTimeImmutable::createFromFormat('!Ymd', $startDate, $shanghai);
            if ($byStartPlusOne !== false && $byStartPlusOne->modify('+1 day')->format('Y-m-d') !== $date->format('Y-m-d')) {
                Log::warn('壁纸库更新：' . $startDate . ' 的北京时间(' . $date->format('Y-m-d')
                    . ')不再等于 startdate + 1 天，Bing 的日期口径可能变了');
            }

            $dateStr = $date->format('Y-m-d');

            // 域名必须是 cn.bing.com，否则 Archive::upgradeableTo4k() 会返回 null（见类说明第 2 条）
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
                'region' => self::REGION_TAG,
                'sources' => self::SOURCE_TAG,
            ];
        }

        if ($skippedMalformed > 0) {
            Log::warn('壁纸库更新：' . $skippedMalformed . ' 条数据字段不全，已跳过');
        }

        return array_values($rows);
    }

    /**
     * 打开数据集。
     *
     * @param 'read'|'write' $mode write 时额外要求文件可写；read 供 --dry-run 用
     *
     * @throws RuntimeException 文件不存在 / 不可写 / 打不开
     */
    private function connectDataset(string $mode): PDO
    {
        $dbPath = Config::archiveDbPath();

        if (!is_file($dbPath)) {
            throw new RuntimeException('数据集不存在：' . $dbPath . '（本脚本只往已有库里补行，不建库）');
        }

        if ($mode === 'write' && !is_writable($dbPath)) {
            throw new RuntimeException('数据集不可写：' . $dbPath . '（检查文件属主与权限，见 DEPLOY.md 第 6 节）');
        }

        try {
            $pdo = new PDO('sqlite:' . $dbPath, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                // 与 Web 端的只读连接抢锁时最多等 10 秒，别一碰就 SQLITE_BUSY
                PDO::ATTR_TIMEOUT => 10,
            ]);
        } catch (PDOException $e) {
            throw new RuntimeException('打开数据集失败：' . $e->getMessage(), 0, $e);
        }

        // 双保险：ATTR_TIMEOUT 之外再显式设一次（要求 ≥5 秒）
        $pdo->exec('PRAGMA busy_timeout = 10000');

        // 不碰 journal_mode：Web 端把这个库当单文件读，切成 WAL 会多出 -wal / -shm
        // 两个附属文件，部署与备份都得跟着变，没必要。

        return $pdo;
    }

    /**
     * 写库之前先确认打开的是那张表 —— 否则要等到 INSERT 才报错，信息很难读。
     *
     * @throws RuntimeException
     */
    private function assertSchema(PDO $pdo): void
    {
        $tableSql = $pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='bing_wallpapers'")->fetchColumn();
        if (!is_string($tableSql) || $tableSql === '') {
            throw new RuntimeException('数据集里没有 bing_wallpapers 表，确认路径是否正确：' . Config::archiveDbPath());
        }

        $actual = [];
        foreach ($pdo->query('PRAGMA table_info(bing_wallpapers)') as $col) {
            $actual[] = (string) $col['name'];
        }

        $missing = array_diff(self::EXPECTED_COLUMNS, $actual);
        if ($missing !== []) {
            throw new RuntimeException('表结构与预期不符，缺少列：' . implode(', ', $missing));
        }
    }

    /**
     * @return array{total:int, min_date:string, max_date:string}
     */
    private function datasetStats(PDO $pdo): array
    {
        $range = $pdo->query('SELECT MIN(date) mn, MAX(date) mx FROM bing_wallpapers')->fetch() ?: [];

        return [
            'total' => (int) $pdo->query('SELECT COUNT(*) FROM bing_wallpapers')->fetchColumn(),
            'min_date' => (string) ($range['mn'] ?? '?'),
            'max_date' => (string) ($range['mx'] ?? '?'),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private static function emptyReport(bool $dryRun, bool $locked): array
    {
        return [
            'fetched' => 0,
            'inserted' => 0,
            'skipped' => 0,
            'dry_run' => $dryRun,
            'locked' => $locked,
            'pending_today' => false,
            'latest_date' => '',
            'total' => 0,
            'min_date' => '',
            'max_date' => '',
            'rows' => [],
        ];
    }

    // ============================================================
    //  内部：锁 与 标记文件
    // ============================================================

    /**
     * 非阻塞抢锁。
     *
     * @return resource|null null = 别人正在跑（正常情况，直接让路）
     *
     * @throws RuntimeException 锁文件都打不开（data/ 权限问题）
     */
    private function tryLock()
    {
        $handle = @fopen(self::lockPath(), 'c');
        if ($handle === false) {
            throw new RuntimeException('无法打开锁文件：' . self::lockPath() . '（请检查 data/ 目录权限）');
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return null;
        }

        return $handle;
    }

    /** @param resource $handle */
    private static function releaseLock($handle): void
    {
        if (is_resource($handle)) {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * 先把已有的输出缓冲冲出去。
     *
     * php-fpm（宝塔）有 fastcgi_finish_request，交给它把响应收尾最干净；
     * 内置服务器 / mod_php 没有这个函数，就自己冲 —— 实测同样有效
     * （Content-Length 已由 Response 设好，客户端拿到整包即可返回，
     * 不必等连接关闭，所以之后那几秒的后台工作拖不住它）。
     */
    private static function releaseResponse(): void
    {
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();

            return;
        }

        // ob_end_flush() 对「不可删除」的缓冲会返回 false 且不降低层级，
        // 不判返回值就是个死循环 —— 而这里正是收尾路径，卡住等于请求永不结束。
        while (ob_get_level() > 0) {
            if (!@ob_end_flush()) {
                break;
            }
        }

        @flush();
    }

    /** 标记文件写不写得下去（决定首访触发是否可用，见 needsRun()） */
    private static function markerIsWritable(): bool
    {
        $marker = self::successMarkerPath();
        $target = is_file($marker) ? $marker : dirname($marker);

        return is_writable($target);
    }

    private function recordSuccess(string $date): void
    {
        self::writeFile(self::successMarkerPath(), $date . "\n");
        // 成功即清掉退避标记：这个文件存在 ⇒ 最近一次尝试是失败的
        @unlink(self::failureMarkerPath());
    }

    /**
     * 记一次失败（或「还没换图」这种需要稍后重试的情况）。
     *
     * 文件内容第一行是 Unix 时间戳 —— 用时间戳而不是格式化日期，
     * 是为了完全避开服务器时区带来的歧义（见类说明第 1 条）。
     * 第二行是给人看的，含原因，方便直接 cat 出来排查。
     */
    private function recordFailure(string $reason): void
    {
        $now = time();
        $human = (new DateTimeImmutable('@' . $now))
            ->setTimezone(self::timezone())
            ->format('Y-m-d H:i:s P');

        self::writeFile(self::failureMarkerPath(), $now . "\n" . $human . '  ' . $reason . "\n");
    }

    /** 标记文件都是几字节的小文件，LOCK_EX 足够，不必走「临时文件 + rename」那套 */
    private static function writeFile(string $path, string $content): void
    {
        // 写不下去不是致命错误：标记丢了顶多多跑一次（幂等），不该让更新本身失败
        @file_put_contents($path, $content, LOCK_EX);
    }

    private static function timezone(): DateTimeZone
    {
        return new DateTimeZone(self::TIMEZONE);
    }
}
