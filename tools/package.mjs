/**
 * 把「已编译的前端 + 后端 + 数据集 + 预置 .env」打成一个**上传即可访问**的 zip。
 *
 * 用法：
 *   npm run package          # = npm run build && node tools/package.mjs
 *
 * 布局是**平铺**的：包内容直接对应网站根目录，解开就能跑（宝塔 / 虚拟主机友好）。
 * 部署方除了「运行目录设为 public」和「粘一行伪静态」，不需要再做任何配置。
 *
 * 为什么用 Node 而不是 Python（项目里另外两个 tools 脚本是 Python 的）：
 *   `npm run package` 的定位是「构建流水线的一环」，它只应该依赖构建前端
 *   已经必需的东西 —— Node。而本机（以及很多服务器）`python` 并不在 PATH 上，
 *   用 Python 会让这条命令在没配 PATH 的机器上直接失败。
 *
 * 为什么自己写 ZIP 而不是引第三方库：
 *   ZIP 是稳定格式，这里只需要 store/deflate 两种情形，用内置 zlib 就能写完。
 *   为了打个包引入 archiver 之类的依赖，对一个「零依赖」项目不划算。
 *   产物用另一套实现（Python 的 zipfile）交叉验证过。
 */

import { existsSync, readdirSync, readFileSync, statSync, unlinkSync, writeFileSync } from 'node:fs'
import { createHash } from 'node:crypto'
import { deflateRawSync, crc32 } from 'node:zlib'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const PROJECT_ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')
const OUT_ZIP = path.join(path.dirname(PROJECT_ROOT), 'bingwallpaper-deploy.zip')

/**
 * 壁纸库数据集：由 bingimages 项目独立产出，这里**只读**引用。
 *
 * 之前刻意不进包（怕两份漂移），代价是部署方必须手工把 db 传上去、还得自己写
 * BINGIMAGES_DIR —— 一步漏了就只坏「壁纸库」一个页面，排查成本很高。
 * 现在改成打进包里：漂移问题依然不存在（包是发布物，打进去的是那一刻的快照，
 * 线上跑的 db 由发布流程决定），而部署变成零配置。
 * 仓库里仍然**不放**这份数据 —— 它只存在于部署包和线上。
 */
const DATASET_SRC = path.resolve(PROJECT_ROOT, '..', 'bingimages', 'bing_wallpapers.db')
const DATASET_ARCNAME = 'dataset/bing_wallpapers.db'

/** 预置 .env 的模板。放在 tools/ 下而不是仓库根：根目录的 .env 是本地开发配置，两者不能混。 */
const ENV_TEMPLATE = path.join(PROJECT_ROOT, 'tools', 'deploy.env')
/** 包内 .env 的位置 —— 站点根，Config::bootstrap() 正是从这个位置读的 */
const ENV_ARCNAME = '.env'

/**
 * 包内要带的东西：源相对路径 → 包内相对路径。
 *
 * 布局就是本项目自身的目录结构（标准 PHP 结构）：
 * 解压到网站目录后，把「运行目录」指向 /public 即可 —— 不需要挪任何文件。
 */
const INCLUDE = [
  // Web 根：入口 index.php + 构建产物（宝塔「运行目录」指向它）
  ['public', 'public'],
  // 应用代码与视图 —— 在 Web 根之外，HTTP 不可达，所以不需要任何拒绝规则
  ['app', 'app'],
  // 部署样例（nginx 完整配置、php.ini）
  ['deploy', 'deploy'],
  // 配置示例与重写规则
  ['.env.example', '.env.example'],
  ['nginx-rewrite.conf', 'nginx-rewrite.conf'],
  // 运行时数据目录：只带一个占位文件，让目录在服务器上存在、便于授权
  ['data/.gitkeep', 'data/.gitkeep'],
  // 部署说明
  ['DEPLOY.md', 'DEPLOY.md'],
]

/** 即便在 include 目录里也要跳过的 */
const EXCLUDE_DIRS = new Set(['node_modules', '__pycache__', '.git', 'data'])
const EXCLUDE_FILES = new Set(['.DS_Store', 'Thumbs.db'])
const EXCLUDE_SUFFIXES = ['.pyc', '.log', '.tmp']

// ============================================================
//  收集文件
// ============================================================

function collect(absPath, out) {
  const st = statSync(absPath)

  if (st.isFile()) {
    const name = path.basename(absPath)
    if (EXCLUDE_FILES.has(name) || EXCLUDE_SUFFIXES.some((s) => name.endsWith(s))) return
    out.push(absPath)
    return
  }

  for (const entry of readdirSync(absPath).sort()) {
    if (EXCLUDE_DIRS.has(entry)) continue
    collect(path.join(absPath, entry), out)
  }
}

function buildFileList() {
  const files = []
  for (const [srcRel] of INCLUDE) {
    const abs = path.join(PROJECT_ROOT, srcRel)
    if (!existsSync(abs)) {
      throw new Error(`缺少必要文件/目录：${srcRel}`)
    }
    collect(abs, files)
  }
  return files
}

/**
 * 读数据集，并做几个「便宜但能挡住低级错误」的校验。
 *
 * 打错 db 的代价很隐蔽：站点照常起来、今日壁纸正常，只有壁纸库页打不开（503），
 * 很容易被当成 PHP 扩展没装。所以在这里就把明显不对的情况拦下来。
 */
function readDataset() {
  if (!existsSync(DATASET_SRC)) {
    throw new Error(
      `找不到壁纸库数据集：${DATASET_SRC}\n` +
        '  数据集由 ../bingimages 项目产出。缺它本包仍能构建，但壁纸库页会 503；\n' +
        '  若确实要打一个不含数据集的包，请显式注释掉 main() 里的 dataset 分支。'
    )
  }

  const data = readFileSync(DATASET_SRC)
  const header = data.subarray(0, 16).toString('latin1')
  if (!header.startsWith('SQLite format 3')) {
    throw new Error(`数据集不是合法的 SQLite 文件（头部为 ${JSON.stringify(header.slice(0, 16))}）`)
  }

  const sha = createHash('sha256').update(data).digest('hex')
  return { data, sha, size: data.length, mtime: statSync(DATASET_SRC).mtime }
}

/**
 * 从 tools/deploy.env 生成包内 .env。
 *
 * 为什么用模板文件而不是在这里拼字符串：配置是要给人看、给人改的，
 * 写成模板文件就能在 git 里 review，也避免「想改默认值还得翻构建脚本」。
 */
function buildEnv() {
  if (!existsSync(ENV_TEMPLATE)) {
    throw new Error(`缺少 .env 模板：${ENV_TEMPLATE}`)
  }

  const body = readFileSync(ENV_TEMPLATE, 'utf8')
  const banner = [
    '# ============================================================',
    '#  本文件由 tools/package.mjs 从 tools/deploy.env 生成，已随包预置。',
    '#  想改线上配置，直接编辑站点根目录下的这个 .env —— 改完不需要重启 PHP。',
    '#  优先级：真实环境变量 > .env > 代码默认值。',
    '# ============================================================',
    '',
    '',
  ].join('\n')

  return banner + body
}

// ============================================================
//  最小 ZIP writer
// ============================================================

/** JS Date → MS-DOS 时间/日期（ZIP 头部用的老格式，秒只有 2 秒精度） */
function dosDateTime(d) {
  const time = (d.getHours() << 11) | (d.getMinutes() << 5) | (d.getSeconds() >> 1)
  const date = ((d.getFullYear() - 1980) << 9) | ((d.getMonth() + 1) << 5) | d.getDate()
  return { time: time & 0xffff, date: date & 0xffff }
}

/**
 * @param {Array<{arcname: string, data: Buffer, mtime: Date}>} entries
 * @returns {Buffer}
 */
function buildZip(entries) {
  const locals = []
  const centrals = []
  let offset = 0

  for (const entry of entries) {
    // 文件名一律用 UTF-8，并置位 flag bit 11 声明这一点 ——
    // 否则解压端会按本地代码页解释，中文文件名会变乱码。
    const nameBytes = Buffer.from(entry.arcname, 'utf8')
    const raw = entry.data
    const deflated = deflateRawSync(raw, { level: 9 })
    // 压不动就直接存（小文件、已压缩过的内容很多都属于这种）
    const useDeflate = deflated.length < raw.length
    const body = useDeflate ? deflated : raw
    const method = useDeflate ? 8 : 0
    const crc = crc32(raw) >>> 0
    const { time, date } = dosDateTime(entry.mtime)

    const local = Buffer.alloc(30)
    local.writeUInt32LE(0x04034b50, 0) // 签名 PK\x03\x04
    local.writeUInt16LE(20, 4) // 解压所需版本
    local.writeUInt16LE(0x0800, 6) // flag: UTF-8 文件名
    local.writeUInt16LE(method, 8)
    local.writeUInt16LE(time, 10)
    local.writeUInt16LE(date, 12)
    local.writeUInt32LE(crc, 14)
    local.writeUInt32LE(body.length, 18)
    local.writeUInt32LE(raw.length, 22)
    local.writeUInt16LE(nameBytes.length, 26)
    local.writeUInt16LE(0, 28) // 无 extra field
    locals.push(local, nameBytes, body)

    const central = Buffer.alloc(46)
    central.writeUInt32LE(0x02014b50, 0) // 签名 PK\x01\x02
    central.writeUInt16LE(20, 4) // 创建版本
    central.writeUInt16LE(20, 6) // 解压所需版本
    central.writeUInt16LE(0x0800, 8)
    central.writeUInt16LE(method, 10)
    central.writeUInt16LE(time, 12)
    central.writeUInt16LE(date, 14)
    central.writeUInt32LE(crc, 16)
    central.writeUInt32LE(body.length, 20)
    central.writeUInt32LE(raw.length, 24)
    central.writeUInt16LE(nameBytes.length, 28)
    central.writeUInt16LE(0, 30) // extra
    central.writeUInt16LE(0, 32) // comment
    central.writeUInt16LE(0, 34) // 起始磁盘
    central.writeUInt16LE(0, 36) // 内部属性
    // 外部属性：普通文件 0644。
    // 注意必须有 >>> 0：JS 的 << 是**有符号** 32 位运算，
    // 0o100644 << 16 越过了 2^31，不加转换会变成负数，writeUInt32LE 直接抛 RangeError。
    central.writeUInt32LE((0o100644 << 16) >>> 0, 38)
    central.writeUInt32LE(offset, 42)
    centrals.push(central, nameBytes)

    offset += local.length + nameBytes.length + body.length
  }

  const centralBuf = Buffer.concat(centrals)
  const eocd = Buffer.alloc(22)
  eocd.writeUInt32LE(0x06054b50, 0) // 签名 PK\x05\x06
  eocd.writeUInt16LE(0, 4) // 本磁盘号
  eocd.writeUInt16LE(0, 6) // 中央目录起始磁盘
  eocd.writeUInt16LE(entries.length, 8)
  eocd.writeUInt16LE(entries.length, 10)
  eocd.writeUInt32LE(centralBuf.length, 12)
  eocd.writeUInt32LE(offset, 16) // 中央目录偏移
  eocd.writeUInt16LE(0, 20) // 注释长度

  return Buffer.concat([...locals, centralBuf, eocd])
}

// ============================================================
//  main
// ============================================================

function main() {
  const distIndex = path.join(PROJECT_ROOT, 'public', 'index.html')
  if (!existsSync(distIndex)) {
    console.error('！public/index.html 不存在，请先执行：npm run build')
    process.exit(1)
  }

  const files = buildFileList()

  const entries = files.map((abs) => {
    const rel = path.relative(PROJECT_ROOT, abs).split(path.sep).join('/')
    return {
      arcname: rel,
      data: readFileSync(abs),
      mtime: statSync(abs).mtime,
    }
  })

  // ---- 数据集：读自 ../bingimages，只读，进包不进仓库 ----
  const dataset = readDataset()
  entries.push({
    arcname: DATASET_ARCNAME,
    data: dataset.data,
    mtime: dataset.mtime,
  })

  // ---- 预置 .env：部署方不用再 cp .env.example .env ----
  entries.push({
    arcname: ENV_ARCNAME,
    data: Buffer.from(buildEnv(), 'utf8'),
    mtime: new Date(),
  })

  // 构建信息：线上排查「这包是哪一版」时最省事的一行
  const assetDir = path.join(PROJECT_ROOT, 'public', 'assets')
  const assets = existsSync(assetDir) ? readdirSync(assetDir).sort() : []
  const stamp = (d) => d.toLocaleString('zh-CN', { hour12: false })
  // 注意 +1：BUILD-INFO.txt 自己也是包内的一员，统计时要算上
  const totalFiles = entries.length + 1
  const buildInfo = [
    '# 构建信息（由 tools/package.mjs 生成）',
    `构建时间   : ${stamp(new Date())}`,
    `文件数     : ${totalFiles}`,
    '',
    '布局：标准 PHP 结构。',
    '      public/ 是 Web 根 —— 宝塔里把「运行目录」指向 /public。',
    '      app/ 是应用代码，在 Web 根之外，HTTP 不可达，因此无需任何拒绝规则。',
    '      data/ 是运行时数据（缓存、限流计数），需要给 Web 用户可写。',
    '      dataset/ 是壁纸库数据集，同样在 Web 根之外，只读。',
    '      nginx 环境需把 nginx-rewrite.conf 的内容粘到「伪静态」框里；',
    '      Apache 环境会自动读取 public/.htaccess。',
    '',
    '已预置（无需手工配置）：',
    `  .env                       APP_ENV=production / SITE_ORIGIN / BINGIMAGES_DIR=dataset / TRUST_PROXY=1`,
    `  ${DATASET_ARCNAME}   来源 ../bingimages/bing_wallpapers.db`,
    `                             大小 ${dataset.size.toLocaleString()} bytes`,
    `                             改动时间 ${stamp(dataset.mtime)}`,
    `                             sha256 ${dataset.sha.slice(0, 16)}`,
    '',
    '前端产物：',
    ...assets.map((n) => `  public/assets/${n}`),
    '',
    '运行要求：PHP 8.2+，扩展 curl / openssl / mbstring / fileinfo / pdo_sqlite',
    '部署步骤见 DEPLOY.md',
    '',
  ].join('\n')

  entries.push({
    arcname: 'BUILD-INFO.txt',
    data: Buffer.from(buildInfo, 'utf8'),
    mtime: new Date(),
  })

  if (existsSync(OUT_ZIP)) unlinkSync(OUT_ZIP)

  const zip = buildZip(entries)
  writeFileSync(OUT_ZIP, zip)

  const rawTotal = entries.reduce((n, e) => n + e.data.length, 0)
  const digest = createHash('sha256').update(zip).digest('hex').slice(0, 16)

  console.log(`已生成 ${OUT_ZIP}`)
  console.log(`  ${entries.length} 个文件，未压缩 ${rawTotal.toLocaleString()} bytes，压缩后 ${zip.length.toLocaleString()} bytes`)
  console.log(`  sha256(前16位) ${digest}`)
  console.log(`  数据集 ${(dataset.size / 1024 / 1024).toFixed(2)} MB (${dataset.sha.slice(0, 16)}) → ${DATASET_ARCNAME}`)
  console.log('\n包内顶层（对应网站根目录）：')
  const tops = new Set(entries.map((e) => e.arcname.split('/')[0]))
  for (const t of [...tops].sort()) console.log(`  ${t}`)
}

main()
