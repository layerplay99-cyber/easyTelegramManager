import Cache from '@/support/cache'
import { createI18n } from 'vue-i18n'
import en from './languages/en'
import zh from './languages/zh'
import th from './languages/th'
import vi from './languages/vi'
import type { App } from 'vue'

const messages = {
  en,
  zh,
  th,
  vi,
}

const i18n = createI18n({
  locale: Cache.get('language') || 'zh',
  messages,
  globalInjection: true,
  legacy: false, // 使用 Composition API 模式
  fallbackLocale: 'zh'
})

export function bootstrapI18n(app: App): void {
  app.use(i18n)
}

export default i18n
