/**
 * 构建前清理 public/ —— 只删构建产物，保留入口与规则文件。
 *
 * 为什么需要它：Vite 的 outDir 指向 ../public（项目根的 Web 根），
 * 因为 public/ 里还住着 index.php，不能用 Vite 自带的 emptyOutDir（会把入口一起删掉）。
 * 于是「清掉上一轮的产物」这件事得自己做，否则改名/删除的旧资源会一直留在包里。
 *
 * 做法是**白名单保留**而不是逐个列举要删的东西 ——
 * 列举法会在新增构建产物类型时悄悄漏掉，白名单不会。
 */

import { readdirSync, rmSync } from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const PROJECT_ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')
const PUBLIC_DIR = path.join(PROJECT_ROOT, 'public')

/** 这些是「手工维护的文件」，不属于构建产物，绝不能删 */
const KEEP = new Set(['index.php', '.htaccess', '.gitkeep'])

const removed = []
for (const name of readdirSync(PUBLIC_DIR)) {
  if (KEEP.has(name)) continue
  rmSync(path.join(PUBLIC_DIR, name), { recursive: true, force: true })
  removed.push(name)
}

if (removed.length > 0) {
  console.log(`[prebuild] 清理 public/ 旧产物: ${removed.join(', ')}`)
} else {
  console.log('[prebuild] public/ 无需清理')
}
