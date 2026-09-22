# 部署说明

这个包是**标准 PHP 项目结构**：`public/` 是 Web 根，应用代码在它外面的 `app/`。
前端已编译好、壁纸库数据集和 `.env` 都已内置，**不含前端源码、不含 Node 运行时、
不需要 MySQL 之类的数据库服务**。

**部署路径**：上传到网站目录 → 解压 → 把「运行目录」设为 `/public` → 粘一行伪静态 → 完成。

之所以沿用这个结构而不是把入口摊在根目录：**让 URL 路径与物理路径一致**
（`/assets/x.js` ↔ `public/assets/x.js`），nginx/Apache 用它自带的规则就能直接发静态文件，
既不需要 `^~` 覆盖，也不需要任何"拒绝访问内部目录"的规则 —— 因为 `app/` 压根不在 Web 根里。

- 接口文档在应用自身的 `/docs` 路径
- 源码与开发说明见项目仓库的 `README.md`

---

## 1. 运行要求

| 项目 | 要求 |
|---|---|
| PHP | **8.2 或更高**（8.2 / 8.3 / 8.4 均可） |
| PHP 扩展 | `curl`、`openssl`、`mbstring`、`fileinfo`、**`pdo_sqlite`** |
| Web 服务器 | nginx 或 Apache（面板默认即可） |
| Node.js / MySQL | **都不需要** |

> `fileinfo` 与 `pdo_sqlite` 在宝塔里**默认没有安装**，必须手动勾选，见 3.1。
> 漏装 `pdo_sqlite` 的表现是「壁纸库」页打不开（接口 503），其余功能正常。

## 2. 目录结构

```
（解压到网站目录，例如 /www/wwwroot/bing.hecady.com/）
├── public/                  ← Web 根，宝塔「运行目录」指向它
│   ├── index.php            唯一入口（前端控制器）
│   ├── index.html           SPA 外壳
│   ├── assets/              构建产物（文件名带内容指纹）
│   ├── fonts/               自托管字体
│   ├── favicon*.* og-image.png
│   └── .htaccess            Apache 环境的重写规则（自动生效）
├── app/                     应用代码 —— 在 Web 根之外，HTTP 不可达
│   ├── Config.php  Request.php  Response.php  Bing.php  Archive.php
│   ├── Http.php  Cache.php  RateLimiter.php  StaticFiles.php  Log.php
│   ├── ArchiveUpdater.php   壁纸库每日更新的核心（首访 / 计划任务共用，见第 7 节）
│   └── views/docs.html      接口文档正文
├── dataset/                 壁纸库数据集 —— 已随包内置，Web 根之外
│   └── bing_wallpapers.db   SQLite，约 1.8 MB（每天首次访问或 08:00 计划任务追加新行）
├── data/                    运行时数据（Bing 缓存、限流计数、更新脚本的锁/日志/标记）—— 需可写
├── tools/                   └── update-archive.php  壁纸库每日更新的 CLI 入口（见第 7 节）
├── deploy/                  nginx 完整配置样例、php.ini 样例
├── nginx-rewrite.conf       ← 要粘到面板「伪静态」里的内容
├── .env                     ★ 已预置好（生产配置），一般不用改
├── .env.example             全量配置示例（选项说明，供查询用）
├── DEPLOY.md                本文
└── BUILD-INFO.txt           构建时间、数据集版本、产物清单
```

三个要点：

- **不要改动目录层级。** 后端所有路径都相对 `public/index.php` 解析。
- **`app/`、`dataset/`、`data/` 必须留在 Web 根之外** —— 这是「不需要任何拒绝规则」的前提。
- **`.env` 已随包预置**（第 3.6 节列的值），开箱即用，不必再 `cp .env.example .env`。

---

## 3. 宝塔部署

### 3.1 先装对 PHP 和扩展

1. 软件商店 → 安装 **PHP 8.2**（或 8.3 / 8.4）
2. 该 PHP 版本的「设置」→「安装扩展」→ 勾选并安装：
   - **`fileinfo`**
   - **`pdo_sqlite`**

   `curl`、`openssl`、`mbstring` 宝塔通常已启用，`opcache` 也在。
3. 「设置」→「配置修改」确认这行：
   ```ini
   display_errors = Off
   ```
   这是**正确性要求**而非洁癖：本项目是纯 JSON API，任何 PHP 警告被打印进输出都会破坏 JSON 结构。

### 3.2 建站点 + 放文件

1. 网站 → 添加站点，PHP 版本选 **8.2**，记下站点根目录（如 `/www/wwwroot/bing.hecady.com`）
2. **删掉站点根目录里宝塔自带的 `index.html` 和 `404.html`**
   （不清也不会致命，但保留默认 404 页会让你误判某些 404 是应用返回的）
3. 把本包上传到站点根目录，**在线解压**
   （解压后根目录下应该能看到 `public/`、`app/`、`dataset/`、`data/` 这几个文件夹，
   以及 `.env` 文件。宝塔的在线解压默认会解出隐藏文件，若看不到 `.env` 请确认
   「显示隐藏文件」已打开 —— 少了它站点会以开发环境运行）

### 3.3 ★ 把「运行目录」设为 `public`（关键一步）

网站 → 设置 → **网站目录** → 「运行目录」下拉选 **`/public`** → 保存

**这一步不能省**，它是标准 PHP 结构的固定要求（ThinkPHP / Laravel 部署都是这么做的），
原因见开头：只有 Web 根指向 `public/`，静态资源的 URL 才会与物理路径一致。
**不设置的后果**：打开站点直接 403/404 —— 因为根目录下根本没有 `index.php`。

### 3.4 配伪静态

nginx 站点：网站 → 设置 → **伪静态** → 把 `nginx-rewrite.conf` 的内容整段粘进去 → 保存
Apache 站点：**不用管这一步**，`public/.htaccess` 会自动生效（确认 `AllowOverride` 为 All）

内容只有一条是必需的：

```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

它管的是 `/archive`、`/docs`、`/api/*`、`/sitemap.xml` 这些**不是真实文件**的路径 ——
不配的话 `/` 能打开，但这些全 404。文件里另外两块是「把静态资源缓存时间调长」的可选项，
删掉也不影响站点运行。

### 3.5 给 data/ 写权限

```bash
mkdir -p /www/wwwroot/bing.hecady.com/data
chown -R www:www /www/wwwroot/bing.hecady.com/data
```

权限不对也不会全站挂掉：缓存会退化成「每次重新抓」，限流会退化成放行。

> **只给 `data/` 授权，不要 `chown -R` 整个站点目录。**
> `app/`、`dataset/`、`public/` 都只需要读权限；给多了只会扩大被写入的面。
> 尤其不要在面板里把站点目录权限设成 777。

### 3.6 配置（已预置，一般不用动）

**包内已经带了 `.env`**，内容就是下面这几个值 —— 所以这一步默认可以跳过：

```ini
APP_ENV=production
SITE_ORIGIN=https://bing.hecady.com
BINGIMAGES_DIR=dataset
TRUST_PROXY=1
```

只有两种情况需要改：**换成别的域名**（改 `SITE_ORIGIN`），或者**站点不在反代后面**
（`TRUST_PROXY` 改 `0`）。改完**不需要重启 PHP**。

| 变量 | 预置值 | 说明 |
|---|---|---|
| `APP_ENV` | `production` | 生产环境：CORS 收紧、trust proxy 生效、docs 内嵌缓存 |
| `SITE_ORIGIN` | `https://bing.hecady.com` | sitemap.xml / robots.txt 用的站点地址，**换域名时要改** |
| `BINGIMAGES_DIR` | `dataset` | 壁纸库数据集目录，见第 6 节 |
| `TRUST_PROXY` | `1` | 前面有 1 层 nginx 时保持 `1`；若站点直接对外则填 `0`，否则限流会把所有访客算成同一个来源 |

其余可调项（`CORS_ORIGIN`、`BING_CACHE_TTL`、`API_RATE_LIMIT_*`、`APP_DEBUG`、
`ARCHIVE_IMAGE_BASE`、`BING_API_URL` 等）完整说明在 `.env.example` 里 ——
那份是**全量选项清单**，保留在包内供查询，
但**不要**直接拿它覆盖 `.env`（它默认是开发环境的值）。

> `ARCHIVE_IMAGE_BASE`（默认 `https://cn.bing.com`）只影响壁纸库里的图片域名。
> **不要**改成 `www.bing.com`：Bing 只在 `cn.bing.com` 这个域名下提供 4K 版本，
> 改了之后每天新补的行 `url_4k` 会全变 `null`、4K 按钮集体失效
> （`tools/update-archive.php` 检测到这种情况会直接拒绝写库并报错）。
> 它和 `/api/bing/today` 用的域名是**两回事**，后者跟 Bing 接口原文一致，是 `www.bing.com`。


---

## 4. 其它部署方式

### 4.1 本地 / 小流量：PHP 内置服务器

```bash
cd 项目根目录
php tools/serve.php
```

启动器会打印自检信息（PHP 版本、php.ini 路径、出站通道、生效的 CORS 与 trust proxy）。

> **项目路径含中文时注意**：统一用这个启动器，不要自己拼
> `php -S ... -t /绝对/路径/public`。Windows 上命令行参数里的非 ASCII 路径
> 会被按 ANSI 代码页重编码，子进程会报 `Directory C:\Users\<乱码>\... does not exist`。
> `serve.php` 已规避（先 chdir 到项目根，只传纯 ASCII 的相对路径）。

内置服务器是**单进程**的，只适合开发与极小流量。

### 4.2 自己配 nginx + php-fpm

参考 `deploy/nginx.conf.example`。要点：

- 站点根（`root`）指向 `public/`
- 反代必须**覆盖**而不是追加客户端 IP，否则限流可被伪造：
  ```nginx
  fastcgi_param HTTP_X_FORWARDED_FOR $remote_addr;
  ```
  并让 `.env` 里的 `TRUST_PROXY` 与实际代理层数一致。

---

## 5. php.ini 需要关注的项

Linux 上比 Windows 省事很多（CA 证书系统自带），只需注意：

```ini
extension=curl
extension=openssl
extension=mbstring
extension=fileinfo
extension=pdo_sqlite
zend_extension=opcache      ; opcache 是 Zend 扩展，只能这样加载

display_errors = Off        ; 见 3.1
log_errors     = On
default_charset = "UTF-8"
```

- `json` / `filter` / `iconv` / `pcre` / `zlib` 自 PHP 8.0 起已内置编译，
  **不存在** `php_json.dll` 之类的文件，不要去找。
- **Windows 额外要多配 CA 证书**（Linux 一般不用）：Windows 版 PHP 不自带根证书库，
  不配的话首页正常、`/docs` 正常，唯独 `/api/*` 一律 500，日志里是
  `unable to get local issuer certificate (20)`。解法是下载 `cacert.pem` 并设置
  `curl.cainfo` 与 `openssl.cafile`，改完**必须重启 PHP 进程**。

## 6. 壁纸库的数据集

`/archive` 页面需要 bingimages 数据集（历史壁纸归档，SQLite）。

**本包已内置该数据集**，位置 `dataset/bing_wallpapers.db`，并在 `.env` 里配好了：

```
BINGIMAGES_DIR=dataset
```

也就是说 **不需要你做任何事**，解压后「壁纸库」页就能用。

> 相对路径按**项目根**（站点目录）解析，所以 `dataset` 就是站点根下那个文件夹。
> 它落在 Web 根之外，因此既不会被 HTTP 直接下载到，也不会撞面板的「防跨站攻击
> open_basedir」限制 —— 这正是把它放在站点目录内、却不在 `public/` 下的原因。

**这份 db 是哪一版？** 看 `BUILD-INFO.txt`，里面记了数据集的大小、改动时间和 sha256
前 16 位。想换新数据集，两条路：

1. 重新打一个包（推荐）：在构建机上 `npm run package`，脚本会读当前
   `bingimages/bing_wallpapers.db` 打进新包；
2. 只替换数据文件：把新的 `bing_wallpapers.db` 覆盖到站点根的 `dataset/` 下即可，
   **不需要改 .env，也不需要重启 PHP**。

> 若把数据集放到站点目录之外（如 `/www/datasets/bingimages`），open_basedir 会拒绝访问，
> 症状是接口 503 —— 那就得在面板里把该路径加进 open_basedir 白名单，并把 `.env` 的
> `BINGIMAGES_DIR` 改成对应的绝对路径。

数据集缺失或路径不对时：`/archive` 页面仍能打开并提示「暂不可用」，相关接口返回 **503**，
**今日壁纸、接口文档等其它功能完全不受影响**。

**写入方是谁**（别再理解成「这个库永远是只读的」）：

- **Web 后端只读** —— 连接后立刻 `PRAGMA query_only = 1`，从连接层禁止写操作落到数据集上，
  即使权限给多了也一样。这一点在代码层面保证。
- **只有每日更新代码会写** —— 核心是 `app/ArchiveUpdater.php`，由两条路径触发：
  **每天首次访问**（异步、不拖慢访客，默认开）或**计划任务**（每天 08:00，可选兜底）。
  两者都只往表里 `INSERT OR IGNORE` 若干行，**不会** UPDATE/DELETE 既有行。
  详见下一节。

## 7. 壁纸库每日自动更新

数据集是 bingimages 项目用 merge 脚本**离线**产出的，最后一天停在产出那一刻。
之后每天的新壁纸没人补，壁纸库页就会「越放越旧」。这一节负责把缺口补上。

**两种触发方式，目的都是「当天补上当天那张」：**

| 方式 | 默认 | 作用 |
|---|---|---|
| **每天首次访问自动更新** | **开** | 有人打开站点就把当天缺口补上；不依赖任何外部调度 |
| 宝塔计划任务（每天 08:00） | 可选 | 兜底 —— 某天**一个访客都没有**时，由 cron 补上 |

两者**可以同时配**，不会冲突也不会重复写：它们共用同一把锁 `data/update-archive.lock`
和同一个「今天已成功」标记 `data/archive-update.date`，谁先跑谁写，另一个看到标记
就什么都不做。**这不是二选一。**

### 7.1 它做什么

两条触发路径最终都调用同一个核心类 `BingWallpaper\ArchiveUpdater`：
抓一次 Bing 首页归档（北京时间当天 + 倒序 7 天，共 8 条），
按数据集的既有约定组装成 15 列，`INSERT OR IGNORE` 进 `bing_wallpapers` 表。

- **幂等**：`date` 是主键，同一天跑一百遍也只会有那一行；重复跑报告「新增 0 条」。
- **只增不改**：不 UPDATE、不 DELETE、不覆盖已有行。
- **防重叠**：用 `data/update-archive.lock` 加 `flock`。上一次还没跑完时，本次直接退出 0。
- **不动图片**：只写 URL，壁纸仍直接引 Bing CDN，与站点现有行为一致。
- **失败安全**：抓取失败 / 库不可写 / 表结构不对时写明原因并**退出非 0**，绝不写坏库。

> 每天的日期按**北京时间**记（Bing 接口给的是 UTC，换算后正好是北京时间零点那天的壁纸）。
> `region` 写 `zh-cn`、`sources` 写 `daily-api` —— 后者让你一眼能看出哪些行是每日补的。

### 7.2 首访自动更新（默认开启）

站点入口 `public/index.php` 在 `Config::bootstrap()` 之后、路由之前会问一句
`ArchiveUpdater::needsRun()`：**今天还没成功更新过吗？** 是的话就排一次后台更新。

三个必须知道的点：

1. **不拖慢访客**。更新**不在请求里同步跑** —— 先把响应完整吐给访客，再在
   `register_shutdown_function` 里执行。线上 php-fpm 走 `fastcgi_finish_request()`
   立即归还连接；没有这个函数的 SAPI（例如 Windows 内置服务器）退化为 `flush()`
   冲刷输出缓冲，同样能在发完响应后再干活。**首访的响应时间不受影响。**
2. **绝不影响页面**。整段逻辑包在 `try/catch` 里，任何异常只写 `Log::error`，
   页面该渲染什么还是什么。响应已经发出去了，更新失败跟访客无关。
3. **拿不到锁就安静跳过**。如果此时 cron 正在跑（锁被占），首访这条路径什么都不做，
   直接放过 —— 不排队、不等待、不报错。

**两个标记文件**（都在 `data/`，都已 ignore，不进部署包）：

| 文件 | 内容 | 用途 |
|---|---|---|
| `data/archive-update.date` | 最后一次成功更新的日期 `YYYY-MM-DD` | 等于今天 ⇒ 今天不再更新 |
| `data/archive-update.failed` | 第一行是 Unix 时间戳，之后是北京时间 + 原因 | 失败退避，见下 |

**失败退避（10 分钟）**：抓取失败时**不写**成功标记，改写成失败标记。
此后 10 分钟内 `needsRun()` 一律返回 false ⇒ 不会再向 Bing 发请求。
这是为了避免 Bing 或本机网络抖动时，**每一个访客都去撞一次**、把请求量放大。
10 分钟后自动允许重试；一旦某次成功，成功标记被写上，失败标记即刻作废。

**顺带一提「Bing 还没换图」这种情况**：北京刚过零点时，Bing 首页归档里最新一张可能
还是昨天的（CDN 有延迟）。这种情况下脚本**不记成功**，而是记一次退避 ——
否则今天这张就会被永久跳过、要等到明天。退避到期后重试，拿到今天的图才记成功。

**运维开关 `ARCHIVE_AUTO_UPDATE`**（`.env`，默认 `1` 开）：

```dotenv
# 关掉首访自动更新（cron 兜底不受影响，仍会继续跑）
ARCHIVE_AUTO_UPDATE=0
```

这是本站**唯一**一处「访客访问会触发对外请求」的功能，所以留一个不用重启 PHP 就能
关掉的口子。改成 `0` / `false` / `off` / `no` 都算关闭；只影响首访触发，
`tools/update-archive.php` 手动 / cron 调用**照常工作**。

部署包模板 `tools/deploy.env` 里这一行是**注释状态**（`# ARCHIVE_AUTO_UPDATE=0`），
所以新包的默认就是**开着**的 —— 想改新包的默认值就改模板，想改已上线那份就改服务器上的 `.env`。

### 7.3 配计划任务（每天 08:00，可选兜底）

**如果你愿意让 cron 兜底**（推荐 —— 覆盖「某天无人访问」的情况），
面板 → **计划任务** → 添加 → 任务类型选「Shell 脚本」，执行周期选「每天」，时间 `08:00`：

```bash
/www/server/php/82/bin/php /www/wwwroot/bing.hecady.com/tools/update-archive.php >> /www/wwwroot/bing.hecady.com/data/update-archive.log 2>&1
```

把两处路径改成本站的实际值。三个容易踩的点：

1. **别只写 `php`，要用绝对路径**。cron 的 `PATH` 极简，通常找不到 `php`，
   结果就是任务「执行成功」但什么都没发生（日志里 `php: command not found`）。
   绝对路径在面板的 PHP 设置里能看到，一般形如 `/www/server/php/82/bin/php`。
2. **用 root 跑**（面板默认）就**不需要给 `dataset/` 额外授权** —— root 本来就能写，
   而 Web 用户 www 只需要读。这样能保住第 3.5 节「只给 `data/` 授权、不扩大可写面」的做法。
   如果你偏要用 www 跑，那得 `chown -R www:www dataset`。
3. **`data/` 必须对执行用户可写**（放锁文件、日志与标记文件）。第 3.5 节已经做过这件事。
   首访自动更新也依赖 `data/` 可写 —— 两个路径共用同一个目录。

> 注意：cron 那条命令**不需要**为「首访已经更新过」做特殊处理。它跑起来后看到
> `data/archive-update.date` 已经是今天，就会报告「新增 0 条」并退出 0 —— 这是**正常且期望**的。

### 7.4 手动补跑 / 核对

```bash
cd /www/wwwroot/bing.hecady.com

# 先看会写什么，不落库
php tools/update-archive.php --dry-run

# 真跑
php tools/update-archive.php

# 看历史日志（每次一行摘要，方便 grep）
tail -n 20 data/update-archive.log
grep 更新 data/update-archive.log 2>/dev/null

# 首访自动更新的「今天已成功」标记（有内容且等于今天，说明今日已补过）
cat data/archive-update.date

# 失败退避标记：只有存在时才说明最近一次失败并正在退避
cat data/archive-update.failed 2>/dev/null
```

正常输出长这样：

```
[2026-09-21 08:00:01] 数据集：.../dataset/bing_wallpapers.db
[2026-09-21 08:00:02] 抓到 8 条
[2026-09-21 08:00:02] 抓到 8 条 / 新增 1 条 / 跳过 7 条
[2026-09-21 08:00:02] 当前库：共 3853 行，日期范围 2016-03-05 ~ 2026-09-21
```

跑完可以顺手确认线上确实生效：

```bash
curl -s $BASE/api/archive/stats | head -c 200   # total 应比昨天 +1
# 壁纸库页第一张应当就是今天
```

想验证「首访自动更新」这条路径本身，服务器上这样复现（把成功标记改成昨天，
然后访问一次首页，几秒后看库与标记）：

```bash
cd /www/wwwroot/bing.hecady.com
echo "2026-01-01" > data/archive-update.date      # 伪装成今天还没更新
curl -s -o /dev/null -w "%{http_code}\n" $BASE/   # 期望 200，且秒回（不被更新拖慢）
sleep 5
cat data/archive-update.date                      # 期望变成今天
```

### 7.5 ⚠️ 本地跑 merge 重建库之后，必须补跑一次

`bingimages/merge_bing_wallpapers.py` 重建库时是**整表 DROP 再建**（`write_sqlite()`
先 unlink 旧文件），所以它产出的库里**不含**任何 `daily-api` 行 —— 每日更新写进去的
那几天会被抹掉。

- **服务器上没人跑 merge**，数据只由本脚本追加，所以线上不存在这个问题。
- **本地**在 `bingimages/` 下跑完 merge 之后，请再跑一次：
  `php tools/update-archive.php`，把 recent 的几天补回来，再 `npm run package` 出新包。
  （只跑首访路径也行，但会顺带打标记、不如直接用 CLI 干净。）
- 想知道库里有几天是每日补的：
  `sqlite3 bingimages/bing_wallpapers.db "SELECT COUNT(*) FROM bing_wallpapers WHERE sources='daily-api'"`


## 8. 上线后验收清单

```bash
BASE=https://bing.hecady.com
curl -s -o /dev/null -w "%{http_code} " $BASE/                 # 期望 200
curl -s -o /dev/null -w "%{http_code} " $BASE/archive          # 期望 200
curl -s -o /dev/null -w "%{http_code} " $BASE/docs             # 期望 200
curl -s -o /dev/null -w "%{http_code} " $BASE/api/bing/today   # 期望 200
curl -s -o /dev/null -w "%{http_code} " $BASE/sitemap.xml      # 期望 200
curl -s -o /dev/null -w "%{http_code} " $BASE/robots.txt       # 期望 200
curl -s $BASE/api/bing/today | head -c 200                     # 应看到中文标题，不是 ???

# 静态资源（取一个真实存在的构建产物文件名）
ASSET=$(ls public/assets | grep '^index-.*\.js$' | head -1)
curl -s -o /dev/null -w "%{http_code} " $BASE/assets/$ASSET    # 期望 200
curl -s -o /dev/null -w "%{http_code} " $BASE/fonts/fonts.css  # 期望 200
curl -s -o /dev/null -w "%{http_code} " $BASE/og-image.png     # 期望 200

# Web 根之外的东西必须拿不到（结构正常时它们天然不可达）
curl -s -o /dev/null -w "%{http_code} " $BASE/app/Config.php          # 期望 404
curl -s -o /dev/null -w "%{http_code} " $BASE/data/bing-wallpapers.json  # 期望 404
```

再核对两条：

```bash
# 生产模式下 CORS 应已收紧：带外部 Origin 请求，响应里不应出现 Access-Control-Allow-Origin
curl -s -D - -o /dev/null -H "Origin: https://evil.example.com" $BASE/api/bing/today | grep -i access-control
# 应无输出
```

- 浏览器打开站点，按 **F12 → 网络**，刷新一次：**不应该有任何 404**。
- 用**手机浏览器**打开一次：窄屏下导航只保留站内入口，确认「壁纸库」可点。
- **壁纸库每日更新**：`data/archive-update.date` 里的日期是不是今天？是的话，
  再手动跑一次 CLI 并把结果对照本文第 7.4 节：

  ```bash
  cd /www/wwwroot/bing.hecady.com
  cat data/archive-update.date           # 今日已由首访补过的话，这里就是今天
  php tools/update-archive.php          # 期望「新增 0 条」（幂等，退出码 0）
  echo $?                               # 期望 0
  ```

  若想真跑一次写入，先把标记改成过去日期再执行 `php tools/update-archive.php`，
  或直接走首访路径（见 7.4 末尾的复现步骤）。

## 9. 常见问题

| 现象 | 原因 |
|---|---|
| 打开站点 403 / 404，看不到应用 | **「运行目录」没设成 `/public`**，见 3.3 |
| `/` 正常，`/archive`、`/docs`、`/api/*` 全 404 | 伪静态没配，见 3.4 |
| 页面骨架出来了但 JS/CSS/字体全 404 | 同样是「运行目录」没设对（静态文件不在 Web 根下），见 3.3 |
| 整站 500 | PHP 版本低于 8.2；或 `display_errors` 被打开导致输出破坏 JSON |
| 首页正常，`/api/*` 全 500，日志 `unable to get local issuer certificate` | 未配 CA 证书（Windows 环境，见第 5 节） |
| 「壁纸库」提示暂不可用 / 接口 503 | 未装 `pdo_sqlite`，或数据集路径不对 / 被 open_basedir 挡住，见第 6 节 |
| 所有访客共用同一个限流额度，很快就 429 | `TRUST_PROXY` 与实际代理层数不符，见 3.6 |
| 归档里部分年份的图不显示 | CSP 的 `img-src` 漏了那个年代的图床域名（Bing 图床换过三次），由 `app/Response.php` 统一发送 |
| 上传/解压后 403 | 文件属主或权限不对，见 3.5 |
| 计划任务显示「成功」但壁纸库一直不更新 | 任务里只写了 `php`，cron 的 `PATH` 找不到它 → 用 PHP 绝对路径，见 7.3 |
| `data/` 不可写 | 首访自动更新会**直接放弃自动触发**（避免每次访问都白试），cron 也会失败。给 `data/` 写权限，见 3.5 / 7.3 |
| 更新脚本报「数据集不可写」 | 执行用户对 `dataset/` 没有写权限。用 root 跑，或 `chown -R www:www dataset`，见 7.3 |
| 壁纸库比实际日期旧一天 | 当天既无访客（cron 也没配）、或正在失败退避（看 `data/archive-update.failed`）；或本地跑过 merge 重建把 `daily-api` 行抹了，见 7.4 / 7.5 |
| 首访没有触发更新 | 今天已有成功标记；或处于失败退避的 10 分钟内；或 `ARCHIVE_AUTO_UPDATE` 被设成了 0，见 7.2 |
| 想让访客**永远不**触发对外请求 | `.env` 里设 `ARCHIVE_AUTO_UPDATE=0` 并保留 08:00 计划任务即可，见 7.2 |
| 新补的行没有 4K 按钮 | `ARCHIVE_IMAGE_BASE` 被改成了非 `cn.bing.com`。该域名下 Bing 才有 4K，脚本会直接拒绝写库并报错，见 `app/Config.php` |

> 关于 `/app/...` 返回 404 而不是 403：这是**正常的**。
> `app/` 不在 Web 根里，服务器根本不知道有这个路径，所以返回「找不到」。
> 如果它返回了 200，说明「运行目录」设错了（Web 根被指到了项目根）。
