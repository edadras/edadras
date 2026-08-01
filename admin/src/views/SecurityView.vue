<script setup>
import { onMounted, ref } from 'vue'
import api from '@/api/client'
import { useUiStore } from '@/stores/ui'
import GlassCard from '@/components/GlassCard.vue'

const ui = useUiStore()

const status = ref(null)
const setup = ref(null)
const code = ref('')
const password = ref('')
const freshCodes = ref(null)
const busy = ref(false)
const error = ref('')

async function load() {
  const { data } = await api.get('/auth/two-factor')

  status.value = data
}

async function begin() {
  busy.value = true
  error.value = ''

  try {
    const { data } = await api.post('/auth/two-factor')

    setup.value = data
  } finally {
    busy.value = false
  }
}

async function confirm() {
  busy.value = true
  error.value = ''

  try {
    await api.post('/auth/two-factor/confirm', { code: code.value })

    setup.value = null
    code.value = ''
    ui.notify(ui.t('auth.two_factor_enabled', 'Two factor authentication is on.'))
    await load()
  } catch (e) {
    error.value = e.response?.data?.errors?.code?.[0] ?? ui.t('auth.two_factor_invalid', 'That code is not right.')
  } finally {
    busy.value = false
  }
}

async function disable() {
  busy.value = true
  error.value = ''

  try {
    await api.delete('/auth/two-factor', { data: { password: password.value } })

    password.value = ''
    freshCodes.value = null
    ui.notify(ui.t('auth.two_factor_disabled', 'Two factor authentication is off.'))
    await load()
  } catch (e) {
    error.value = e.response?.data?.errors?.password?.[0] ?? ui.t('auth.password_mismatch', 'Wrong password.')
  } finally {
    busy.value = false
  }
}

async function regenerate() {
  const { data } = await api.post('/auth/two-factor/recovery-codes')

  freshCodes.value = data.recovery_codes
  await load()
}

function copyCodes(codes) {
  navigator.clipboard?.writeText(codes.join('\n'))
  ui.notify(ui.t('security.codes_copied', 'Recovery codes copied.'))
}

onMounted(load)
</script>

<template>
  <div class="grid gap-6 lg:grid-cols-2">
    <GlassCard :title="ui.t('security.two_factor', 'Two factor sign-in')">
      <p class="mb-5 text-sm leading-relaxed text-ink-400">
        {{ ui.t('security.two_factor_hint', 'An authenticator app on your phone generates a six digit code that is asked for after your password.') }}
      </p>

      <!-- Not set up yet -->
      <template v-if="status && !status.enabled && !setup">
        <button class="btn-primary" type="button" :disabled="busy" @click="begin">
          {{ ui.t('security.turn_on', 'Turn it on') }}
        </button>
      </template>

      <!-- Secret issued, waiting for the phone to prove it scanned -->
      <template v-else-if="setup">
        <div class="space-y-4">
          <div class="flex justify-center rounded-2xl bg-white p-4" v-html="setup.qr_svg" />

          <div>
            <p class="label">{{ ui.t('security.manual_key', 'Or type this key') }}</p>
            <code class="block break-all rounded-lg bg-white/5 px-3 py-2 text-xs">{{ setup.secret }}</code>
          </div>

          <div>
            <label class="label">{{ ui.t('security.code', 'Code from the app') }}</label>
            <input
              v-model="code"
              class="field text-center text-lg tracking-[0.4em]"
              type="text"
              inputmode="numeric"
              maxlength="6"
              @keyup.enter="confirm"
            />
          </div>

          <p v-if="error" class="text-sm text-danger">{{ error }}</p>

          <div class="flex gap-3">
            <button class="btn-primary" type="button" :disabled="busy || code.length < 6" @click="confirm">
              {{ ui.t('security.confirm', 'Confirm') }}
            </button>
            <button class="btn-ghost" type="button" @click="setup = null">
              {{ ui.t('general.cancel', 'Cancel') }}
            </button>
          </div>

          <div class="rounded-xl border border-warning/30 bg-warning/8 p-4">
            <p class="mb-2 text-xs font-semibold uppercase tracking-wider text-warning">
              {{ ui.t('security.recovery_codes', 'Recovery codes') }}
            </p>
            <p class="mb-3 text-xs text-ink-200">
              {{ ui.t('security.recovery_hint', 'Save these somewhere safe. Each one signs you in once if you lose your phone. They are shown only now.') }}
            </p>
            <div class="grid grid-cols-2 gap-1.5">
              <code v-for="line in setup.recovery_codes" :key="line" class="text-xs">{{ line }}</code>
            </div>
            <button class="btn-ghost mt-3 !px-3 !py-1 text-xs" type="button" @click="copyCodes(setup.recovery_codes)">
              {{ ui.t('security.copy', 'Copy') }}
            </button>
          </div>
        </div>
      </template>

      <!-- On -->
      <template v-else-if="status?.enabled">
        <div class="space-y-5">
          <div class="flex items-center gap-2 rounded-xl bg-brand/10 px-4 py-3">
            <span class="size-2 rounded-full bg-brand" />
            <span class="text-sm">
              {{ ui.t('security.on_since', 'On since') }}
              {{ new Date(status.confirmed_at).toLocaleDateString() }}
            </span>
          </div>

          <p class="text-sm text-ink-400">
            {{ status.recovery_codes_left }} {{ ui.t('security.codes_left', 'recovery codes left') }}
          </p>

          <div v-if="freshCodes" class="rounded-xl border border-warning/30 bg-warning/8 p-4">
            <div class="grid grid-cols-2 gap-1.5">
              <code v-for="line in freshCodes" :key="line" class="text-xs">{{ line }}</code>
            </div>
            <button class="btn-ghost mt-3 !px-3 !py-1 text-xs" type="button" @click="copyCodes(freshCodes)">
              {{ ui.t('security.copy', 'Copy') }}
            </button>
          </div>

          <button class="btn-ghost" type="button" @click="regenerate">
            {{ ui.t('security.new_codes', 'Issue new recovery codes') }}
          </button>

          <div class="border-t border-white/8 pt-5">
            <label class="label">{{ ui.t('auth.password_label', 'Password') }}</label>
            <input v-model="password" class="field" type="password" autocomplete="current-password" />
            <p v-if="error" class="mt-2 text-sm text-danger">{{ error }}</p>
            <button
              class="btn-ghost mt-3 !text-danger"
              type="button"
              :disabled="busy || !password"
              @click="disable"
            >
              {{ ui.t('security.turn_off', 'Turn it off') }}
            </button>
          </div>
        </div>
      </template>
    </GlassCard>

    <GlassCard :title="ui.t('security.advice', 'Keeping the club safe')">
      <ul class="space-y-4 text-sm leading-relaxed text-ink-200">
        <li class="flex gap-3">
          <span class="text-brand">•</span>
          <span>{{ ui.t('security.tip_roles', 'Give each person the smallest role that lets them do their job. Reception does not need the books.') }}</span>
        </li>
        <li class="flex gap-3">
          <span class="text-brand">•</span>
          <span>{{ ui.t('security.tip_audit', 'The audit trail records every change to members, money and permissions, with who made it.') }}</span>
        </li>
        <li class="flex gap-3">
          <span class="text-brand">•</span>
          <span>{{ ui.t('security.tip_backup', 'Backups run nightly once switched on, and old archives are pruned automatically.') }}</span>
        </li>
        <li class="flex gap-3">
          <span class="text-brand">•</span>
          <span>{{ ui.t('security.tip_leaver', 'When someone leaves, disable their account rather than sharing a login.') }}</span>
        </li>
      </ul>
    </GlassCard>
  </div>
</template>
