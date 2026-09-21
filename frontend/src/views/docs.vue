<template>
  <!--
    docs.vue — 接口文档（SPA 内的第三个视图）。

    为什么要这一层：原先 NavBar 里的「接口文档」是 <a href="/docs" target="_blank">，
    点开是新标签页 + 一份 PHP 独立渲染的完整 HTML（自带 topbar），
    与站内的沉浸式体验完全割裂。现在改成站内路由 /api-docs，
    导航栏保持不动，正文用 iframe 内嵌 —— 切页无整页刷新。

    为什么用 iframe 而不是 fetch 回来注入 innerHTML：
    app/views/docs.html 是**自带全套样式与 design token 的独立文档**
    （它还有自己的 :root 变量、字体、代码块配色）。把它塞进 SPA 的 DOM，
    两套样式必然互相污染，而且以后谁改了哪一份都说不清。
    iframe 天然隔离样式与脚本，且内容**只有一份**（就是那个文件本身）。

    为什么 src 要带 ?embed=1：
    那会让后端给 <html> 注入 class="embed"，隐藏文档自带的 topbar 和底部
    「返回首页」—— 否则会出现「SPA 导航 + 文档 topbar」双头部。
    /docs 独立页照旧可用，不受影响（sitemap.xml 与外部 API 用户还靠它）。
  -->

  <NavBar />

  <main class="docs">
    <iframe
      class="docs-frame"
      src="/docs?embed=1"
      title="BingWallpaper 接口文档"
      loading="eager"
    />
  </main>
</template>

<script setup>
import NavBar from '../components/NavBar.vue'
</script>

<style scoped>
/*
 * 整高布局：iframe 高度 = 视口 − 导航高度。
 * 用 dvh 而非 vh，和 showcase / archive 的口径一致 ——
 * 移动端地址栏伸缩时 dvh 会跟着变，vh 不会（那样底部会露出一截）。
 * 64px 那个兜底是给不支持 dvh 的老浏览器，值与 --nav-height 一致。
 */
.docs {
  height: calc(100vh - var(--nav-height));
  height: calc(100dvh - var(--nav-height));
}

.docs-frame {
  display: block;
  width: 100%;
  height: 100%;
  border: 0;
  /* iframe 内的文档是深色画布（#0a0a0c），底色先铺上，
     避免加载瞬间闪一下白底 */
  background: var(--canvas);
}
</style>
