# BingWallpaper

Bing 每日壁纸展示。前端 Vue 3 SPA，后端 **PHP 8.2**（零第三方依赖，不需要数据库服务 ——
壁纸库读的是一个随部署包分发的 SQLite 文件）。

## 环境要求

| 组件 | 版本 | 说明 |
|---|---|---|
| PHP | **8.2+** | 用到 `never` 返回类型、只读属性、`str_starts_with` 等 8.1/8.2 特性 |
| Node.js | 18+ | **仅用于构建前端**，后端不依赖 Node |
| PHP 扩展 | curl、openssl、mbstring、fileinfo、**pdo_sqlite** | curl 缺失时可回退到 stream 通道；openssl 两条通道都需要；pdo_sqlite 用于读壁纸库数据集 |
| `bingimages` 数据集 | 可选 | 不接入时站点照常运行，仅「壁纸库」页不可用 |

不需要 Composer —— 项目自带一个十行的 PSR-4 加载器。`composer.json` 只是元信息，
存在 `vendor/autoload.php` 时会优先使用它。

Windows 上装 PHP 有两个必配项，漏掉会直接导致接口 500，详见
[PHP 环境配置](#php-环境配置windows-必读)。

## 项目结构

**标准 PHP 结构**：`public/` 是 Web 根，应用代码在它外面的 `app/`。

这么排不是审美偏好，而是为了让 **URL 路径与物理路径一致**：
`/assets/index-x.js` ↔ `public/assets/index-x.js`、`/fonts/DMSans.woff2` ↔ `public/fonts/DMSans.woff2`。
于是 nginx/Apache 用它自带的规则就能直接发静态文件 ——
不需要 `^~` 覆盖面板规则、不需要 try_files 回退、也不需要任何「拒绝访问内部目录」的配置，
因为 `app/` 压根不在 Web 根里，HTTP 天然够不着。

```
必应图片展示/                   ← 仓库根（= 工作区根）；线上 Web 根是 public/
├── public/                    ★ Web 根（宝塔「运行目录」指向它）
│   ├── index.php              前端控制器：所有请求的唯一入口
│   ├── index.html             SPA 外壳（构建产物）
│   ├── assets/                构建产物（文件名带内容指纹）
│   ├── fonts/                 自托管字体
│   ├── favicon*.* og-image.png
│   └── .htaccess              Apache 环境的重写规则
├── app/                       PHP 8.2 应用代码（Web 根之外，HTTP 不可达）
│   ├── Config.php             配置解析（.env / 环境变量 / 默认值）
│   ├── Request.php            请求读取 + 真实客户端 IP 解析
│   ├── Response.php           响应输出 + 安全头 + CORS
│   ├── Bing.php               Bing 代理业务（抓取 / 4K 重写 / 入参校验）
│   ├── Archive.php            壁纸库数据集访问（只读 SQLite + 搜索/筛选/分页）
│   ├── BingException.php      带 HTTP 状态码的领域异常
│   ├── Http.php               出站 HTTP（curl 优先，stream 回退）
│   ├── Cache.php              文件缓存（TTL + 原子写）
│   ├── RateLimiter.php        按 IP 的固定窗口限流（flock 保证并发正确）
│   ├── StaticFiles.php        静态文件服务（含目录穿越与 .php 防护）
│   ├── Log.php                日志出口（兼容 cli / cli-server / fpm 三种 SAPI）
│   └── views/docs.html        接口文档正文
├── data/                      运行时数据（Bing 缓存、限流计数）—— 需可写
├── deploy/                    nginx 完整配置样例、php.ini 样例
├── frontend/                  Vue 3 + Vite（源码；构建输出到 ../public）
│   ├── src/
│   │   ├── main.js · app.vue · router.js
│   │   ├── components/NavBar.vue   全站共享导航
│   │   ├── views/
│   │   │   ├── showcase.vue   今日壁纸（沉浸 / 画廊双模式）
│   │   │   └── archive.vue    壁纸库（历史归档）
│   │   └── styles/            Design Tokens + 全局样式
│   ├── static/                构建输入资产（刻意不叫 public/，避免与 Web 根混淆）
│   │   ├── fonts/             自托管字体（woff2 + fonts.css）
│   │   ├── og-image.png       社交分享大图（1200×630）
│   │   └── favicon*
│   └── index.html · vite.config.js · package.json
├── tools/
│   ├── serve.php              PHP 内置服务器启动器
│   ├── prebuild.mjs           构建前清理 public/ 旧产物（保留入口与 .htaccess）
│   ├── package.mjs            打包部署包
│   ├── lint-php.php           对 app/ 与 public/ 全量 php -l
│   ├── fetch-fonts.py         生成自托管字体
│   └── make-og-image.py       生成 og-image.png
├── bingimages/                壁纸库数据集源（不进仓库；打包时**只读**读入 → 包内 dataset/）
├── nginx-rewrite.conf         宝塔「伪静态」粘贴用
├── DEPLOY.md                  部署说明（随部署包分发）
├── .env.example
├── composer.json
└── package.json               前端构建与本地启动命令
```

## 快速开始

```bash
# 1) 构建前端（需要 Node）—— 产物会直接落到 public/
npm run install:all
npm run build

# 2) 配置
cp .env.example .env
cp deploy/php.ini.example <你的 PHP 安装目录>/php.ini   # 首次装 PHP 才需要

# 3) 启动（Web 根是 public/）
npm start                 # 等价于 php tools/serve.php
```

打开 http://127.0.0.1:2665

`public/` 里还没有构建产物时服务照样能起：`/docs`、`/fonts`、`/og-image.png` 都可用，
只是首页会返回 404 —— 因为 SPA 产物确实还没有。此时会打印提示让你先 build。

### 开发模式（双进程）

```bash
php tools/serve.php              # 终端 1：后端 :2665
npm run dev:frontend             # 终端 2：Vite dev server :5173，/api 代理到 2665
```

前端改完刷新即可；后端是纯 PHP，改完也即时生效（不需要重启，除非改了 `php.ini`）。

> `npm run build` 会先跑 `tools/prebuild.mjs` 清掉 `public/` 里的旧产物再构建。
> 这是必需的：Vite 的 `outDir` 指向 `public/` 而那里还住着入口 `index.php`，
> 所以不能用 Vite 自带的 `emptyOutDir`（会把入口一起删掉）。

## 接口（全部公开，无需鉴权）

| 路径 | 用途 |
|---|---|
| `GET /api/bing/today` | 1080p URL 的壁纸数据（实测约 410 KB/张） |
| `GET /api/bing/today-4k` | 4K URL 的同一份数据（实测约 1.5 MB/张） |
| `GET /api/bing/image/:resolution?id=X` | 302 重定向到 Bing CDN（宽 320–3840 / 高 240–2160） |
| `GET /api/archive/stats` | 壁纸库统计：总量 / 日期范围 / 每年条数 |
| `GET /api/archive/wallpapers?page=&size=&keyword=&year=` | 壁纸库分页查询（关键词 + 年份筛选） |
| `GET /docs` | 接口文档 HTML |
| `GET /` | Vue SPA — 今日壁纸（沉浸 + 画廊双模式） |
| `GET /archive` | Vue SPA — 壁纸库（历史归档） |
| `GET /sitemap.xml` · `GET /robots.txt` | SEO |

按 IP 限流 200 次/分钟，响应带 `RateLimit-Policy / Limit / Remaining / Reset` 四个标准头
（与 express-rate-limit 的 `standardHeaders` 输出一致）。

## 配置

`.env`（从 `.env.example` 复制）。优先级：**真实环境变量 > `.env` > 代码默认值**。

| 变量 | 默认 | 说明 |
|---|---|---|
| `APP_ENV` | `development` | `production` 时收紧 CORS、trust proxy 默认值生效、docs 缓存 |
| `APP_DEBUG` | 非生产为开 | 输出缓存命中、抓取条数等日志 |
| `HOST` | `127.0.0.1` | 内置服务器监听地址。**默认只绑本机**，对外提供需显式设 `0.0.0.0` |
| `PORT` | `2665` | 端口 |
| `SITE_ORIGIN` | `https://bing.hecady.com` | sitemap / robots 里的站点根地址 |
| `CORS_ORIGIN` | 开发 `*` / 生产**不下发** | 逗号分隔白名单。生产未配置时不发 CORS 头，浏览器按同源策略限制 |
| `TRUST_PROXY` | 生产 `1` / 开发 `false` | 反向代理层数。**反代部署必须正确设置**，直连部署设 `0` |
| `BING_CACHE_TTL` | `3600` | Bing 数据缓存秒数 |
| `API_RATE_LIMIT_WINDOW` / `_MAX` | `60` / `200` | 限流窗口与上限 |
| `BINGIMAGES_DIR` | `bingimages` | 壁纸库数据集目录（相对项目根或绝对路径） |
| `ARCHIVE_PAGE_SIZE` | `24` | 壁纸库每页条数（上限固定 100） |

## PHP 环境配置（Windows 必读）

从 Node 迁过来时，有两个坑**只会在 PHP 侧出现**，且都不报「缺少扩展」这么直白：

### 1. HTTPS 根证书

Windows 版 PHP **不自带 CA 根证书库**（Linux 发行版通常自动读 `/etc/ssl/certs`）。
不配置的话，所有 HTTPS 出站请求都会失败并报：

```
SSL certificate verify result: unable to get local issuer certificate (20)
```

表现是首页正常、`/docs` 正常，但 `/api/bing/today` 一律 500 —— 很容易误判成代码问题。

Node.js 自带 CA 库，所以这个问题是迁移引入的，不是原有缺陷。

修法：下载 <https://curl.se/ca/cacert.pem>，然后在 `php.ini` 里指定：

```ini
curl.cainfo    = "C:/path/to/cacert.pem"
openssl.cafile = "C:/path/to/cacert.pem"
```

### 2. 扩展与 `extension_dir`

```ini
extension_dir = "ext"
extension=curl
extension=openssl
extension=mbstring
extension=fileinfo
zend_extension=opcache     ; opcache 是 Zend 扩展，写成 extension= 会报错
```

注意 `json`、`filter`、`iconv`、`pcre`、`zlib` 自 PHP 8.0 起**已内置编译**，
不存在也不需要 `php_json.dll` 之类的文件。

用 `php --ini` 确认配置文件确实被加载；`npm run lint:php` 会顺带报出扩展缺失。

### 3. 关于 PHP 8.2 的生命周期

PHP 8.2 自 **2024-12-31 起已进入仅安全维护**，并将于 **2026-12-31 彻底 EOL**。
如果你是从零开始，建议直接用 8.4（安全维护到 2028-12-31）。

本项目的代码不需要修改即可跑在 8.3 / 8.4 上 —— 没有用任何会在新版被移除的特性。

## 部署

### 方式一：PHP 内置服务器（开发 / 小流量自用）

```bash
php tools/serve.php
```

单进程、串行处理请求，**不适合有并发压力的生产环境**。在 Linux 上可以用
`PHP_CLI_SERVER_WORKERS=4` 启动多个 worker 进程缓解（Windows 不支持该变量）。

### 方式二：nginx + php-fpm（推荐）

参考 `deploy/nginx.conf.example`。要点：

- 站点根（`root`）指向 `public/`
- 静态资源（`/assets/`、`/fonts/`、图标）由 nginx 直接发 —— 因为它们的 URL 与
  物理路径一致，不需要任何特殊规则。PHP 只在路径不是真实文件时才被触发
- **必须**让反代用**覆盖**写法传客户端 IP，否则客户端可以伪造 `X-Forwarded-For`：

  ```nginx
  fastcgi_param HTTP_X_FORWARDED_FOR $remote_addr;   # 注意不是 $proxy_add_x_forwarded_for
  ```

  如果用的是追加写法，请把 `TRUST_PROXY` 设为 `0`。

## 分辨率策略（重要）

图片一律**浏览器直连 Bing CDN**，服务器只代理 JSON 元数据，图片字节不经过本服务。

| 场景 | 使用地址 | 实测体积 |
|---|---|---|
| 沉浸模式 / 画廊卡片 | 1080p（`srcFor()`） | 约 410 KB/张 |
| Lightbox 大图、下载 | 4K（`hdSrcFor()`） | 约 1.5 MB/张 |

**不要**把列表展示改成 4K：8 张图会从 3.2 MB 变成 12.3 MB。后端 `/api/bing/today-4k`
是留给外部 API 消费方的，前端不需要调它。

## 打包部署

```bash
npm run package          # = npm run build && node tools/package.mjs
```

产出项目根下的 `bingwallpaper-deploy.zip`（已被 `.gitignore` 忽略），内容是**编译好的前端 + PHP 后端 + 壁纸库数据集 + 预置 .env**，
布局就是本项目自身的目录结构：

```
（解压到网站目录）/
├── public/             ← Web 根，宝塔「运行目录」指向它
├── app/                应用代码（Web 根之外）
├── dataset/            壁纸库数据集（Web 根之外，只读）
├── data/               运行时数据（需可写）
├── deploy/             nginx / php.ini 样例
├── nginx-rewrite.conf  宝塔「伪静态」粘贴用
├── .env                已预置生产配置 —— 部署方不用再手工复制
├── .env.example        全量选项说明（供查询）
├── DEPLOY.md · BUILD-INFO.txt
```

宝塔部署四步：**装 PHP 8.2 + 勾上 `fileinfo`/`pdo_sqlite` → 上传解压 → 运行目录设为 `/public` → 粘伪静态（一行）**。
数据集与 `.env` 都随包内置，解压后壁纸库页直接可用。
完整步骤与排查表在 `DEPLOY.md`。

几个刻意的取舍：

- **不带前端源码、`tools/`、`node_modules`**。部署包里放源码只会让「线上跑的到底是哪一份」变含糊。
- **带壁纸库数据集，但仓库里不放**。数据集由 `bingimages/` 目录独立产出，打包时从
  `bingimages/bing_wallpapers.db` **只读**读入、以 `dataset/bing_wallpapers.db` 进包。
  包的定位是「发布物快照」，所以不存在漂移问题；而部署方因此拿到的是零配置的整包。
  仓库里仍然没有这份数据（`.gitignore` 同时挡了 `dataset/` 和 `bingimages/`）。
- **把构建产物放进 `public/` 而不是让 PHP 去映射**。这是这次结构调整的核心 ——
  早期的做法是「构建产物在一个独立目录、URL 却是根路径 `/assets/`」，两者对不上，
  于是要靠 `^~` 覆盖面板的正则规则 + try_files 回退才能跑通，非常脆
  （面板自带的 `location ~ .*\.(js|css|png)$` 会抢先截住请求，直接 404）。
  现在 URL 与物理路径一一对应，那些 hack 全都不需要了。
- **`.env` 模板放在 `tools/deploy.env` 并提交进仓库**。线上默认配置应当能被 review、
  能有历史，而不是藏在构建脚本的字符串里。注意它和根目录的 `.env`（本地开发配置）是两回事。

打包脚本用 Node 写（`tools/package.mjs`），不依赖 Python —— `npm run package` 的定位是构建流水线的一环，
只应该依赖构建前端已经必需的 Node。ZIP 由内置 `zlib` 直接写（格式简单，不值得为打包引第三方依赖），
产物用 Python 的 `zipfile` 做过交叉校验。

> 注意：`tools/fetch-fonts.py` 与 `tools/make-og-image.py` 是 Python 脚本（需要 Pillow），
> 走 `npm run tools:fonts` / `tools:og` 时需要 `python` 在 PATH 上。

## 壁纸库（历史归档）

`/archive` 页面展示 bingimages 数据集里的 3851 天历史壁纸（2016-03-05 至今，无日期缺口），
支持关键词搜索、年份筛选、无限滚动与详情弹窗。

### 数据集怎么接入

后端**只读引用**数据集。开发时从 `bingimages/` 读；线上则由部署包内置在 `dataset/` 下。

- 默认路径：项目根下的 `bingimages/`（它不在 Web 根内，HTTP 拿不到）
- 可用 `BINGIMAGES_DIR` 覆盖，支持相对项目根的路径或绝对路径
- 部署包里写的是 `BINGIMAGES_DIR=dataset`，即站点根下的 `dataset/` 目录
- 打开连接后立刻执行 `PRAGMA query_only = 1`，从连接层禁止任何写操作落到数据集上
- 数据集缺失时 `/archive` 与相关接口返回 **503** 并给出提示，**不影响** 今日壁纸与文档页

需要 PHP 的 **pdo_sqlite** 扩展（`php.ini.example` 里已列出）。

### 4K 可用性（不是所有图都有）

数据集的 url 是 1080p。实测结论：

| 图床 | 条数 | 能否升 4K |
|---|---|---|
| `cn.bing.com` | 2690 | ✅ 改 `w=`/`h=` 参数即可（实测 266 KB → 967 KB） |
| `cdn.bimg.cc`（2016–2018） | 920 | ❌ 改写文件名返回 404，Bing 侧没有 4K 版本 |
| `bing.com`（2018-09 ~ 2019-05） | 241 | ❌ 同上 |

所以后端把判断算好放在 `url_4k` 字段：可升的有值，不可升的为 `null`。
前端据此显示「4K 可用 / 1080p 原图」，不会给用户一个必定 404 的链接。

> 这三个域名也必须都在 CSP 的 `img-src` 白名单里，否则对应那批图会被浏览器直接拦掉 ——
> 而 curl 不执行 CSP，只有真浏览器验证才能发现。

### 前端为什么这时才加 vue-router

本项目原本刻意不带路由（只有一个页面，用不着）。多了「壁纸库」之后需要可分享的 URL、
浏览器前进后退、刷新后停在原地 —— 这些自己糊一个 history 监听也能做，但需求既然成立，
用标准方案更稳。

版本锁在 **vue-router 4.x**：5.x 的 `peerOptional` 要求 Vite 7/8，与本项目锁定的 Vite 5
冲突（实测 `npm install vue-router` 直接 ERESOLVE 装不上）。

两个视图都用动态 import，各自打成独立 chunk：只访问首页时不会把壁纸库的代码一起下载
（实测 archive chunk 4.4 KB gzip，首页加载不到它）。

## 字体与静态资源

字体自托管在 `frontend/static/fonts/`，由 `tools/fetch-fonts.py` 从 Google Fonts 拉取并生成
`fonts.css`。运行时由 PHP 从 `frontend/static/` 发送，**不依赖 `dist/`**，
因此前端未构建时 `/docs` 也能正常加载字体。

- 只取 latin 子集（合计约 89 KB）：正文是中文，CJK 字体动辄几 MB，统一回落系统字体栈。
- 可变字体（DM Sans / JetBrains Mono）在各字重回源是同一份文件，脚本按内容去重后
  每族只留一份，用 `font-weight` 区间声明。

```bash
pip install pillow
python tools/fetch-fonts.py     # 或 npm run tools:fonts
python tools/make-og-image.py   # 或 npm run tools:og
```

## 数据更新机制

- 文件缓存 1 小时（`data/bing-wallpapers.json`），过期后下次请求触发刷新
- Bing 自己每天换图（UTC 凌晨滚动），无需定时任务
- 抓取失败时**降级返回过期缓存**，而不是直接 500
- 新数据要等下次请求触发，或手动 `curl /api/bing/today`

## 安全

- 全部端点公开、无 Cookie、无鉴权，因此服务端**不下发** `Access-Control-Allow-Credentials`
- 生产环境未配置 `CORS_ORIGIN` 时不下发 CORS 头（注意：`cors` 包的 `origin: true` 是
  「反射任意来源」，并非同源限制，不要用它来收紧）
- 已设置 `X-Content-Type-Options` / `X-Frame-Options` / `Referrer-Policy` /
  `Permissions-Policy` / CSP
- 静态文件服务做了目录穿越防护：先拒 `..`，再用 `realpath` 确认最终路径仍在静态根内
  （只靠字符串过滤挡不住符号链接和编码绕过）
- `display_errors` 必须为 `Off`：这是纯 JSON API，任何 warning 被打印到输出里
  都会直接破坏 JSON 结构

## 已知限制

- 单机部署：缓存与限流都落本地文件。多实例共享 `data/` 时计数是共享的
  （若挂同一存储，反而能得到全局一致的限流）
- 内置服务器是单进程的，并发能力有限
- 没有定时任务：Bing 每天换图后需有访客才会刷到（缓存过期时）
- 图片直连 Bing CDN，可用性取决于 Bing 与访客网络；加载失败会自动经后端 302
  端点重试一次，仍失败则显示失败提示与重试按钮

## 相关文档

- `deploy/nginx.conf.example` — 生产部署配置
- `deploy/php.ini.example` — 推荐 php.ini
