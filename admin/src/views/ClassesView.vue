<script setup>
import { computed, onMounted, ref } from 'vue'
import api, { errorMessage } from '@/api/client'
import { useUiStore } from '@/stores/ui'
import GlassCard from '@/components/GlassCard.vue'

const ui = useUiStore()

const classes = ref([])
const sessions = ref([])
const loading = ref(true)
const kind = ref('')

/** Sessions grouped by day, which is how the timetable reads. */
const byDay = computed(() => {
  const groups = {}

  for (const session of sessions.value) {
    const day = session.starts_at.slice(0, 10)
    groups[day] ??= []
    groups[day].push(session)
  }

  return Object.entries(groups).sort(([a], [b]) => a.localeCompare(b))
})

async function load() {
  loading.value = true

  const [classResponse, sessionResponse] = await Promise.all([
    api.get('/classes', { params: { kind: kind.value || undefined } }),
    api.get('/class-sessions', {
      params: {
        from: new Date().toISOString().slice(0, 10),
        to: new Date(Date.now() + 12096e5).toISOString().slice(0, 10),
      },
    }),
  ])

  classes.value = classResponse.data
  sessions.value = sessionResponse.data
  loading.value = false
}

async function cancelSession(session) {
  try {
    await api.post(`/class-sessions/${session.id}/cancel`)
    ui.notify(ui.t('general.saved', 'Session cancelled.'))
    load()
  } catch (error) {
    ui.notify(errorMessage(error), 'error')
  }
}

function occupancy(session) {
  return Math.round((session.booked_count / Math.max(1, session.capacity)) * 100)
}

onMounted(load)
</script>

<template>
  <div class="space-y-6">
    <div class="flex flex-wrap items-center gap-3">
      <select v-model="kind" class="field !w-auto" :aria-label="ui.t('classes.kind', 'Kind')" @change="load">
        <option value="">{{ ui.t('classes.all', 'All') }}</option>
        <option value="class">{{ ui.t('classes.class', 'Classes') }}</option>
        <option value="pool">{{ ui.t('classes.pool', 'Pool') }}</option>
        <option value="private">{{ ui.t('classes.private', 'Private') }}</option>
      </select>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
      <div v-for="item in classes" :key="item.id" class="glass glass-hover p-5">
        <div class="flex items-start justify-between gap-3">
          <div class="min-w-0">
            <p class="truncate font-semibold">{{ item.name?.[ui.locale] || item.name?.en }}</p>
            <p class="mt-1 text-xs text-ink-400">
              {{ item.coach?.first_name }} {{ item.coach?.last_name }}
            </p>
          </div>
          <span class="chip-muted">{{ item.kind }}</span>
        </div>

        <dl class="mt-4 grid grid-cols-3 gap-2 text-center text-xs">
          <div class="rounded-lg bg-white/5 py-2">
            <dt class="text-ink-400">{{ ui.t('classes.capacity', 'Seats') }}</dt>
            <dd class="mt-0.5 font-bold">{{ item.capacity }}</dd>
          </div>
          <div class="rounded-lg bg-white/5 py-2">
            <dt class="text-ink-400">{{ ui.t('classes.duration', 'Minutes') }}</dt>
            <dd class="mt-0.5 font-bold">{{ item.duration_minutes }}</dd>
          </div>
          <div class="rounded-lg bg-white/5 py-2">
            <dt class="text-ink-400">{{ ui.t('classes.price', 'Price') }}</dt>
            <dd class="mt-0.5 font-bold">{{ Number(item.price).toLocaleString() }}</dd>
          </div>
        </dl>
      </div>
    </div>

    <GlassCard :title="ui.t('classes.timetable', 'Timetable')" :subtitle="ui.t('classes.next_two_weeks', 'Next two weeks')">
      <div v-if="loading" class="py-10 text-center">
        <span class="inline-block size-6 animate-spin rounded-full border-2 border-white/20 border-t-brand" />
      </div>

      <p v-else-if="!byDay.length" class="py-8 text-center text-sm text-ink-400">
        {{ ui.t('classes.no_sessions', 'No sessions scheduled.') }}
      </p>

      <div v-else class="space-y-6">
        <div v-for="[day, daySessions] in byDay" :key="day">
          <h3 class="mb-2 text-xs font-semibold uppercase tracking-wider text-ink-400">
            {{ new Date(day).toLocaleDateString(undefined, { weekday: 'long', month: 'short', day: 'numeric' }) }}
          </h3>

          <ul class="space-y-2">
            <li
              v-for="session in daySessions"
              :key="session.id"
              class="flex flex-wrap items-center gap-3 rounded-xl bg-white/5 px-4 py-3"
            >
              <span class="font-mono text-sm">
                {{ session.starts_at.slice(11, 16) }}
              </span>

              <span class="min-w-0 flex-1 truncate text-sm font-medium">
                {{ session.gym_class?.name?.[ui.locale] || session.gym_class?.name?.en }}
              </span>

              <!-- The occupancy bar doubles as the capacity warning. -->
              <div class="flex items-center gap-2">
                <div class="h-1.5 w-24 overflow-hidden rounded-full bg-white/10">
                  <div
                    class="h-full rounded-full transition-all"
                    :class="occupancy(session) >= 100 ? 'bg-rose-400' : 'bg-brand'"
                    :style="{ width: `${Math.min(100, occupancy(session))}%` }"
                  />
                </div>
                <span class="w-16 text-end text-xs tabular-nums text-ink-400">
                  {{ session.booked_count }}/{{ session.capacity }}
                </span>
              </div>

              <span v-if="session.status !== 'scheduled'" class="chip-muted">{{ session.status }}</span>
              <button
                v-else
                class="btn-ghost !px-2.5 !py-1 text-xs"
                type="button"
                @click="cancelSession(session)"
              >
                {{ ui.t('general.cancel', 'Cancel') }}
              </button>
            </li>
          </ul>
        </div>
      </div>
    </GlassCard>
  </div>
</template>
