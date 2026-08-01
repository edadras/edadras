<script setup>
import { onMounted, ref } from 'vue'
import api, { errorMessage } from '@/api/client'
import { useUiStore } from '@/stores/ui'
import GlassCard from '@/components/GlassCard.vue'

const ui = useUiStore()

const code = ref('')
const busy = ref(false)
const result = ref(null)
const inside = ref([])
const today = ref([])

async function refresh() {
  const [insideResponse, todayResponse] = await Promise.all([
    api.get('/attendance/inside'),
    api.get('/attendance', { params: { period: 'today', per_page: 15 } }),
  ])

  inside.value = insideResponse.data
  today.value = todayResponse.data.data
}

/**
 * A scanner is just a keyboard: it types the token and presses enter. The
 * same box therefore works for a handheld reader and for typing a code.
 */
async function scan(action = 'check-in') {
  if (!code.value.trim() || busy.value) return

  busy.value = true
  result.value = null

  try {
    const { data } = await api.post(`/attendance/${action}`, { qr_token: code.value.trim(), method: 'qr' })

    result.value = { ok: true, ...data }
    ui.notify(data.message)
  } catch (error) {
    result.value = {
      ok: false,
      message: errorMessage(error, ui.t('checkin.unknown_code', 'Unknown code.')),
      reason: error.response?.data?.reason,
    }
  } finally {
    code.value = ''
    busy.value = false
    refresh()
  }
}

onMounted(refresh)
</script>

<template>
  <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_360px]">
    <div class="space-y-6">
      <GlassCard :title="ui.t('nav.check_in', 'Check-in')" :subtitle="ui.t('checkin.scan_hint', 'Scan a QR badge or type the code')">
        <form class="flex gap-3" @submit.prevent="scan('check-in')">
          <input
            v-model="code"
            class="field font-mono"
            :placeholder="ui.t('checkin.code_placeholder', 'Scan or type a code…')"
            autofocus
            :disabled="busy"
          />
          <button class="btn-primary" type="submit" :disabled="busy">
            {{ ui.t('checkin.enter', 'Enter') }}
          </button>
          <button class="btn-ghost" type="button" :disabled="busy" @click="scan('check-out')">
            {{ ui.t('checkin.exit', 'Exit') }}
          </button>
        </form>

        <!-- The green or red card the receptionist reads at a glance. -->
        <Transition
          enter-active-class="transition duration-300 ease-out"
          enter-from-class="scale-95 opacity-0"
        >
          <div
            v-if="result"
            class="glass glass-strong mt-5 flex items-center gap-4 p-5"
            :class="result.ok ? 'border-brand/50' : 'border-rose-500/50'"
          >
            <span
              class="grid size-14 shrink-0 place-items-center rounded-2xl text-2xl"
              :class="result.ok ? 'bg-brand/20 animate-pulse-ring' : 'bg-rose-500/20'"
              aria-hidden="true"
            >
              {{ result.ok ? '✅' : '⛔' }}
            </span>

            <div class="min-w-0">
              <p class="text-lg font-bold" :class="result.ok ? 'text-brand' : 'text-rose-300'">
                {{ result.message }}
              </p>
              <p v-if="result.member" class="mt-0.5 truncate text-sm text-white">
                {{ result.member.first_name }} {{ result.member.last_name }} · #{{ result.member.code }}
              </p>
              <p v-if="result.ok" class="mt-1 text-xs text-ink-400">
                <template v-if="result.remaining_sessions !== null">
                  {{ ui.t('checkin.sessions_left', 'Sessions left') }}: {{ result.remaining_sessions }}
                </template>
                <template v-if="result.days_remaining !== null">
                  · {{ ui.t('checkin.days_left', 'Days left') }}: {{ result.days_remaining }}
                </template>
              </p>
            </div>
          </div>
        </Transition>
      </GlassCard>

      <GlassCard :title="ui.t('checkin.today', 'Today')">
        <ul class="divide-y divide-white/5">
          <li v-if="!today.length" class="py-8 text-center text-sm text-ink-400">
            {{ ui.t('checkin.no_entries', 'No entries yet today.') }}
          </li>
          <li v-for="entry in today" :key="entry.id" class="flex items-center gap-3 py-3">
            <span class="grid size-9 place-items-center rounded-full bg-white/8 text-xs font-bold">
              {{ (entry.member?.first_name || '?').slice(0, 1) }}
            </span>
            <div class="min-w-0 flex-1">
              <p class="truncate text-sm">{{ entry.member?.first_name }} {{ entry.member?.last_name }}</p>
              <p class="text-xs text-ink-400">
                {{ new Date(entry.checked_in_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) }}
                · {{ entry.method }}
              </p>
            </div>
            <span :class="entry.checked_out_at ? 'chip-muted' : 'chip-ok'">
              {{ entry.checked_out_at ? ui.t('checkin.left', 'left') : ui.t('checkin.inside', 'inside') }}
            </span>
          </li>
        </ul>
      </GlassCard>
    </div>

    <GlassCard :title="ui.t('checkin.inside_now', 'Inside now')" :subtitle="`${inside.length}`">
      <ul class="space-y-2">
        <li v-if="!inside.length" class="py-6 text-center text-sm text-ink-400">
          {{ ui.t('checkin.empty_gym', 'The club is empty.') }}
        </li>
        <li
          v-for="entry in inside"
          :key="entry.id"
          class="flex items-center gap-3 rounded-xl bg-white/5 px-3 py-2"
        >
          <span class="size-2 rounded-full bg-brand" aria-hidden="true" />
          <span class="min-w-0 flex-1 truncate text-sm">
            {{ entry.member?.first_name }} {{ entry.member?.last_name }}
          </span>
          <span class="text-xs text-ink-400">
            {{ new Date(entry.checked_in_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) }}
          </span>
        </li>
      </ul>
    </GlassCard>
  </div>
</template>
