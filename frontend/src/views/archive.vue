<template>
  <!--
    Archive.vue — 壁纸库（历史归档）
    数据来自 bingimages 数据集（3851 天，2016-03 起），由后端 /api/archive/* 提供。
    视觉语言与展示页保持一致：同一套 design tokens（#0a0a0c 画布 + #d4a853 琥珀金）。
  -->
  <NavBar />

  <main class="archive">
    <!-- ===== 页头 ===== -->
    <header class="head">
      <h2 class="head-title">壁纸库</h2>
      <p class="head-sub">
        Bing 每日精选历史归档
        <span v-if="stats" class="head-stat">
          · 共 {{ stats.total.toLocaleString() }} 张
          · {{ formatDate(stats.min_date) }} → {{ formatDate(stats.max_date) }}
        </span>
      </p>
    </header>

    <!-- ===== 工具栏 ===== -->
    <div class="toolbar">
      <div class="field field--grow">
        <label class="field-label" for="kw">搜索</label>
        <input
          id="kw"
          v-model="keywordInput"
          class="input"
          type="search"
          autocomplete="off"
          placeholder="标题 / 版权 / 日期，如 2024-10 或 极光"
          @input="applyFilter"
        />
      </div>
      <div class="field">
        <label class="field-label" for="year">年份</label>
        <select id="year" v-model="yearInput" class="input select" @change="applyFilter">
          <option value="">全部年份</option>
          <option v-for="y in years" :key="y.year" :value="String(y.year)">
            {{ y.year }}（{{ y.count }}）
          </option>
        </select>
      </div>
      <button v-if="hasFilter" class="btn-ghost" type="button" @click="clearFilter">清空</button>
    </div>

    <!-- ===== 数据集不可用 ===== -->
    <div v-if="datasetError" class="state state--error">
      <div class="state-icon">🌑</div>
      <div class="state-title">壁纸库暂不可用</div>
      <p class="state-msg">{{ datasetError }}</p>
    </div>

    <!-- ===== 网格 ===== -->
    <div v-else class="grid">
      <article
        v-for="(item, i) in items"
        :key="item.date"
        class="card"
        :class="{ 'fade-in-up': i >= firstPaintCount }"
        :style="i >= firstPaintCount ? { animationDelay: ((i - firstPaintCount) % 24) * 0.03 + 's' } : null"
        tabindex="0"
        role="button"
        :aria-label="'查看 ' + item.date + ' 的壁纸'"
        @click="openDetail(i)"
        @keydown.enter.prevent="openDetail(i)"
        @keydown.space.prevent="openDetail(i)"
      >
        <img
          class="card-img"
          :src="item.url"
          :alt="displayTitle(item)"
          loading="lazy"
          decoding="async"
          @error="onImgError($event)"
        />
        <div class="card-overlay">
          <div class="card-title">{{ displayTitle(item) }}</div>
          <div class="card-meta">
            <span>{{ formatDate(item.date) }}</span>
            <span v-if="item.url_4k" class="tag-4k">4K</span>
          </div>
        </div>
      </article>
    </div>

    <!-- ===== 状态提示 ===== -->
    <div v-if="!datasetError" ref="sentinel" class="sentinel" aria-hidden="true"></div>
    <div v-if="loading" class="state state--hint">加载中…</div>
    <div v-else-if="!hasMore && items.length" class="state state--hint">
      — 已展示全部 {{ items.length.toLocaleString() }} 张 —
    </div>
    <div v-else-if="!loading && !items.length && !datasetError" class="state">
      <div class="state-icon">🔍</div>
      <div class="state-title">没有匹配的壁纸</div>
      <p class="state-msg">换个关键词，或清空筛选条件看看。</p>
    </div>

    <footer class="foot">
      <span>数据来源 © Bing · 归档数据集经 niumoo / Zhu-junwei 两仓库合并</span>
    </footer>
  </main>

  <!-- ===== 详情弹窗 ===== -->
  <Teleport to="body">
    <div
      v-if="detailVisible"
      ref="detailRoot"
      class="detail"
      role="dialog"
      aria-modal="true"
      aria-label="壁纸详情"
      @click.self="closeDetail"
    >
      <button ref="closeBtn" class="detail-close" aria-label="关闭" @click="closeDetail">&times;</button>
      <button class="detail-nav detail-prev" aria-label="上一张（更新）" @click="navigate(-1)">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <polyline points="15 18 9 12 15 6" />
        </svg>
      </button>
      <button class="detail-nav detail-next" aria-label="下一张（更早）" @click="navigate(1)">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <polyline points="9 18 15 12 9 6" />
        </svg>
      </button>

      <div class="detail-body">
        <img class="detail-img" :src="detailSrc" :alt="detailItem ? displayTitle(detailItem) : ''" />

        <div v-if="detailItem" class="detail-info">
          <div class="detail-index">
            <span class="detail-index-line" />
            {{ formatDate(detailItem.date) }} · {{ weekdayZh(detailItem.weekday) }}
          </div>
          <h3 class="detail-title">{{ displayTitle(detailItem) }}</h3>
          <p v-if="detailItem.title_en && detailItem.title" class="detail-sub">{{ detailItem.title_en }}</p>
          <p v-if="displayCopyright(detailItem)" class="detail-copy">{{ displayCopyright(detailItem) }}</p>

          <div class="detail-tags">
            <span v-if="detailItem.region" class="chip">{{ detailItem.region }}</span>
            <span v-if="detailItem.url_4k" class="chip chip--accent">4K 可用</span>
            <span v-else class="chip">1080p 原图</span>
            <span v-for="s in detailItem.sources" :key="s" class="chip chip--dim">{{ s }}</span>
          </div>

          <div class="detail-actions">
            <button class="btn" :class="{ done: downloaded }" type="button" @click="downloadDetail">
              <template v-if="downloaded">✓ 已下载</template>
              <template v-else-if="downloading">下载中…</template>
              <template v-else>⬇ 下载原图</template>
            </button>
            <a
              v-if="detailItem.url_4k"
              class="btn btn--ghost"
              :href="detailItem.url_4k"
              target="_blank"
              rel="noopener"
            >4K 原图</a>
            <a class="btn btn--ghost" :href="detailItem.url" target="_blank" rel="noopener">在线打开</a>
            <a
              v-if="detailItem.copyrightlink"
              class="btn btn--ghost"
              :href="detailItem.copyrightlink"
              target="_blank"
              rel="noopener"
            >版权信息</a>
          </div>

          <p v-if="!detailItem.url_4k" class="detail-note">
            这张历史图 Bing 侧只有 1080p，没有 4K 版本（实测 4K 地址返回 404）。
          </p>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<script setup>
/**
 * Archive.vue — 壁纸库页面。
 *
 * 要点：
 * - 分页用「哨兵 + IntersectionObserver」做无限滚动，同时保留一个可见的结束标记，
 *   用户在触底时能明确知道「到底了」而不是以为卡住。
 * - 搜索/年份变化一律防抖 300ms 并重置到第 1 页（否则会把筛选结果接在旧列表后面）。
 * - 卡片图片直接指向 Bing CDN（沿用本项目「图片字节不过服务器」的架构），
 *   4K 是否可用由后端算好放在 item.url_4k，前端不重复判断。
 */

import { ref, reactive, computed, onMounted, onUnmounted, nextTick } from 'vue'
import NavBar from '../components/NavBar.vue'

const PAGE_SIZE = 24
const WEEKDAY_ZH = {
  Monday: '周一',
  Tuesday: '周二',
  Wednesday: '周三',
  Thursday: '周四',
  Friday: '周五',
  Saturday: '周六',
  Sunday: '周日',
}

// ===== 状态 =====
const items = ref([])
const stats = ref(null)
const loading = ref(false)
const hasMore = ref(true)
const datasetError = ref('')
const keywordInput = ref('')
const yearInput = ref('')
const sentinel = ref(null)

// 首屏那批不做入场动画，避免用户一进页面看到一片空白再逐个浮现
const firstPaintCount = ref(PAGE_SIZE)

const detailVisible = ref(false)
const detailIndex = ref(0)
const downloading = ref(false)
const downloaded = ref(false)
const detailRoot = ref(null)
const closeBtn = ref(null)
const lastFocused = ref(null)

const state = reactive({ page: 0, keyword: '', year: '' })

// ===== 计算 =====
const years = computed(() => stats.value?.by_year ?? [])
const hasFilter = computed(() => state.keyword !== '' || state.year !== '')
const detailItem = computed(() => items.value[detailIndex.value] ?? null)

/**
 * 详情大图优先用 4K（有的话），与展示页 Lightbox 的做法保持一致。
 * 列表缩略图仍走 1080p —— 那里要的是首屏速度，不是画质。
 */
const detailSrc = computed(() => {
  const item = detailItem.value
  if (!item) return ''
  return item.url_4k || item.url
})

// ===== 工具 =====
/** 2016-03-05 → 2016.03.05 */
function formatDate(dateStr) {
  if (!dateStr || dateStr.length !== 10) return dateStr || ''
  return dateStr.replace(/-/g, '.')
}

function weekdayZh(weekday) {
  return WEEKDAY_ZH[weekday] || weekday || ''
}

function displayTitle(item) {
  return item.title || item.title_en || '(无标题)'
}

function displayCopyright(item) {
  return item.copyright || item.copyright_en || ''
}

function debounce(fn, ms) {
  let timer
  return (...args) => {
    clearTimeout(timer)
    timer = setTimeout(() => fn(...args), ms)
  }
}

/** 图片挂了不要留一个破图标在那儿 */
function onImgError(event) {
  const img = event.target
  img.style.opacity = '0.25'
  img.alt = '加载失败'
}

// ===== 数据获取 =====
async function fetchStats() {
  try {
    const resp = await fetch('/api/archive/stats')
    if (resp.status === 503) {
      datasetError.value = '后端未找到 bingimages 数据集，请检查 BINGIMAGES_DIR 配置。'
      return
    }
    if (!resp.ok) throw new Error('HTTP ' + resp.status)
    const json = await resp.json()
    stats.value = json.data
  } catch (e) {
    datasetError.value = '统计信息加载失败：' + e.message
  }
}

async function fetchPage(page) {
  const params = new URLSearchParams({ page: String(page), size: String(PAGE_SIZE) })
  if (state.keyword) params.set('keyword', state.keyword)
  if (state.year) params.set('year', state.year)

  const resp = await fetch('/api/archive/wallpapers?' + params.toString())
  if (resp.status === 503) {
    datasetError.value = '后端未找到 bingimages 数据集，请检查 BINGIMAGES_DIR 配置。'
    return null
  }
  if (!resp.ok) throw new Error('HTTP ' + resp.status)
  return (await resp.json()).data
}

async function loadMore() {
  if (loading.value || !hasMore.value || datasetError.value) return

  loading.value = true
  try {
    const next = state.page + 1
    const data = await fetchPage(next)
    if (data === null) return

    if (data.items.length === 0) {
      hasMore.value = false
      return
    }

    state.page = next
    hasMore.value = data.has_more
    items.value = items.value.concat(data.items)
  } catch (e) {
    hasMore.value = false
    datasetError.value = '加载失败：' + e.message
  } finally {
    loading.value = false
  }
}

function resetAndReload() {
  state.page = 0
  hasMore.value = true
  items.value = []
  // 重置后这批算首屏，同样不做入场动画
  firstPaintCount.value = PAGE_SIZE
  loadMore()
}

const applyFilter = debounce(() => {
  state.keyword = keywordInput.value.trim()
  state.year = yearInput.value
  resetAndReload()
}, 300)

function clearFilter() {
  keywordInput.value = ''
  yearInput.value = ''
  applyFilter()
}

// ===== 详情 =====
function openDetail(index) {
  detailIndex.value = index
  detailVisible.value = true
  downloaded.value = false
  lastFocused.value = document.activeElement
  document.addEventListener('keydown', onDetailKeydown)
  // 焦点移进弹窗，否则 Tab 会跑到背后的网格里
  nextTick(() => closeBtn.value?.focus())
}

function closeDetail() {
  detailVisible.value = false
  document.removeEventListener('keydown', onDetailKeydown)
  if (lastFocused.value) lastFocused.value.focus()
}

/**
 * 前后翻页。列表按日期倒序，所以 +1 是更早的一张。
 */
function navigate(delta) {
  const next = detailIndex.value + delta
  if (next < 0 || next >= items.value.length) return
  detailIndex.value = next
  downloaded.value = false
}

/** Tab 在弹窗内循环（与展示页 Lightbox 的做法一致） */
function trapFocus(e) {
  if (e.key !== 'Tab' || !detailRoot.value) return
  const focusable = detailRoot.value.querySelectorAll('button, [href], [tabindex]:not([tabindex="-1"])')
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

async function downloadDetail() {
  const item = detailItem.value
  if (!item || downloading.value) return

  downloading.value = true
  const src = item.url_4k || item.url
  try {
    const resp = await fetch(src)
    if (!resp.ok) throw new Error('HTTP ' + resp.status)
    const blob = await resp.blob()
    const objectUrl = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = objectUrl
    a.download = `bing-${item.date}-${sanitize(displayTitle(item))}.jpg`
    document.body.appendChild(a)
    a.click()
    document.body.removeChild(a)
    URL.revokeObjectURL(objectUrl)
    downloaded.value = true
    setTimeout(() => {
      downloaded.value = false
    }, 2400)
  } catch {
    // 跨域或防盗链导致 blob 拿不到时，退化成新标签页打开原图
    window.open(src, '_blank', 'noopener')
  } finally {
    downloading.value = false
  }
}

function sanitize(name) {
  return (name || 'bing-wallpaper').replace(/[<>:"/\\|?*]/g, '_').trim().slice(0, 60) || 'bing-wallpaper'
}

function onDetailKeydown(e) {
  if (e.key === 'Escape') {
    closeDetail()
    return
  }
  // 与列表顺序一致：→ 更早，← 更新
  if (e.key === 'ArrowRight') {
    navigate(1)
    return
  }
  if (e.key === 'ArrowLeft') {
    navigate(-1)
    return
  }
  trapFocus(e)
}

// ===== 无限滚动 =====
let observer = null

onMounted(async () => {
  await fetchStats()
  if (datasetError.value) return

  await loadMore()

  if (sentinel.value && 'IntersectionObserver' in window) {
    // 观察器在首次观察时若目标已在视口内会立即回调一次，
    // 所以「首屏不足一屏」的情况也能自动继续加载，不需要额外补一次。
    observer = new IntersectionObserver(
      (entries) => {
        for (const entry of entries) {
          if (entry.isIntersecting) loadMore()
        }
      },
      // 提前 400px 触发，滚动时不容易看到空档
      { rootMargin: '400px' }
    )
    observer.observe(sentinel.value)
  }
})

onUnmounted(() => {
  observer?.disconnect()
  document.removeEventListener('keydown', onDetailKeydown)
})
</script>

<style scoped>
/* ===== 页面骨架 ===== */
.archive {
  min-height: 100vh;
  padding: calc(var(--nav-height) + 2.5rem) 2.5vw 3rem;
  max-width: 1560px;
  margin: 0 auto;
}

/* ===== 页头 ===== */
.head {
  margin-bottom: 1.75rem;
}

.head-title {
  font-family: var(--font-display);
  font-size: 2rem;
  font-weight: 700;
  color: var(--text-primary);
  letter-spacing: -0.02em;
  margin-bottom: 6px;
}

.head-sub {
  font-size: 0.875rem;
  color: var(--text-secondary);
}

.head-stat {
  color: var(--text-tertiary);
}

/* ===== 工具栏 ===== */
.toolbar {
  display: flex;
  align-items: flex-end;
  gap: 12px;
  flex-wrap: wrap;
  padding-bottom: 1.5rem;
  margin-bottom: 1.75rem;
  border-bottom: 1px solid var(--border);
}

.field {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.field--grow {
  flex: 1 1 260px;
  min-width: 200px;
}

.field-label {
  font-size: 0.7rem;
  text-transform: uppercase;
  letter-spacing: 0.1em;
  color: var(--text-tertiary);
}

.input {
  width: 100%;
  padding: 10px 14px;
  font-size: 0.875rem;
  color: var(--text-primary);
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  transition: border-color var(--transition-fast), background var(--transition-fast);
}

.input::placeholder {
  color: var(--text-tertiary);
}

.input:hover {
  background: var(--surface-hover);
}

.input:focus {
  outline: none;
  border-color: var(--accent);
  background: var(--surface-hover);
}

.select {
  cursor: pointer;
  min-width: 150px;
  /* 原生下拉的箭头在深色底上需要用背景图补一个，否则看不见 */
  appearance: none;
  -webkit-appearance: none;
  padding-right: 34px;
  background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%238b8b93' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
  background-repeat: no-repeat;
  background-position: right 12px center;
}

.select option {
  background: var(--surface);
  color: var(--text-primary);
}

.btn-ghost {
  padding: 10px 18px;
  font-size: 0.82rem;
  font-weight: 500;
  color: var(--text-secondary);
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  transition: color var(--transition-fast), border-color var(--transition-fast);
}

.btn-ghost:hover {
  color: var(--text-primary);
  border-color: var(--border-strong);
}

/* ===== 网格 ===== */
.grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(268px, 1fr));
  gap: 18px;
}

.card {
  position: relative;
  aspect-ratio: 16 / 10;
  border-radius: var(--radius-lg);
  overflow: hidden;
  cursor: pointer;
  background: var(--surface);
  border: 1px solid var(--border);
  transition: transform var(--transition), border-color var(--transition);
}

.card:hover,
.card:focus-visible {
  transform: translateY(-4px);
  border-color: var(--border-strong);
}

.card-img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  transition: transform 0.6s ease, opacity 0.4s ease;
}

.card:hover .card-img {
  transform: scale(1.05);
}

.card-overlay {
  position: absolute;
  inset: 0;
  display: flex;
  flex-direction: column;
  justify-content: flex-end;
  gap: 4px;
  padding: 14px 16px;
  background: linear-gradient(to top, rgba(0, 0, 0, 0.85) 0%, rgba(0, 0, 0, 0.35) 45%, transparent 75%);
  opacity: 0;
  transition: opacity var(--transition);
}

.card:hover .card-overlay,
.card:focus-visible .card-overlay {
  opacity: 1;
}

/* 触屏没有 hover，覆盖层必须常显，否则标题永远看不到 */
@media (hover: none) {
  .card-overlay {
    opacity: 1;
  }
}

.card-title {
  font-family: var(--font-display);
  font-size: 1rem;
  line-height: 1.3;
  color: #fff;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.card-meta {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 0.75rem;
  color: var(--text-secondary);
}

.tag-4k {
  padding: 1px 6px;
  font-size: 0.65rem;
  font-weight: 700;
  letter-spacing: 0.05em;
  color: var(--accent);
  background: var(--accent-soft);
  border: 1px solid rgba(212, 168, 83, 0.35);
  border-radius: 4px;
}

/* ===== 状态 ===== */
.sentinel {
  height: 1px;
}

.state {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 10px;
  padding: 5rem 1rem;
  text-align: center;
}

.state--hint {
  padding: 2.5rem 1rem;
  font-size: 0.82rem;
  color: var(--text-tertiary);
  letter-spacing: 0.05em;
}

.state-icon {
  font-size: 2.5rem;
  opacity: 0.35;
}

.state-title {
  font-family: var(--font-display);
  font-size: 1.25rem;
  color: var(--text-primary);
}

.state-msg {
  font-size: 0.875rem;
  color: var(--text-secondary);
  max-width: 420px;
  line-height: 1.7;
}

.state--error .state-title {
  color: #ef4444;
}

/* ===== 页脚 ===== */
.foot {
  margin-top: 3rem;
  padding-top: 1.25rem;
  border-top: 1px solid var(--border);
  font-size: 0.75rem;
  color: var(--text-tertiary);
  text-align: center;
}

/* ===== 详情弹窗 ===== */
.detail {
  position: fixed;
  inset: 0;
  z-index: 2000;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 3vh 2vw;
  background: rgba(0, 0, 0, 0.92);
  backdrop-filter: blur(8px);
  -webkit-backdrop-filter: blur(8px);
}

.detail-body {
  display: grid;
  grid-template-columns: minmax(0, 1.55fr) minmax(300px, 1fr);
  gap: 0;
  width: min(1400px, 100%);
  max-height: 94vh;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
  overflow: hidden;
}

.detail-img {
  width: 100%;
  height: 100%;
  max-height: 94vh;
  object-fit: contain;
  background: #000;
}

.detail-info {
  padding: 26px 26px 30px;
  overflow-y: auto;
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.detail-index {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 0.72rem;
  font-weight: 500;
  letter-spacing: 0.14em;
  text-transform: uppercase;
  color: var(--accent);
}

.detail-index-line {
  width: 20px;
  height: 1px;
  background: var(--accent);
  opacity: 0.6;
}

.detail-title {
  font-family: var(--font-display);
  font-size: 1.5rem;
  line-height: 1.25;
  color: var(--text-primary);
  letter-spacing: -0.01em;
}

.detail-sub {
  font-size: 0.85rem;
  color: var(--text-secondary);
  font-style: italic;
}

.detail-copy {
  font-size: 0.875rem;
  line-height: 1.7;
  color: var(--text-secondary);
}

.detail-tags {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin-top: 4px;
}

.chip {
  padding: 3px 9px;
  font-size: 0.7rem;
  color: var(--text-secondary);
  border: 1px solid var(--border-strong);
  border-radius: 99px;
}

.chip--accent {
  color: var(--accent);
  border-color: rgba(212, 168, 83, 0.4);
  background: var(--accent-soft);
}

.chip--dim {
  color: var(--text-tertiary);
}

.detail-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: 8px;
}

.btn {
  padding: 9px 16px;
  font-size: 0.82rem;
  font-weight: 500;
  color: var(--canvas);
  background: var(--accent);
  border: 1px solid var(--accent);
  border-radius: var(--radius-sm);
  text-decoration: none;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  transition: background var(--transition-fast), transform var(--transition-fast);
}

.btn:hover {
  background: var(--accent-hover);
  border-color: var(--accent-hover);
}

.btn.done {
  background: var(--success);
  border-color: var(--success);
}

.btn--ghost {
  color: var(--text-secondary);
  background: transparent;
  border-color: var(--border-strong);
}

.btn--ghost:hover {
  color: var(--text-primary);
  background: var(--surface-hover);
  border-color: var(--border-strong);
}

.detail-note {
  font-size: 0.75rem;
  line-height: 1.6;
  color: var(--text-tertiary);
  padding-top: 4px;
  border-top: 1px solid var(--border);
  margin-top: 6px;
}

.detail-close {
  position: absolute;
  top: 20px;
  right: 24px;
  width: 44px;
  height: 44px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 1.3rem;
  color: #fff;
  background: rgba(255, 255, 255, 0.08);
  border: 1px solid rgba(255, 255, 255, 0.15);
  border-radius: 50%;
  transition: background var(--transition-fast);
}

.detail-close:hover {
  background: rgba(255, 255, 255, 0.18);
}

.detail-nav {
  position: absolute;
  top: 50%;
  transform: translateY(-50%);
  width: 48px;
  height: 48px;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #fff;
  background: rgba(255, 255, 255, 0.08);
  border: 1px solid rgba(255, 255, 255, 0.15);
  border-radius: 50%;
  transition: background var(--transition-fast), transform var(--transition-fast);
}

.detail-nav:hover {
  background: rgba(255, 255, 255, 0.18);
  transform: translateY(-50%) scale(1.06);
}

.detail-nav svg {
  width: 22px;
  height: 22px;
}

.detail-prev { left: 1.5vw; }
.detail-next { right: 1.5vw; }

/* ===== 入场动画 ===== */
.fade-in-up {
  animation: fadeInUp 0.5s var(--ease) both;
}

@keyframes fadeInUp {
  from {
    opacity: 0;
    transform: translateY(16px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

/* ===== 响应式 ===== */
@media (max-width: 980px) {
  .detail-body {
    grid-template-columns: 1fr;
    max-height: 92vh;
    overflow-y: auto;
  }
  .detail-img {
    max-height: 52vh;
  }
}

@media (max-width: 768px) {
  .archive {
    padding: calc(56px + 1.75rem) 4vw 2.5rem;
  }
  .head-title {
    font-size: 1.5rem;
  }
  .grid {
    grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
    gap: 12px;
  }
  .card-overlay {
    padding: 10px 12px;
  }
  .card-title {
    font-size: 0.85rem;
  }
  .detail-nav,
  .detail-close {
    width: 38px;
    height: 38px;
  }
  .detail-prev { left: 8px; }
  .detail-next { right: 8px; }
}
</style>
