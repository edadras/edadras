import { defineStore } from 'pinia'
import api from '@/api/client'

export const useAuthStore = defineStore('auth', {
  state: () => ({
    user: null,
    club: null,
    permissions: [],
    ready: false,
  }),

  getters: {
    isAuthenticated: (state) => state.user !== null,
    isSuperAdmin: (state) => Boolean(state.user?.is_super_admin),

    /** Mirrors the API's wildcard rules so the sidebar hides what it must. */
    can: (state) => (permission) => {
      if (!state.user) return false
      // A screen with no permission of its own — Security is everyone's.
      if (!permission) return true
      if (state.user.is_super_admin || state.permissions.includes('*')) return true
      if (state.permissions.includes(permission)) return true

      const [group] = permission.split('.')

      return state.permissions.includes(`${group}.*`)
    },
  },

  actions: {
    async login({ email, password, tenant }) {
      if (tenant) localStorage.setItem('gymflow.tenant', tenant)
      else localStorage.removeItem('gymflow.tenant')

      const { data } = await api.post('/auth/login', { email, password, device_name: 'admin-panel' })

      this.applySession(data)

      return data
    },

    async registerClub(payload) {
      const { data } = await api.post('/clubs/register', payload)

      localStorage.setItem('gymflow.tenant', data.club.slug)
      localStorage.setItem('gymflow.token', data.token)

      this.user = data.user
      this.club = data.club
      this.permissions = ['*']

      return data
    },

    applySession(data) {
      localStorage.setItem('gymflow.token', data.token)

      if (data.club?.slug) localStorage.setItem('gymflow.tenant', data.club.slug)
      if (data.user?.locale) localStorage.setItem('gymflow.locale', data.user.locale)

      this.user = data.user
      this.club = data.club
      this.permissions = data.permissions || []
    },

    /** Restores the session on a page refresh. */
    async restore() {
      const token = localStorage.getItem('gymflow.token')

      if (!token) {
        this.ready = true

        return
      }

      try {
        const { data } = await api.get('/auth/me')

        this.user = data.user
        this.club = data.club
        this.permissions = data.permissions || []
      } catch {
        this.clear()
      } finally {
        this.ready = true
      }
    },

    async logout() {
      try {
        await api.post('/auth/logout')
      } catch {
        // The token is being discarded either way.
      }

      this.clear()
    },

    clear() {
      localStorage.removeItem('gymflow.token')
      this.user = null
      this.club = null
      this.permissions = []
    },
  },
})
