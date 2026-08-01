<script setup>
import { computed, onMounted, ref } from 'vue'
import api from '@/api/client'
import { useUiStore } from '@/stores/ui'
import GlassCard from '@/components/GlassCard.vue'
import LineChart from '@/components/LineChart.vue'

const ui = useUiStore()

const catalogue = ref([])
const active = ref(null)
const result = ref(null)
const loading = ref(false)
const from = ref(new Date(Date.now() - 30 * 864e5).toISOString().slice(0, 10))
const to = ref(new Date().toISOString().slice(0, 10))

const grouped = computed(() => {
  const groups = {}

  for (const report of catalogue.value) {
    groups[report.group] ??= []
    groups[report.group].push(report)
  }

  return groups
})

/** A report can come back as a list, a map, or a time series. */
const shape = computed(() => {
  const data = result.value?.data

  if (!data) return 'none'
  if (Array.isArray(data)) {
    return data.length && (data[0]?.date || data[0]?.hour) ? 'series' : 'list'
  }

  return 'map'
})

const listColumns = computed(() => {
  const first = result.value?.data?.[0]

  return first ? Object.keys(first).filter((key) => typeof first[key] !== 'object') : []
})

async function run(key) {
  active.value = key
  loading.value = true

  try {
    const { data } = await api.get(`/reports/${key}`, { params: { from: from.value, to: to.value } })

    result.value = data
  } finally {
    loading.value = false
  }
}

function exportCsv() {
  const token = localStorage.getItem('gymflow.token')
  const tenant = localStorage.getItem('gymflow.tenant')
  const base = import.meta.env.VITE_API_URL || '/api/v1'

  // The download goes through fetch so the auth headers ride along.
  fetch(`${base}/reports/${active.value}/export?from=${from.value}&to=${to.value}`, {
    headers: { Authorization: `Bearer ${token}`, 'X-Tenant': tenant },
  })
    .then((response) => response.blob())
    .then((blob) => {
      const link = document.createElement('a')
      link.href = URL.createObjectURL(blob)
      link.download = `${active.value}.csv`
      link.click()
      URL.revokeObjectURL(link.href)
    })
}

onMounted(async () => {
  const { data } = await api.get('/reports')

  catalogue.value = data
  run('profit_and_loss')
})
</script>

<template>
  <div class="grid gap-6 lg:grid-cols-[280px_minmax(0,1fr)]">
    <GlassCard :title="ui.t('nav.reports', 'Reports')">
      <div v-for="(reports, group) in grouped" :key="group" class="mb-4">
        <p class="mb-1.5 text-xs font-semibold uppercase tracking-wider text-ink-400">{{ group }}</p>
        <div class="space-y-1">
          <button
            v-for="report in reports"
            :key="report.key"
            type="button"
            class="w-full rounded-lg px-3 py-2 text-start text-sm transition"
            :class="active === report.key ? 'bg-brand/15 text-brand' : 'text-ink-200 hover:bg-white/8'"
            @click="run(report.key)"
          >
            {{ report.label }}
          </button>
        </div>
      </div>
    </GlassCard>

    <div class="space-y-4">
      <div class="flex flex-wrap items-end gap-3">
        <div>
          <label class="label">{{ ui.t('reports.from', 'From') }}</label>
          <input v-model="from" class="field !w-auto" type="date" @change="run(active)" />
        </div>
        <div>
          <label class="label">{{ ui.t('reports.to', 'To') }}</label>
          <input v-model="to" class="field !w-auto" type="date" @change="run(active)" />
        </div>
        <button class="btn-ghost" type="button" :disabled="!active" @click="exportCsv">
          ⬇ {{ ui.t('reports.export', 'Export CSV') }}
        </button>
      </div>

      <GlassCard :title="result?.label || ui.t('nav.reports', 'Reports')">
        <div v-if="loading" class="py-12 text-center">
          <span class="inline-block size-6 animate-spin rounded-full border-2 border-white/20 border-t-brand" />
        </div>

        <template v-else-if="result">
          <LineChart v-if="shape === 'series'" :series="result.data" :label="result.label" />

          <dl v-else-if="shape === 'map'" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <div
              v-for="(value, key) in result.data"
              :key="key"
              class="rounded-xl bg-white/5 p-4"
            >
              <dt class="text-xs text-ink-400">{{ key }}</dt>
              <dd class="mt-1 text-lg font-bold tabular-nums">
                {{ typeof value === 'object' ? JSON.stringify(value) : Number(value).toLocaleString() }}
              </dd>
            </div>
          </dl>

          <div v-else-if="shape === 'list' && result.data.length" class="overflow-x-auto">
            <table class="w-full min-w-max">
              <thead>
                <tr class="border-b border-white/8">
                  <th v-for="column in listColumns" :key="column" class="table-head">{{ column }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(row, index) in result.data" :key="index" class="border-b border-white/5">
                  <td v-for="column in listColumns" :key="column" class="table-cell">
                    {{ typeof row[column] === 'number' ? row[column].toLocaleString() : (row[column] ?? '—') }}
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <p v-else class="py-10 text-center text-sm text-ink-400">
            {{ ui.t('reports.no_data', 'No data for this period.') }}
          </p>
        </template>
      </GlassCard>
    </div>
  </div>
</template>
