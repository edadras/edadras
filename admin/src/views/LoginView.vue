<script setup>
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'
import { errorMessage } from '@/api/client'

const auth = useAuthStore()
const ui = useUiStore()
const router = useRouter()
const route = useRoute()

const form = ref({
  tenant: localStorage.getItem('gymflow.tenant') || '',
  email: '',
  password: '',
})
const loading = ref(false)
const error = ref(null)

async function submit() {
  loading.value = true
  error.value = null

  try {
    await auth.login(form.value)
    await ui.loadTranslations(ui.locale)
    router.push(route.query.redirect || { name: 'dashboard' })
  } catch (e) {
    error.value = errorMessage(e, ui.t('auth.failed', 'Sign in failed.'))
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="grid min-h-screen place-items-center p-4">
    <div class="glass animate-rise w-full max-w-md p-7">
      <div class="mb-7 text-center">
        <span
          class="mx-auto mb-4 grid size-14 place-items-center rounded-3xl text-2xl font-black text-ink-900"
          style="background: linear-gradient(150deg, #8ff8b6, #2fd96b)"
          aria-hidden="true"
        >
          G
        </span>
        <h1 class="text-xl font-bold">GymFlow AI</h1>
        <p class="mt-1 text-sm text-ink-400">
          {{ ui.t('auth.sign_in_subtitle', 'Sign in to your club') }}
        </p>
      </div>

      <form class="space-y-4" @submit.prevent="submit">
        <div>
          <label class="label" for="tenant">{{ ui.t('auth.club', 'Club') }}</label>
          <input
            id="tenant"
            v-model="form.tenant"
            class="field"
            autocomplete="organization"
            :placeholder="ui.t('auth.club_placeholder', 'club-slug (leave empty for platform admin)')"
          />
        </div>

        <div>
          <label class="label" for="email">{{ ui.t('auth.email', 'Email') }}</label>
          <input id="email" v-model="form.email" class="field" type="email" required autocomplete="email" />
        </div>

        <div>
          <label class="label" for="password">{{ ui.t('auth.password_label', 'Password') }}</label>
          <input
            id="password"
            v-model="form.password"
            class="field"
            type="password"
            required
            autocomplete="current-password"
          />
        </div>

        <p v-if="error" class="rounded-xl bg-rose-500/15 px-3 py-2 text-sm text-rose-200" role="alert">
          {{ error }}
        </p>

        <button class="btn-primary w-full" type="submit" :disabled="loading">
          {{ loading ? '…' : ui.t('auth.sign_in', 'Sign in') }}
        </button>
      </form>

      <p class="mt-6 text-center text-sm text-ink-400">
        {{ ui.t('auth.no_club', 'No club yet?') }}
        <RouterLink :to="{ name: 'register' }" class="font-semibold text-brand hover:underline">
          {{ ui.t('auth.create_club', 'Create one') }}
        </RouterLink>
      </p>
    </div>
  </div>
</template>
