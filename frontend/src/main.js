import { createApp } from 'vue'

import './styles/variables.css'
import './styles/global.css'

import App from './app.vue'
import { router } from './router'

createApp(App).use(router).mount('#app')
