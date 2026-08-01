<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'
import { errorMessage } from '@/api/client'

const auth = useAuthStore()
const ui = useUiStore()
const router = useRouter()

const types = [
  { value: 'gym', icon: '🏋️', label: 'Gym' },
  { value: 'pool', icon: '🏊', label: 'Pool' },
  { value: 'martial_arts', icon: '🥋', label: 'Martial arts' },
  { value: 'yoga', icon: '🧘', label: 'Yoga' },
  { value: 'pilates', icon: '🤸', label: 'Pilates' },
  { value: 'crossfit', icon: '🏃', label: 'CrossFit' },
  { value: 'football', icon: '⚽', label: 'Football' },
  { value: 'multi', icon: '🏟️', label: 'Multi-sport' },
]

const form = ref({
  club: { name: '', type: 'gym', phone: '', address: '', locale: ui.locale, currency: 'IRR' },
  owner: { name: '', email: '', phone: '', password: '' },
  plan: 'free',
})
const loading = ref(false)
const error = ref(null)

async function submit() {
  loading.value = true
  error.value = null

  try {
    await auth.registerClub(form.value)
    ui.notify(ui.t('general.saved', 'Club created.'))
    router.push({ name: 'dashboard' })
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="grid min-h-screen place-items-center p-4">
    <div class="glass animate-rise w-full max-w-2xl p-7">
      <h1 class="text-xl font-bold">{{ ui.t('auth.create_club', 'Create your club') }}</h1>
      <p class="mt-1 text-sm text-ink-400">
        {{ ui.t('auth.create_club_subtitle', 'Your club gets its own isolated space, apps and data.') }}
      </p>

      <form class="mt-6 space-y-6" @submit.prevent="submit">
        <fieldset>
          <legend class="label">{{ ui.t('auth.club_type', 'What kind of club is it?') }}</legend>
          <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
            <button
              v-for="type in types"
              :key="type.value"
              type="button"
              class="glass glass-hover flex flex-col items-center gap-1 px-2 py-3 text-xs"
              :class="form.club.type === type.value ? 'border-brand/60 bg-brand/10 text-brand' : 'text-ink-200'"
              @click="form.club.type = type.value"
            >
              <span class="text-xl" aria-hidden="true">{{ type.icon }}</span>
              {{ type.label }}
            </button>
          </div>
        </fieldset>

        <div class="grid gap-4 sm:grid-cols-2">
          <div>
            <label class="label" for="club-name">{{ ui.t('auth.club_name', 'Club name') }}</label>
            <input id="club-name" v-model="form.club.name" class="field" required />
          </div>
          <div>
            <label class="label" for="club-phone">{{ ui.t('auth.phone', 'Phone') }}</label>
            <input id="club-phone" v-model="form.club.phone" class="field" />
          </div>
          <div class="sm:col-span-2">
            <label class="label" for="club-address">{{ ui.t('auth.address', 'Address') }}</label>
            <input id="club-address" v-model="form.club.address" class="field" />
          </div>
        </div>

        <hr class="border-white/8" />

        <div class="grid gap-4 sm:grid-cols-2">
          <div>
            <label class="label" for="owner-name">{{ ui.t('auth.owner_name', 'Your name') }}</label>
            <input id="owner-name" v-model="form.owner.name" class="field" required />
          </div>
          <div>
            <label class="label" for="owner-email">{{ ui.t('auth.email', 'Email') }}</label>
            <input id="owner-email" v-model="form.owner.email" class="field" type="email" required />
          </div>
          <div>
            <label class="label" for="owner-phone">{{ ui.t('auth.phone', 'Phone') }}</label>
            <input id="owner-phone" v-model="form.owner.phone" class="field" />
          </div>
          <div>
            <label class="label" for="owner-password">{{ ui.t('auth.password_label', 'Password') }}</label>
            <input
              id="owner-password"
              v-model="form.owner.password"
              class="field"
              type="password"
              minlength="8"
              required
              autocomplete="new-password"
            />
          </div>
        </div>

        <p v-if="error" class="rounded-xl bg-rose-500/15 px-3 py-2 text-sm text-rose-200" role="alert">
          {{ error }}
        </p>

        <div class="flex gap-3">
          <button class="btn-primary flex-1" type="submit" :disabled="loading">
            {{ loading ? '…' : ui.t('auth.create_club', 'Create club') }}
          </button>
          <RouterLink class="btn-ghost" :to="{ name: 'login' }">
            {{ ui.t('auth.sign_in', 'Sign in') }}
          </RouterLink>
        </div>
      </form>
    </div>
  </div>
</template>
