<template>
  <!--
    Showcase.vue — BingWallpaper 展示页
    功能：沉浸模式 / 画廊模式 / Lightbox / 下载 / 键盘导航
  -->
  <!-- ===== 错误状态 ===== -->
  <div class="error-state" :class="{ show: hasError }">
    <div class="error-icon">🌑</div>
    <div class="error-title">无法加载壁纸</div>
    <div class="error-msg">{{ errorMsg }}</div>
    <button class="error-retry" @click="retry">重新加载</button>
  </div>

  <!-- ===== 导航栏（全站共享组件；「沉浸 / 画廊」作为插槽传入）===== -->
  <NavBar :hidden="navHidden">
    <div class="mode-toggle" role="group" aria-label="浏览模式切换">
      <button
        class="mode-btn"
        :class="{ active: currentMode === 'immersive' }"
        @click="switchMode('immersive')"
        aria-label="沉浸模式"
      >
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <rect x="3" y="3" width="18" height="18" rx="2" /><line x1="3" y1="12" x2="21" y2="12" />
        </svg>
        <span>沉浸</span>
      </button>
      <button
        class="mode-btn"
        :class="{ active: currentMode === 'gallery' }"
        @click="switchMode('gallery')"
        aria-label="画廊模式"
      >
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <rect x="3" y="3" width="7" height="7" rx="1" />
          <rect x="14" y="3" width="7" height="7" rx="1" />
          <rect x="3" y="14" width="7" height="7" rx="1" />
          <rect x="14" y="14" width="7" height="7" rx="1" />
        </svg>
        <span>画廊</span>
      </button>
    </div>
  </NavBar>

  <!-- ===== 沉浸模式 ===== -->
  <div
    class="immersive"
    :class="{ hidden: currentMode !== 'immersive' }"
    ref="immersiveRef"
    @scroll="onImmersiveScroll"
    aria-label="沉浸式壁纸浏览"
  >
    <div
      v-for="(wp, i) in wallpapers"
      :key="'slide-' + i"
      class="slide"
      :class="{ active: currentSlide === i }"
      :ref="el => { if (el) slideRefs[i] = el }"
      role="tabpanel"
      :aria-label="'壁纸 ' + (i + 1) + ' of ' + wallpapers.length"
    >
      <div class="slide-img-wrap">
        <div class="slide-img-placeholder" :class="{ fade: imageLoaded[i] }">
          <div class="placeholder-shimmer" />
        </div>
        <img
          class="slide-img"
          :class="{ loaded: imageLoaded[i] }"
          :src="srcFor(i)"
          :alt="cleanText(wp.location || wp.title)"
          :loading="i < 2 ? 'eager' : 'lazy'"
          @load="onImageLoad(i)"
          @error="onImageError(i)"
        />
        <div v-if="imageFailed[i]" class="img-failed">
          <span class="img-failed-text">图片加载失败</span>
          <button class="img-failed-retry" @click.stop="retryImage(i)">重试</button>
        </div>
      </div>
      <div class="slide-meta">
        <div class="slide-info">
          <div class="slide-index">
            <span class="slide-index-line" />
            {{ String(i + 1).padStart(2, '0') }} / {{ String(wallpapers.length).padStart(2, '0') }}
          </div>
          <div class="slide-title">{{ cleanText(wp.location || wp.title) }}</div>
          <div class="slide-date">{{ formatDate(wp.date) }}</div>
        </div>
        <button
          class="slide-download"
          :class="{ downloading: downloadingIdx === i, done: doneIdx === i }"
          aria-label="下载壁纸"
          @click.stop="handleDownload(i)"
        >
          <!-- 默认图标 -->
          <svg v-if="downloadingIdx !== i && doneIdx !== i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
            <polyline points="7 10 12 15 17 10" />
            <line x1="12" y1="15" x2="12" y2="3" />
          </svg>
          <!-- 下载中 -->
          <div v-else-if="downloadingIdx === i" class="placeholder-shimmer download-spinner" />
          <!-- 完成 -->
          <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
            <polyline points="20 6 9 17 4 12" />
          </svg>
        </button>
      </div>
    </div>
  </div>

  <!-- ===== 画廊模式 ===== -->
  <section
    class="gallery"
    :class="{ active: currentMode === 'gallery' }"
    aria-label="壁纸画廊网格"
  >
    <div class="gallery-header">
      <h2 class="gallery-title">近期壁纸</h2>
      <p class="gallery-subtitle">Bing 每日精选 — 点击任意壁纸查看大图并下载</p>
    </div>
    <div class="gallery-grid">
      <div
        v-for="(wp, i) in wallpapers"
        :key="'card-' + i"
        class="gallery-card fade-in-up"
        :style="{ animationDelay: (i * 0.06) + 's' }"
        @click="openLightbox(i)"
      >
        <img class="gallery-card-img" :src="srcFor(i)" :alt="cleanText(wp.location || wp.title)" loading="lazy" />
        <div v-if="imageFailed[i]" class="img-failed img-failed--card">
          <span class="img-failed-text">加载失败</span>
        </div>
        <div class="gallery-card-overlay">
          <div class="gallery-card-title">{{ cleanText(wp.location || wp.title) }}</div>
          <div class="gallery-card-date">{{ formatDate(wp.date) }}</div>
        </div>
      </div>
    </div>
  </section>

  <!-- ===== 进度点 ===== -->
  <div
    v-show="currentMode === 'immersive'"
    class="progress-dots"
    role="tablist"
    aria-label="壁纸导航"
  >
    <button
      v-for="(_, i) in wallpapers"
      :key="'dot-' + i"
      class="dot"
      :class="{ active: currentSlide === i }"
      role="tab"
      :aria-label="'跳转到壁纸 ' + (i + 1)"
      :aria-selected="currentSlide === i ? 'true' : 'false'"
      @click="scrollToSlide(i)"
    />
  </div>

  <!-- ===== 滚动提示 ===== -->
  <div
    v-show="currentMode === 'immersive'"
    class="scroll-hint"
    :class="{ hidden: scrollHintHidden }"
  >
    <span>滚动浏览</span>
    <div class="scroll-hint-arrow" />
  </div>

  <!-- ===== Lightbox ===== -->
  <Teleport to="body">
    <div
      class="lightbox"
      :class="{ show: lightboxVisible }"
      ref="lightboxRef"
      role="dialog"
      aria-modal="true"
      aria-label="壁纸大图查看"
      @click.self="closeLightbox"
    >
      <button class="lightbox-close" ref="lightboxCloseRef" aria-label="关闭" @click="closeLightbox">&times;</button>
      <button class="lightbox-nav lightbox-prev" aria-label="上一张" @click="lightboxNavigate(-1)">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6" /></svg>
      </button>
      <button class="lightbox-nav lightbox-next" aria-label="下一张" @click="lightboxNavigate(1)">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6" /></svg>
      </button>
      <!-- 大图查看用 4K：这里才是真正需要原画质的地方 -->
      <img class="lightbox-img" :src="hdSrcFor(currentLightboxIndex)" :alt="cleanText(lightboxWp?.location || lightboxWp?.title)" />
      <div class="lightbox-info">
        <div class="lightbox-title">{{ cleanText(lightboxWp?.location || lightboxWp?.title) }}</div>
        <div class="lightbox-date">{{ formatDate(lightboxWp?.date) }}</div>
      </div>
      <button class="lightbox-download" aria-label="下载壁纸" @click="handleDownload(currentLightboxIndex)">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
          <polyline points="7 10 12 15 17 10" />
          <line x1="12" y1="15" x2="12" y2="3" />
        </svg>
      </button>
    </div>
  </Teleport>

  <!-- ===== 页脚 ===== -->
  <footer class="footer">
    <span>数据来源 &copy; Bing</span>
  </footer>
</template>

<script setup>
/**
 * Showcase.vue — Bing 每日壁纸展示页
 *
 * 功能：
 * - 沉浸模式：全屏 scroll-snap + 键盘导航 (J/K/↑↓)
 * - 画廊模式：响应式网格 + Lightbox
 * - 下载功能 + 反馈动画
 * - 图片渐进加载 (placeholder shimmer → 淡入)
 * - 导航栏自动隐藏
 * - Lightbox 焦点陷阱
 */

import { ref, reactive, computed, onMounted, onUnmounted, nextTick } from 'vue'
import NavBar from '../components/NavBar.vue'

// ===== 状态 =====
const wallpapers = ref([])
const currentMode = ref('immersive')
const currentSlide = ref(0)
const currentLightboxIndex = ref(0)
const lastFocusedElement = ref(null)
const imageLoaded = reactive({})
const navHidden = ref(false)
const scrollHintHidden = ref(false)
const hasError = ref(false)
const errorMsg = ref('网络请求失败，请稍后重试。')
const downloadingIdx = ref(-1)
const doneIdx = ref(-1)
const lightboxVisible = ref(false)
const lastScrollY = ref(0)
const imageFailed = reactive({})        // index -> 图片彻底加载失败
const imageSrcOverride = reactive({})   // index -> 失败后的替代地址（后端 302 端点 / 手动重试带时间戳）

// DOM 引用
const immersiveRef = ref(null)
const lightboxRef = ref(null)
const lightboxCloseRef = ref(null)
const slideRefs = reactive({})

// ===== 计算属性 =====
const lightboxWp = computed(() => {
  if (currentLightboxIndex.value >= 0 && currentLightboxIndex.value < wallpapers.value.length) {
    return wallpapers.value[currentLightboxIndex.value]
  }
  return null
})

// ===== 降级示例数据 =====
const FALLBACK_IMAGES = [
  { url: 'https://images.unsplash.com/photo-1506905925346-21bda4d32df4?w=3840&h=2160&fit=crop', date: '20260704', title: 'Mountain landscape', location: 'Dolomites, Italy' },
  { url: 'https://images.unsplash.com/photo-1470071459604-3b5ec3a7fe05?w=3840&h=2160&fit=crop', date: '20260703', title: 'Foggy forest', location: 'Black Forest, Germany' },
  { url: 'https://images.unsplash.com/photo-1447752875215-b2761acb3c5d?w=3840&h=2160&fit=crop', date: '20260702', title: 'Stream in forest', location: 'Olympic National Park, USA' },
  { url: 'https://images.unsplash.com/photo-1469474968028-56623f02e42e?w=3840&h=2160&fit=crop', date: '20260701', title: 'Mountain sunset', location: 'Mount Rainier, USA' },
  { url: 'https://images.unsplash.com/photo-1441974231531-c6227db76b6e?w=3840&h=2160&fit=crop', date: '20260630', title: 'Pine forest', location: 'Lake Bled, Slovenia' },
  { url: 'https://images.unsplash.com/photo-1501785888041-af3ef285b470?w=3840&h=2160&fit=crop', date: '20260629', title: 'Lake reflection', location: 'Banff, Canada' },
  { url: 'https://images.unsplash.com/photo-1475924156734-496f6cc8ec3c?w=3840&h=2160&fit=crop', date: '20260628', title: 'Rolling hills', location: 'Tuscany, Italy' },
  { url: 'https://images.unsplash.com/photo-1426604966848-d7adac402bff?w=3840&h=2160&fit=crop', date: '20260627', title: 'Valley vista', location: 'Glacier National Park, USA' }
]

// ===== 工具函数 =====

/** 格式化日期 YYYYMMDD → YYYY.MM.DD */
function formatDate(dateStr) {
  if (!dateStr || dateStr.length !== 8) return dateStr || ''
  return `${dateStr.slice(0, 4)}.${dateStr.slice(4, 6)}.${dateStr.slice(6, 8)}`
}

/** 清理文件名中的非法字符 */
function sanitizeFilename(name) {
  return (name || '').replace(/[<>:"/\\|?*]/g, '_').trim().slice(0, 100) || 'bing-wallpaper'
}

/**
 * 去除文本中的括号及其内容（Bing location 字段常含 "(© 作者/来源)" 版权标记）
 * 例："法国 (© Robert Harding/Shutterstock)" → "法国"
 *     "北京(故宫)" → "北京"
 *     "普通文本" → "普通文本"
 */
function cleanText(s) {
  if (!s) return ''
  return String(s).replace(/\s*\([^)]*\)/g, '').replace(/\s{2,}/g, ' ').trim()
}

/** 从 Bing 图片地址里取出图床 id（用于走本机 302 端点重试） */
function extractBingId(url) {
  const m = /[?&]id=([^&]+)/.exec(url || '')
  return m ? m[1] : ''
}

/**
 * 把图片地址重写为 4K（w=3840&h=2160）。
 *
 * 关键：本函数只用于「大图查看」和「下载」——列表与卡片展示一律走 srcFor() 拿到的
 * 1080p 地址。同一张图 1080p ≈ 410 KB、4K ≈ 1.53 MB（实测约 3.8 倍），
 * 8 张全上 4K 会让首屏从 3.2 MB 变成 12.3 MB。
 *
 * 为什么重写 URL 而不是另调 /api/bing/today-4k：
 *   1. 同一个 1080p URL 重写一次就行，零额外延迟。
 *   2. 后端 4K 端点（/api/bing/today-4k）保留给外部 API 消费方使用。
 *   3. Bing CDN 对同一资源的 ?w=3840&h=2160 会按需切到 4K JPG（实测 1.53 MB）。
 *
 * 非 Bing URL（如 Unsplash 降级数据）原样返回。
 */
function to4K(url) {
  if (!url) return ''
  // 本机 302 端点：解析出 id 后换成 4K 尺寸
  const internal = /^\/api\/bing\/image\/\d+x\d+\?id=([^&]+)/.exec(url)
  if (internal) return `/api/bing/image/3840x2160?id=${internal[1]}`
  if (/[?&]w=\d+/.test(url) && /[?&]h=\d+/.test(url)) {
    return url.replace(/([?&])w=\d+/, '$1w=3840').replace(/([?&])h=\d+/, '$1h=2160')
  }
  return url
}

/** 列表 / 卡片展示地址：1080p，体积小、加载快 */
function srcFor(index) {
  const wp = wallpapers.value[index]
  if (!wp) return ''
  return imageSrcOverride[index] || wp.url
}

/** 大图查看 / 下载地址：4K 原画质 */
function hdSrcFor(index) {
  return to4K(srcFor(index))
}

/** 带超时的 fetch */
function fetchWithTimeout(url, options = {}, timeout = 12000) {
  return Promise.race([
    fetch(url, options),
    new Promise((_, reject) => setTimeout(() => reject(new Error('timeout')), timeout))
  ])
}

// ===== 数据获取 =====
async function fetchWallpapers() {

  try {
    // 通过 Vite 代理请求后端 API
    const resp = await fetchWithTimeout('/api/bing/today', {}, 12000)
    if (resp.ok) {
      const data = await resp.json()

      // 后端实际格式：{ message: 'success', code: 200, data: [{url, date, title, location}] }
      if (data && data.code === 200 && Array.isArray(data.data) && data.data.length > 0) {
        // 字段映射：后端 location 含版权信息，兼容旧字段
        return data.data.map((item) => ({
          url: item.url,
          date: item.date,
          title: item.title || '',
          location: item.location || '',
          copyright: item.copyright || item.location || ''
        }))
      }

      // 兼容旧格式 { wallpapers: [...] }
      if (data.wallpapers && data.wallpapers.length > 0) {
        return data.wallpapers
      }

      // 兼容裸数组
      if (Array.isArray(data) && data.length > 0) {
        return data
      }
    }
    throw new Error('API returned empty data')
  } catch (e) {
    console.log('后端 API 失败，使用降级数据:', e.message)
    return FALLBACK_IMAGES
  }
}

// ===== 图片加载回调 =====
function onImageLoad(index) {
  imageFailed[index] = false
  imageLoaded[index] = true
}

/**
 * 图片加载失败：
 *   第 1 次失败 → 改用后端 302 端点重试一次（URL 更干净，可绕开 Bing 对直连的限流/404）
 *   第 2 次失败 → 标记失败，渲染失败提示并停掉 loading 骨架，避免 shimmer 永久旋转
 */
function onImageError(index) {
  const wp = wallpapers.value[index]
  if (!wp) return

  if (!imageSrcOverride[index]) {
    const id = extractBingId(wp.url)
    if (id) {
      imageSrcOverride[index] = `/api/bing/image/1920x1080?id=${encodeURIComponent(id)}`
      return
    }
  }

  imageFailed[index] = true
  imageLoaded[index] = true // 淡出占位层
}

/** 手动重试：带时间戳强制重新发起请求，否则浏览器会直接复用失败的缓存结果 */
function retryImage(index) {
  const wp = wallpapers.value[index]
  if (!wp) return
  imageFailed[index] = false
  imageLoaded[index] = false
  imageSrcOverride[index] = `${wp.url}${wp.url.includes('?') ? '&' : '?'}_r=${Date.now()}`
}

// ===== 下载功能 =====
async function handleDownload(index) {
  if (downloadingIdx.value !== -1 || doneIdx.value !== -1) return
  const wp = wallpapers.value[index]
  if (!wp) return

  const isSlideDownload = currentMode.value === 'immersive'
  const src = hdSrcFor(index)
  if (isSlideDownload) {
    downloadingIdx.value = index
  }

  try {
    const resp = await fetchWithTimeout(src, {}, 15000)
    const blob = await resp.blob()
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `${sanitizeFilename(cleanText(wp.location || wp.title))}_${wp.date}.jpg`
    document.body.appendChild(a)
    a.click()
    document.body.removeChild(a)
    URL.revokeObjectURL(url)

    if (isSlideDownload) {
      downloadingIdx.value = -1
      doneIdx.value = index
      setTimeout(() => { doneIdx.value = -1 }, 2000)
    }
  } catch {
    // 降级：在新标签页打开（Bing CDN 实测返回 Access-Control-Allow-Origin: *，正常走不到这里）
    window.open(src, '_blank')
    if (isSlideDownload) {
      downloadingIdx.value = -1
    }
  }
}

// ===== 模式切换 =====
function switchMode(mode) {
  currentMode.value = mode
  if (mode === 'immersive') {
    scrollHintHidden.value = currentSlide.value !== 0
  }
}

// ===== 沉浸模式滚动 =====

/**
 * slide 的实测高度。
 * 为什么不用 window.innerHeight：slide 高度是 CSS 100dvh，移动端地址栏伸缩时
 * innerHeight 与 dvh 不相等，会导致 currentSlide 计算漂移、进度点错位。
 */
function getSlideHeight() {
  const first = slideRefs[0]
  return (first && first.offsetHeight) || window.innerHeight
}

function onImmersiveScroll() {
  const el = immersiveRef.value
  if (!el) return

  const scrollTop = el.scrollTop
  const slideHeight = getSlideHeight()
  const newSlide = Math.round(scrollTop / slideHeight)

  if (newSlide !== currentSlide.value) {
    currentSlide.value = newSlide
  }

  // 导航栏自动隐藏
  if (scrollTop > lastScrollY.value && scrollTop > 100) {
    navHidden.value = true
  } else {
    navHidden.value = false
  }
  lastScrollY.value = scrollTop

  // 首次滚动后隐藏提示
  if (scrollTop > 50) scrollHintHidden.value = true
}

/** 滚动到指定幻灯片 */
function scrollToSlide(index) {
  const slide = slideRefs[index]
  if (slide) {
    slide.scrollIntoView({ behavior: 'smooth' })
  }
}

// ===== Lightbox =====
function openLightbox(index) {
  currentLightboxIndex.value = index
  lastFocusedElement.value = document.activeElement
  lightboxVisible.value = true

  // 挂在 document 上：焦点一旦逃出 Lightbox，绑在容器上的监听就会失效（Esc 失灵）。
  // 重复 addEventListener 传同一函数引用不会产生重复监听，可安全重复调用。
  document.addEventListener('keydown', onLightboxKeydown)

  nextTick(() => {
    lightboxCloseRef.value?.focus()
  })
}

function closeLightbox() {
  lightboxVisible.value = false
  document.removeEventListener('keydown', onLightboxKeydown)
  if (lastFocusedElement.value) {
    lastFocusedElement.value.focus()
  }
}

function lightboxNavigate(direction) {
  let newIndex = currentLightboxIndex.value + direction
  if (newIndex < 0) newIndex = wallpapers.value.length - 1
  if (newIndex >= wallpapers.value.length) newIndex = 0
  openLightbox(newIndex)
}

/** Lightbox 焦点陷阱 */
function trapFocus(e) {
  if (e.key !== 'Tab' || !lightboxRef.value) return
  const focusable = lightboxRef.value.querySelectorAll('button, [href], [tabindex]:not([tabindex="-1"])')
  if (focusable.length === 0) return
  const first = focusable[0]
  const last = focusable[focusable.length - 1]
  if (e.shiftKey && document.activeElement === first) {
    e.preventDefault()
    last.focus()
  } else if (!e.shiftKey && document.activeElement === last) {
    e.preventDefault()
    first.focus()
  }
}

function onLightboxKeydown(e) {
  if (e.key === 'Escape') closeLightbox()
  else if (e.key === 'ArrowLeft') lightboxNavigate(-1)
  else if (e.key === 'ArrowRight') lightboxNavigate(1)
  else trapFocus(e)
}

// ===== 全局键盘导航 =====
function onGlobalKeydown(e) {
  // Lightbox 已打开时由其自身处理
  if (lightboxVisible.value) return
  if (currentMode.value !== 'immersive') return

  if (e.key === 'ArrowDown' || e.key === 'j' || e.key === 'J') {
    e.preventDefault()
    if (currentSlide.value < wallpapers.value.length - 1) {
      scrollToSlide(currentSlide.value + 1)
    }
  } else if (e.key === 'ArrowUp' || e.key === 'k' || e.key === 'K') {
    e.preventDefault()
    if (currentSlide.value > 0) {
      scrollToSlide(currentSlide.value - 1)
    }
  }
}

// ===== 重试 =====
async function retry() {
  hasError.value = false
  await init()
}

// ===== 初始化 =====
async function init() {
  try {
    wallpapers.value = await fetchWallpapers()

    if (!wallpapers.value || wallpapers.value.length === 0) {
      throw new Error('没有获取到壁纸数据')
    }

    // 5s 后自动隐藏滚动提示
    setTimeout(() => {
      scrollHintHidden.value = true
    }, 5000)

  } catch (err) {
    console.error('初始化失败:', err)
    errorMsg.value = err.message || '未知错误。'
    hasError.value = true
  }
}

// ===== 生命周期 =====
onMounted(() => {
  init()
  document.addEventListener('keydown', onGlobalKeydown)
})

onUnmounted(() => {
  document.removeEventListener('keydown', onGlobalKeydown)
  document.removeEventListener('keydown', onLightboxKeydown)
})
</script>

<style scoped>
/* ===== 错误状态 ===== */
.error-state {
  position: fixed;
  inset: 0;
  z-index: 1500;
  display: none;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  text-align: center;
  padding: 2rem;
}

.error-state.show { display: flex; }

.error-icon {
  font-size: 3rem;
  margin-bottom: 1rem;
  opacity: 0.3;
}

.error-title {
  font-family: var(--font-display);
  font-size: 1.5rem;
  color: var(--text-primary);
  margin-bottom: 0.5rem;
}

.error-msg {
  color: var(--text-secondary);
  font-size: 0.9rem;
  max-width: 400px;
  line-height: 1.6;
}

.error-retry {
  margin-top: 1.5rem;
  padding: 10px 24px;
  background: var(--accent);
  color: var(--canvas);
  border-radius: var(--radius-sm);
  font-weight: 500;
  font-size: 0.875rem;
  cursor: pointer;
  border: none;
  transition: background var(--transition-fast);
}

.error-retry:hover { background: var(--accent-hover); }

/* 导航栏样式已移到 components/NavBar.vue（两个页面共用，避免各写一份漂移）。
 * 这里只保留「模式切换」的样式 —— 它通过插槽渲染在 NavBar 内部，
 * 但插槽内容在父组件作用域编译，所以样式写在这里才生效。 */

/* 模式切换按钮 */
.mode-toggle {
  display: flex;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-md);
  padding: 3px;
}

.mode-btn {
  padding: 6px 14px;
  font-size: 0.8rem;
  font-weight: 500;
  color: var(--text-secondary);
  border-radius: 7px;
  transition: all var(--transition-fast);
  display: flex;
  align-items: center;
  gap: 6px;
  cursor: pointer;
  border: none;
  background: none;
}

.mode-btn.active {
  background: var(--surface-hover);
  color: var(--text-primary);
}

.mode-btn svg {
  width: 14px;
  height: 14px;
}

/* ===== 沉浸模式 ===== */
.immersive {
  height: 100vh;   /* 旧浏览器兜底 */
  height: 100dvh;  /* 移动端地址栏伸缩时与 JS getSlideHeight() 口径一致 */
  overflow-y: scroll;
  scroll-snap-type: y mandatory;
  scrollbar-width: none;
}

.immersive::-webkit-scrollbar { display: none; }

.immersive.hidden { display: none; }

.slide {
  position: relative;
  height: 100vh;   /* 旧浏览器兜底 */
  height: 100dvh;
  scroll-snap-align: start;
  scroll-snap-stop: always;
  overflow: hidden;
  display: flex;
  align-items: flex-end;
}

/* 渐变遮罩 */
.slide::after {
  content: '';
  position: absolute;
  bottom: 0;
  left: 0;
  right: 0;
  height: 50%;
  background: linear-gradient(to top, rgba(0, 0, 0, 0.75) 0%, rgba(0, 0, 0, 0.3) 50%, transparent 100%);
  pointer-events: none;
  z-index: 1;
}

.slide-img-wrap {
  position: absolute;
  inset: 0;
  overflow: hidden;
}

.slide-img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  opacity: 0;
  transition: opacity 1.2s ease;
  transform: scale(1.02);
}

.slide-img.loaded {
  opacity: 1;
  transform: scale(1);
}

.slide-img-placeholder {
  position: absolute;
  inset: 0;
  background: linear-gradient(135deg, #1a1a1c 0%, #0f0f11 100%);
  display: flex;
  align-items: center;
  justify-content: center;
  transition: opacity 0.6s ease;
}

.slide-img-placeholder.fade {
  opacity: 0;
  pointer-events: none;
}

.placeholder-shimmer {
  width: 48px;
  height: 48px;
  border: 2px solid var(--surface-hover);
  border-top-color: var(--accent);
  border-radius: 50%;
  animation: spin 0.8s linear infinite;
}

.download-spinner {
  width: 18px;
  height: 18px;
  border-width: 2px;
}

/* ===== 图片加载失败提示 ===== */
.img-failed {
  position: absolute;
  inset: 0;
  z-index: 3;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 12px;
  background: linear-gradient(135deg, #1a1a1c 0%, #0f0f11 100%);
  color: var(--text-secondary);
  font-size: 0.85rem;
}

.img-failed-text { letter-spacing: 0.05em; }

.img-failed-retry {
  padding: 6px 16px;
  font-size: 0.8rem;
  font-weight: 500;
  color: var(--canvas);
  background: var(--accent);
  border-radius: var(--radius-sm);
  transition: background var(--transition-fast);
}

.img-failed-retry:hover { background: var(--accent-hover); }

.img-failed--card { gap: 0; background: rgba(10, 10, 12, 0.86); }

/* Slide 元数据 */
.slide-meta {
  position: relative;
  z-index: 2;
  width: 100%;
  padding: 0 4vw 5vh;
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  gap: 2rem;
  opacity: 0;
  transform: translateY(20px);
  transition: opacity 0.6s ease 0.3s, transform 0.6s ease 0.3s;
}

.slide.active .slide-meta {
  opacity: 1;
  transform: translateY(0);
}

.slide-info {
  flex: 1;
  min-width: 0;
}

.slide-index {
  font-size: 0.75rem;
  font-weight: 500;
  color: var(--accent);
  letter-spacing: 0.15em;
  text-transform: uppercase;
  margin-bottom: 8px;
  display: flex;
  align-items: center;
  gap: 8px;
}

.slide-index-line {
  width: 24px;
  height: 1px;
  background: var(--accent);
  opacity: 0.5;
}

.slide-title {
  font-family: var(--font-display);
  font-size: clamp(1.5rem, 3.5vw, 2.8rem);
  font-weight: 700;
  line-height: 1.15;
  letter-spacing: -0.02em;
  color: #fff;
  margin-bottom: 6px;
  text-shadow: 0 2px 20px rgba(0, 0, 0, 0.5);
}

.slide-date {
  font-size: 0.875rem;
  color: var(--text-secondary);
  font-weight: 400;
  letter-spacing: 0.02em;
}

/* 下载按钮 */
.slide-download {
  flex-shrink: 0;
  width: 52px;
  height: 52px;
  display: flex;
  align-items: center;
  justify-content: center;
  background: rgba(255, 255, 255, 0.08);
  backdrop-filter: blur(12px);
  -webkit-backdrop-filter: blur(12px);
  border: 1px solid rgba(255, 255, 255, 0.15);
  border-radius: 50%;
  color: #fff;
  cursor: pointer;
  transition: all var(--transition);
  opacity: 0;
}

.slide.active .slide-download {
  opacity: 1;
  transition-delay: 0.5s;
}

.slide-download:hover {
  background: var(--accent);
  border-color: var(--accent);
  transform: scale(1.08);
}

.slide-download svg {
  width: 20px;
  height: 20px;
}

.slide-download.downloading svg,
.slide-download.done svg {
  width: 18px;
  height: 18px;
}

.slide-download.done {
  background: var(--success);
  border-color: var(--success);
}

/* ===== 进度点 ===== */
.progress-dots {
  position: fixed;
  right: 2vw;
  top: 50%;
  transform: translateY(-50%);
  z-index: 50;
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.dot {
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: rgba(255, 255, 255, 0.2);
  border: none;
  cursor: pointer;
  padding: 0;
  transition: all var(--transition);
}

.dot.active {
  background: var(--accent);
  transform: scale(1.5);
  box-shadow: 0 0 8px rgba(212, 168, 83, 0.5);
}

.dot:hover { background: rgba(255, 255, 255, 0.4); }
.dot.active:hover { background: var(--accent); }

/* ===== 滚动提示 ===== */
.scroll-hint {
  position: fixed;
  bottom: 3vh;
  left: 50%;
  transform: translateX(-50%);
  z-index: 50;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8px;
  color: var(--text-tertiary);
  font-size: 0.75rem;
  letter-spacing: 0.1em;
  text-transform: uppercase;
  transition: opacity 0.6s ease;
  pointer-events: none;
}

.scroll-hint.hidden { opacity: 0; }

.scroll-hint-arrow {
  width: 1px;
  height: 24px;
  background: linear-gradient(to bottom, transparent, var(--text-tertiary));
  animation: scrollPulse 2s ease-in-out infinite;
}

@keyframes scrollPulse {
  0%, 100% { transform: scaleY(1); opacity: 0.5; }
  50% { transform: scaleY(1.3); opacity: 1; }
}

/* ===== 画廊模式 ===== */
.gallery {
  display: none;
  height: 100vh;
  overflow-y: auto;
  scrollbar-width: thin;
  scrollbar-color: var(--surface-hover) transparent;
  padding: calc(var(--nav-height) + 2rem) 2.5vw 3rem;
}

.gallery::-webkit-scrollbar { width: 6px; }
.gallery::-webkit-scrollbar-track { background: transparent; }
.gallery::-webkit-scrollbar-thumb { background: var(--surface-hover); border-radius: 3px; }

.gallery.active { display: block; }

.gallery-header { margin-bottom: 2rem; }

.gallery-title {
  font-family: var(--font-display);
  font-size: 2rem;
  color: var(--text-primary);
  margin-bottom: 4px;
}

.gallery-subtitle {
  font-size: 0.875rem;
  color: var(--text-secondary);
}

.gallery-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
  gap: 20px;
}

.gallery-card {
  position: relative;
  aspect-ratio: 16 / 10;
  border-radius: var(--radius-lg);
  overflow: hidden;
  cursor: pointer;
  background: var(--surface);
  border: 1px solid var(--border);
  transition: transform var(--transition), border-color var(--transition);
}

.gallery-card:hover {
  transform: translateY(-4px);
  border-color: var(--border-strong);
}

.gallery-card-img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  transition: transform 0.6s ease;
}

.gallery-card:hover .gallery-card-img {
  transform: scale(1.05);
}

.gallery-card-overlay {
  position: absolute;
  inset: 0;
  background: linear-gradient(to top, rgba(0, 0, 0, 0.8) 0%, transparent 60%);
  display: flex;
  flex-direction: column;
  justify-content: flex-end;
  padding: 16px 20px;
  opacity: 0;
  transition: opacity var(--transition);
}

.gallery-card:hover .gallery-card-overlay {
  opacity: 1;
}

.gallery-card-title {
  font-family: var(--font-display);
  font-size: 1.1rem;
  color: #fff;
  margin-bottom: 2px;
}

.gallery-card-date {
  font-size: 0.75rem;
  color: var(--text-secondary);
}

/* ===== Lightbox ===== */
.lightbox {
  position: fixed;
  inset: 0;
  z-index: 2000;
  display: none;
  align-items: center;
  justify-content: center;
  background: rgba(0, 0, 0, 0.92);
  backdrop-filter: blur(8px);
  opacity: 0;
  transition: opacity 0.3s ease;
}

.lightbox.show {
  display: flex;
  opacity: 1;
}

.lightbox-img {
  max-width: 90vw;
  max-height: 85vh;
  border-radius: var(--radius-md);
  box-shadow: 0 20px 80px rgba(0, 0, 0, 0.6);
}

.lightbox-close {
  position: absolute;
  top: 20px;
  right: 24px;
  width: 44px;
  height: 44px;
  display: flex;
  align-items: center;
  justify-content: center;
  background: rgba(255, 255, 255, 0.08);
  border: 1px solid rgba(255, 255, 255, 0.15);
  border-radius: 50%;
  color: #fff;
  font-size: 1.2rem;
  cursor: pointer;
  transition: background var(--transition-fast);
}

.lightbox-close:hover { background: rgba(255, 255, 255, 0.15); }

.lightbox-info {
  position: absolute;
  bottom: 3vh;
  left: 50%;
  transform: translateX(-50%);
  text-align: center;
  max-width: 600px;
  padding: 0 2rem;
}

.lightbox-title {
  font-family: var(--font-display);
  font-size: 1.3rem;
  color: #fff;
  margin-bottom: 4px;
}

.lightbox-date {
  font-size: 0.8rem;
  color: var(--text-secondary);
}

.lightbox-download {
  position: absolute;
  bottom: 3vh;
  right: 3vw;
  width: 48px;
  height: 48px;
  display: flex;
  align-items: center;
  justify-content: center;
  background: var(--accent);
  border: none;
  border-radius: 50%;
  color: var(--canvas);
  cursor: pointer;
  transition: all var(--transition);
}

.lightbox-download:hover {
  background: var(--accent-hover);
  transform: scale(1.08);
}

.lightbox-download svg { width: 20px; height: 20px; }

/* Lightbox 导航按钮 */
.lightbox-nav {
  position: absolute;
  top: 50%;
  transform: translateY(-50%);
  width: 48px;
  height: 48px;
  display: flex;
  align-items: center;
  justify-content: center;
  background: rgba(255, 255, 255, 0.08);
  border: 1px solid rgba(255, 255, 255, 0.15);
  border-radius: 50%;
  color: #fff;
  cursor: pointer;
  transition: all var(--transition-fast);
}

.lightbox-nav:hover {
  background: rgba(255, 255, 255, 0.18);
  transform: translateY(-50%) scale(1.08);
}

.lightbox-nav svg { width: 22px; height: 22px; }
.lightbox-prev { left: 2vw; }
.lightbox-next { right: 2vw; }

/* ===== 页脚 ===== */
.footer {
  position: fixed;
  bottom: 0;
  left: 0;
  right: 0;
  z-index: 40;
  padding: 12px 2.5vw;
  display: flex;
  justify-content: flex-end;
  align-items: center;
  font-size: 0.75rem;
  color: var(--text-tertiary);
  background: linear-gradient(to top, var(--canvas) 0%, transparent 100%);
  pointer-events: none;
  opacity: 1;
  transition: opacity var(--transition);
}

/* ===== 入场动画 ===== */
.fade-in-up {
  animation: fadeInUp 0.8s var(--ease) forwards;
  opacity: 0;
}

@keyframes fadeInUp {
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

/* ===== 响应式 ===== */
@media (max-width: 768px) {
  /* 导航自身在窄屏的调整已移到 NavBar.vue；这里只管本页内容 */
  .progress-dots { right: 1.5vw; }
  .dot { width: 5px; height: 5px; }
  .slide-meta {
    padding: 0 5vw 4vh;
    flex-direction: column;
    align-items: flex-start;
    gap: 1rem;
  }
  .slide-download { width: 44px; height: 44px; }
  .slide-title { font-size: 1.3rem; }
  .gallery { padding: calc(56px + 1.5rem) 4vw 2rem; }
  .gallery-grid { grid-template-columns: 1fr; gap: 14px; }
  .gallery-title { font-size: 1.5rem; }
  .mode-toggle .mode-btn span { display: none; }
  .mode-btn { padding: 6px 10px; }
  .scroll-hint { display: none; }
  .lightbox-info { bottom: 2vh; }
  .lightbox-download { bottom: 2vh; right: 4vw; }
}

@media (max-width: 480px) {
  .slide-title { font-size: 1.1rem; }
}

/* ===== 减弱动画偏好 ===== */
@media (prefers-reduced-motion: reduce) {
  *,
  *::before,
  *::after {
    animation-duration: 0.01ms !important;
    transition-duration: 0.01ms !important;
  }
}

/* ===== 焦点可见性 ===== */
:focus:not(:focus-visible) {
  outline: none;
}
</style>
