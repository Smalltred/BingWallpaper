<script setup>
/**
 * NavBar.vue — 全站共享导航。
 *
 * 抽成组件的理由：现在有两个页面（沉浸展示页 / 壁纸库），
 * 导航如果各写一份，样式和链接列表必然漂移 ——
 * 这个项目已经因为「两份手抄的 design token」出过一次问题，不再重复。
 *
 * 页面专属的操作按钮（如展示页的「沉浸 / 画廊」切换）通过默认插槽传入。
 * 注意：插槽内容在父组件作用域编译，所以其样式写在父组件的 <style scoped> 里即可生效。
 */

defineProps({
  /** 滚动时上滑隐藏 —— 由持有滚动容器的页面决定，见 showcase.vue */
  hidden: { type: Boolean, default: false },
})

/** 站内页面用 RouterLink，站外资源用普通 a + target=_blank */
const externalLinks = [
  { href: 'https://www.bing.com', label: '数据来源' },
  { href: 'https://github.com/Smalltred/BingWallpaper', label: '开源地址' },
  { href: 'https://www.hecady.com', label: '我的博客' },
]
</script>

<template>
  <nav class="nav" :class="{ hidden }">
    <RouterLink to="/" class="nav-brand">
      <span class="nav-brand-dot" />
      <h1>BingWallpaper</h1>
    </RouterLink>

    <ul class="nav-links">
      <li>
        <RouterLink to="/archive" class="nav-link">壁纸库</RouterLink>
      </li>
      <li>
        <a href="/docs" target="_blank" rel="noopener" class="nav-link">接口文档</a>
      </li>
      <li v-for="link in externalLinks" :key="link.href" class="is-external">
        <a :href="link.href" target="_blank" rel="noopener" class="nav-link">{{ link.label }}</a>
      </li>
    </ul>

    <div class="nav-actions">
      <slot />
    </div>
  </nav>
</template>

<style scoped>
.nav {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  height: var(--nav-height);
  z-index: 1000;
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 2.5vw;
  background: rgba(10, 10, 12, 0.72);
  backdrop-filter: blur(20px) saturate(1.5);
  -webkit-backdrop-filter: blur(20px) saturate(1.5);
  border-bottom: 1px solid var(--border);
  transition: transform var(--transition), opacity var(--transition);
}

.nav.hidden {
  transform: translateY(-100%);
  opacity: 0;
}

.nav-brand {
  display: flex;
  align-items: center;
  gap: 12px;
  font-family: var(--font-display);
  font-size: 1.35rem;
  font-weight: 700;
  letter-spacing: -0.02em;
  color: var(--text-primary);
  text-decoration: none;
  flex-shrink: 0;
}

.nav-brand h1 {
  font-size: inherit;
  font-weight: inherit;
  margin: 0;
}

.nav-brand-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: var(--accent);
  box-shadow: 0 0 12px rgba(212, 168, 83, 0.6);
  animation: pulse 3s ease-in-out infinite;
}

@keyframes pulse {
  0%, 100% { opacity: 1; }
  50% { opacity: 0.5; }
}

.nav-links {
  display: flex;
  align-items: center;
  gap: 2px;
  list-style: none;
}

.nav-link {
  display: block;
  padding: 8px 16px;
  font-size: 0.875rem;
  font-weight: 500;
  color: var(--text-secondary);
  border-radius: var(--radius-sm);
  text-decoration: none;
  transition: color var(--transition-fast), background var(--transition-fast);
}

.nav-link:hover {
  color: var(--text-primary);
  background: var(--surface-hover);
}

/* 当前所在页面：用琥珀色 + 软底，避免「点了没反应」的迷惑 */
.nav-link.router-link-exact-active {
  color: var(--accent);
  background: var(--accent-soft);
}

.nav-actions {
  display: flex;
  align-items: center;
  gap: 12px;
}

@media (max-width: 768px) {
  .nav {
    padding: 0 4vw;
    height: 56px;
  }
  .nav-brand {
    font-size: 1.1rem;
  }
  /* 手机端只保留站内入口。不能把整个 .nav-links 藏掉 ——
     那样「壁纸库」在手机上就完全进不去了。站外链接先让位。 */
  .nav-links li.is-external {
    display: none;
  }
  .nav-link {
    padding: 8px 10px;
    font-size: 0.8rem;
  }
}

@media (max-width: 480px) {
  .nav-brand {
    font-size: 0.95rem;
    gap: 8px;
  }
  .nav-link {
    padding: 8px 8px;
    font-size: 0.78rem;
  }
}
</style>
