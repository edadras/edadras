<script setup>
import { nextTick, onMounted, ref } from 'vue'
import api, { errorMessage } from '@/api/client'
import { useUiStore } from '@/stores/ui'
import GlassCard from '@/components/GlassCard.vue'
import StatTile from '@/components/StatTile.vue'

const ui = useUiStore()

const overview = ref(null)
const loading = ref(true)
const question = ref('')
const thinking = ref(false)
const history = ref([])
const thread = ref(null)

async function load() {
  loading.value = true

  try {
    const { data } = await api.get('/ai/overview')
    overview.value = data
  } finally {
    loading.value = false
  }
}

async function refresh() {
  await api.post('/ai/refresh')
  ui.notify(ui.t('ai.refreshed', 'Insights refreshed.'))
  load()
}

async function ask() {
  const text = question.value.trim()

  if (!text || thinking.value) return

  history.value.push({ role: 'user', content: text })
  question.value = ''
  thinking.value = true

  await nextTick()
  thread.value?.scrollTo({ top: thread.value.scrollHeight, behavior: 'smooth' })

  try {
    const { data } = await api.post('/ai/chat', {
      question: text,
      history: history.value.slice(0, -1).slice(-10),
    })

    history.value.push({ role: 'assistant', content: data.answer })
  } catch (error) {
    history.value.push({ role: 'assistant', content: errorMessage(error) })
  } finally {
    thinking.value = false
    await nextTick()
    thread.value?.scrollTo({ top: thread.value.scrollHeight, behavior: 'smooth' })
  }
}

function severityClass(severity) {
  return { critical: 'chip-bad', warning: 'chip-warn' }[severity] || 'chip-muted'
}

onMounted(load)
</script>

<template>
  <div v-if="loading" class="grid place-items-center py-24">
    <span class="size-8 animate-spin rounded-full border-2 border-white/20 border-t-brand" />
  </div>

  <div v-else-if="overview" class="space-y-6">
    <div class="flex flex-wrap items-center gap-3">
      <h1 class="text-lg font-bold">✨ {{ ui.t('ai.title', 'Smart insights') }}</h1>
      <button class="btn-ghost ms-auto" type="button" @click="refresh">
        {{ ui.t('ai.refresh', 'Refresh') }}
      </button>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
      <StatTile
        :label="ui.t('ai.projected', 'Projected this month')"
        :value="overview.forecast.projected_total"
        icon="🔮"
        money
        :trend="overview.forecast.change_percent"
      />
      <StatTile
        :label="ui.t('ai.pipeline', 'Renewal pipeline')"
        :value="overview.forecast.renewal_pipeline"
        icon="🔁"
        money
      />
      <StatTile
        :label="ui.t('ai.peak_hour', 'Peak hour')"
        :value="`${overview.attendance.peak_hour}:00`"
        icon="⏰"
        :hint="`${overview.attendance.peak_hour_visits} ${ui.t('ai.visits', 'visits')}`"
      />
      <StatTile
        :label="ui.t('ai.attendance_trend', 'Attendance trend')"
        :value="ui.t(`ai.trend_${overview.attendance.trend}`, overview.attendance.trend)"
        icon="📶"
        :trend="overview.attendance.trend_percent"
      />
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
      <GlassCard
        :title="ui.t('ai.churn_risk', 'Members at risk')"
        :subtitle="ui.t('ai.churn_hint', 'Ranked by how likely they are to stop coming')"
      >
        <ul class="space-y-2">
          <li v-if="!overview.churn_risk.length" class="py-6 text-center text-sm text-ink-400">
            {{ ui.t('ai.no_risk', 'Nobody looks at risk right now.') }}
          </li>

          <li
            v-for="risk in overview.churn_risk"
            :key="risk.member.id"
            class="rounded-xl bg-white/5 p-3"
          >
            <div class="flex items-center gap-3">
              <span class="min-w-0 flex-1 truncate text-sm font-medium">
                {{ risk.member.first_name }} {{ risk.member.last_name }}
              </span>
              <span :class="severityClass(risk.severity)">{{ risk.score }}</span>
            </div>

            <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-white/10">
              <div
                class="h-full rounded-full"
                :class="risk.severity === 'critical' ? 'bg-rose-400' : 'bg-amber-300'"
                :style="{ width: `${risk.score}%` }"
              />
            </div>

            <p class="mt-2 text-xs text-ink-400">{{ risk.reasons.join(' ') }}</p>
          </li>
        </ul>
      </GlassCard>

      <GlassCard
        :title="ui.t('ai.campaigns', 'Suggested campaigns')"
        :subtitle="ui.t('ai.campaigns_hint', 'Ready to send from the CRM screen')"
      >
        <ul class="space-y-3">
          <li v-if="!overview.campaign_suggestions.length" class="py-6 text-center text-sm text-ink-400">
            {{ ui.t('ai.no_campaigns', 'No campaign is worth sending today.') }}
          </li>

          <li
            v-for="suggestion in overview.campaign_suggestions"
            :key="suggestion.key"
            class="rounded-xl bg-white/5 p-4"
          >
            <div class="flex items-center gap-2">
              <p class="min-w-0 flex-1 truncate text-sm font-semibold">{{ suggestion.title }}</p>
              <span class="chip-ok">{{ suggestion.channel }}</span>
            </div>
            <p class="mt-1.5 text-xs text-ink-200">{{ suggestion.body }}</p>
            <p class="mt-2 text-xs text-ink-400">
              {{ suggestion.recipients }} {{ ui.t('ai.recipients', 'recipients') }}
              <template v-if="suggestion.expected_value">
                · {{ Number(suggestion.expected_value).toLocaleString() }}
              </template>
            </p>
          </li>
        </ul>
      </GlassCard>
    </div>

    <GlassCard
      :title="ui.t('ai.assistant', 'Management assistant')"
      :subtitle="
        overview.assistant_available
          ? ui.t('ai.assistant_hint', 'Ask anything about this club')
          : ui.t('ai.assistant_unavailable', 'Not configured yet')
      "
    >
      <div ref="thread" class="max-h-96 space-y-3 overflow-y-auto pe-1">
        <p v-if="!history.length" class="py-6 text-center text-sm text-ink-400">
          {{ ui.t('ai.assistant_empty', 'e.g. “Which plan brings in the most revenue this month?”') }}
        </p>

        <div
          v-for="(turn, index) in history"
          :key="index"
          class="flex"
          :class="turn.role === 'user' ? 'justify-end' : 'justify-start'"
        >
          <p
            class="max-w-[80%] whitespace-pre-wrap rounded-2xl px-4 py-2.5 text-sm"
            :class="turn.role === 'user' ? 'bg-brand text-ink-900' : 'bg-white/8 text-white'"
          >
            {{ turn.content }}
          </p>
        </div>

        <div v-if="thinking" class="flex justify-start">
          <p class="rounded-2xl bg-white/8 px-4 py-2.5 text-sm text-ink-400">…</p>
        </div>
      </div>

      <form class="mt-4 flex gap-2" @submit.prevent="ask">
        <input
          v-model="question"
          class="field"
          :placeholder="ui.t('ai.ask_placeholder', 'Ask a question…')"
          :disabled="thinking"
        />
        <button class="btn-primary" type="submit" :disabled="thinking || !question.trim()">
          {{ ui.t('ai.send', 'Ask') }}
        </button>
      </form>
    </GlassCard>
  </div>
</template>
