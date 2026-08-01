import { defineStore } from 'pinia'
import api from '@/api/client'

/**
 * Language, direction and the string table. Every label in the panel is
 * resolved through `t()`, and the strings themselves come from the API.
 */
export const useUiStore = defineStore('ui', {
  state: () => ({
    locale: localStorage.getItem('gymflow.locale') || 'fa',
    direction: 'rtl',
    locales: {},
    lines: {},
    loading: false,
    toast: null,
  }),

  actions: {
    async loadLocales() {
      const { data } = await api.get('/locales')

      this.locales = data.locales
    },

    async loadTranslations(locale = this.locale) {
      const { data } = await api.get(`/translations/${locale}`)

      this.lines = data.lines
      this.direction = data.direction
      this.locale = locale

      localStorage.setItem('gymflow.locale', locale)
      document.documentElement.lang = locale
      document.documentElement.dir = data.direction
    },

    async setLocale(locale) {
      await this.loadTranslations(locale)
    },

    /**
     * `t('checkin.allowed')`. Shared strings live in their own group; the
     * panel's own labels all sit under "panel", which is searched second so
     * a club override in the real group always wins.
     */
    t(key, fallback = null) {
      const path = key.split('.')
      const resolve = (root) => path.reduce((carry, part) => carry?.[part], root)

      return resolve(this.lines) ?? resolve(this.lines.panel) ?? fallback ?? key
    },

    notify(message, kind = 'success') {
      this.toast = { message, kind, id: Date.now() }

      setTimeout(() => {
        if (this.toast?.id) this.toast = null
      }, 4000)
    },
  },
})
