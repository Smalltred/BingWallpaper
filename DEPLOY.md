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
│   └── views/docs.html      接口文档正文
├── dataset/                 壁纸库数据集 —— 已随包内置，只读，Web 根之外
│   └── bing_wallpapers.db   SQLite，约 1.8 MB
├── data/                    运行时数据（Bing 缓存、限流计数）—— 需给 Web 用户可写
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

其余可调项（`CORS_ORIGIN`、`BING_CACHE_TTL`、`API_RATE_LIMIT_*`、`APP_DEBUG` 等）
完整说明在 `.env.example` 里 —— 那份是**全量选项清单**，保留在包内供查询，
但**不要**直接拿它覆盖 `.env`（它默认是开发环境的值）。


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
   `../bingimages/bing_wallpapers.db` 打进新包；
2. 只替换数据文件：把新的 `bing_wallpapers.db` 覆盖到站点根的 `dataset/` 下即可，
   **不需要改 .env，也不需要重启 PHP**。

> 若把数据集放到站点目录之外（如 `/www/datasets/bingimages`），open_basedir 会拒绝访问，
> 症状是接口 503 —— 那就得在面板里把该路径加进 open_basedir 白名单，并把 `.env` 的
> `BINGIMAGES_DIR` 改成对应的绝对路径。

数据集缺失或路径不对时：`/archive` 页面仍能打开并提示「暂不可用」，相关接口返回 **503**，
**今日壁纸、接口文档等其它功能完全不受影响**。后端以只读方式打开该库
（`PRAGMA query_only = 1`），不会写入数据集 —— 这一点在代码层面保证，即使权限给多了也一样。


## 7. 上线后验收清单

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

## 8. 常见问题

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

> 关于 `/app/...` 返回 404 而不是 403：这是**正常的**。
> `app/` 不在 Web 根里，服务器根本不知道有这个路径，所以返回「找不到」。
> 如果它返回了 200，说明「运行目录」设错了（Web 根被指到了项目根）。
