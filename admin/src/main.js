import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import router from './router'
import { handleUnauthenticated } from './api/client'
import { useAuthStore } from './stores/auth'
import { useUiStore } from './stores/ui'
import './style.css'

const app = createApp(App)

app.use(createPinia())
app.use(router)

// An expired token should drop straight back to the sign in screen rather
// than leaving half rendered panels behind.
handleUnauthenticated(() => {
  useAuthStore().clear()
  router.push({ name: 'login' })
})

const ui = useUiStore()

Promise.all([ui.loadLocales(), ui.loadTranslations()])
  .catch(() => {
    // The panel still renders with key fallbacks if the API is unreachable.
  })
  .finally(() => app.mount('#app'))
