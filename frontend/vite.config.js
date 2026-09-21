import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { fileURLToPath, URL } from 'node:url'

// https://vitejs.dev/config/
export default defineConfig({
  plugins: [vue()],

  // 构建输入资产：字体、图标、分享图。
  // 刻意不叫 public/ —— 项目根已经有一个 public/（Web 根），两个同名目录极易搞混。
  publicDir: 'static',

  build: {
    // 构建产物**直接输出到项目根的 public/**，也就是 Web 根（宝塔「运行目录」指向它）。
    //
    // 这是整个结构的关键：让 URL 路径与物理路径一致 ——
    //   /assets/index-xxx.js  →  public/assets/index-xxx.js
    //   /fonts/DMSans.woff2   →  public/fonts/DMSans.woff2
    // 于是 nginx 用它自带的扩展名规则就能直接发这些文件，
    // 不需要 `^~` 覆盖、不需要 try_files 回退、不需要任何特殊伪静态。
    outDir: '../public',

    // outDir 在 Vite root 之外，而且 public/ 里还住着入口 index.php，
    // 不能让 Vite 清空该目录。
    // 清理交给 tools/prebuild.mjs（只清构建产物，保留 index.php 与 .htaccess）。
    emptyOutDir: false,
  },

  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url))
    }
  },
  server: {
    port: 5173,
    proxy: {
      '/api': {
        target: 'http://localhost:2665',
        changeOrigin: true
      }
    }
  }
})
