/**
 * 路由表。
 *
 * 为什么这时才引入 vue-router：本项目原本刻意不带路由（只有一个页面，
 * 状态切换用不着它）。现在多了「壁纸库」这个独立页面，需要可分享的 URL、
 * 浏览器前进后退、以及刷新后停在原地 —— 这些自己糊一个 history 监听也能做，
 * 但既然需求已经成立，用标准方案更稳。
 *
 * 版本上锁在 vue-router 4.x：5.x 的 peerOptional 要求 Vite 7/8，
 * 与本项目锁定的 Vite 5 冲突（实测会 ERESOLVE 装不上）。
 *
 * 三个视图都用动态 import → 各打成独立 chunk，
 * 访问展示页时不会把壁纸库、接口文档的代码一并下载。
 */

import { createRouter, createWebHistory } from 'vue-router'

const routes = [
  {
    path: '/',
    name: 'showcase',
    component: () => import('./views/showcase.vue'),
    meta: { title: 'BingWallpaper · Bing 每日壁纸 · 暗夜画廊' },
  },
  {
    path: '/archive',
    name: 'archive',
    component: () => import('./views/archive.vue'),
    meta: { title: '壁纸库 · BingWallpaper' },
  },
  {
    // ⚠️ 路径必须是 /api-docs，**不能**用 /docs。
    //
    // 原因：PHP 入口在 SPA 回退之前就把 /docs 显式处理掉了（直接吐
    // app/views/docs.html），所以 SPA 路由里的 /docs 永远抢不到。
    //
    // 另一头同样有个坑：PHP 里判断接口命名空间如果写成
    // str_starts_with($path, '/api')，/api-docs 会被误判成接口而 404 ——
    // 那边已经收紧成「正好 /api 或 /api/ 开头」，两处是一对，改一处要看另一处。
    //
    // 视图内部用 iframe 内嵌 /docs?embed=1，因此文档正文仍然只有一份
    // （app/views/docs.html），这里不放任何拷贝。
    path: '/api-docs',
    name: 'api-docs',
    component: () => import('./views/docs.vue'),
    meta: { title: '接口文档 · BingWallpaper' },
  },
  // 未匹配的路径回首页（后端也配了 SPA 回退，双保险）
  { path: '/:pathMatch(.*)*', redirect: '/' },
]

export const router = createRouter({
  history: createWebHistory(),
  routes,
  // 切页回到顶部；锚点/后退恢复留空即可（本应用是列表页，不需要滚动记忆）
  scrollBehavior() {
    return { top: 0 }
  },
})

router.afterEach((to) => {
  const title = to.meta?.title
  if (typeof title === 'string' && title !== '') {
    document.title = title
  }
})
