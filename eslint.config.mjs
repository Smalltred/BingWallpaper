// ESLint 扁平配置（ESLint 9+）
//
// 用 .mjs 扩展名：根 package.json 没有 "type": "module"，而这份配置是 ESM 语法。

import js from '@eslint/js'
import pluginVue from 'eslint-plugin-vue'
import globals from 'globals'

export default [
  {
    ignores: [
      '**/node_modules/**',
      '**/dist/**',
      'app/**', // PHP 后端，交给 tools/lint-php.php
      'public/**', // Web 根：入口 index.php 与打包后的 JS（压缩产物不该 lint）
      'frontend/static/**', // 构建输入资产（字体、图标、分享图）
      'data/**', // 运行时数据
      'deploy/**'
    ]
  },

  js.configs.recommended,
  ...pluginVue.configs['flat/recommended'],

  // 后端是 PHP，所以 JS 的 lint 只覆盖前端的 Vue/JS
  // （Node 版后端曾置于 legacy/，已于 2026-09-21 随「收敛为 Vue3+PHP 单版本」删除）

  // 前端与根级配置脚本：ESM + 浏览器环境
  {
    files: ['frontend/**/*.{js,vue}', '*.mjs'],
    languageOptions: {
      ecmaVersion: 2022,
      sourceType: 'module',
      globals: { ...globals.browser }
    }
  },

  // tools/ 下的脚本跑在 Node 里（用 process / Buffer / node: 内置模块）
  {
    files: ['tools/**/*.mjs'],
    languageOptions: {
      ecmaVersion: 2022,
      sourceType: 'module',
      globals: { ...globals.node }
    }
  },

  // Vue SFC 的务实放宽
  {
    files: ['**/*.vue'],
    rules: {
      // 只有 Showcase 一个视图组件，且已在 views/ 目录层面区分职责
      'vue/multi-word-component-names': 'off',

      // 以下 4 条是纯排版偏好，与本项目既有风格冲突（SVG 属性本就写在一行、
      // 图标标签用自闭合写法）。关掉它们，lint 输出才只剩真问题。
      // 排版交给编辑器 / .editorconfig。
      'vue/max-attributes-per-line': 'off',
      'vue/singleline-html-element-content-newline': 'off',
      'vue/html-self-closing': 'off',
      'vue/attributes-order': 'off'
    }
  }
]
