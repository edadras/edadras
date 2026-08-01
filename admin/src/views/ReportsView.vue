<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import api from '@/api/client'
import { useUiStore } from '@/stores/ui'
import GlassCard from '@/components/GlassCard.vue'
import LineChart from '@/components/LineChart.vue'

const ui = useUiStore()

const catalogue = ref([])
const groups = ref([])
const total = ref(0)
const search = ref('')
const openGroup = ref(null)

const active = ref(null)
const result = ref(null)
const loading = ref(false)

const from = ref(new Date(Date.now() - 30 * 864e5).toISOString().slice(0, 10))
const to = ref(new Date().toISOString().slice(0, 10))
const days = ref(30)
const months = ref(12)
const date = ref(new Date().toISOString().slice(0, 10))

const activeReport = computed(() => catalogue.value.find((report) => report.key === active.value))

/** Only the controls this particular report actually reads. */
const wants = computed(() => new Set(activeReport.value?.params ?? []))

const matching = computed(() => {
  const term = search.value.trim().toLowerCase()

  if (!term) return catalogue.value

  return catalogue.value.filter(
    (report) => report.label.toLowerCase().includes(term) || report.key.includes(term),
  )
})

const grouped = computed(() => {
  const buckets = {}

  for (const report of matching.value) {
    buckets[report.group] ??= []
    buckets[report.group].push(report)
  }

  return buckets
})

function groupLabel(key) {
  return groups.value.find((group) => group.key === key)?.label ?? key
}

/** A report comes back as a time series, a list of rows, or a single map. */
const shape = computed(() => {
  const data = result.value?.data

  if (data === null || data === undefined) return 'none'

  if (Array.isArray(data)) {
    if (!data.length) return 'empty'

    return data[0]?.date || data[0]?.hour || data[0]?.month ? 'series' : 'list'
  }

  return typeof data === 'object' ? 'map' : 'scalar'
})

const seriesKey = computed(() => {
  const first = result.value?.data?.[0] ?? {}

  return first.date ? 'date' : first.month ? 'month' : 'hour'
})

/** Charts want {date,total}; monthly and hourly series are relabelled to fit. */
const series = computed(() =>
  (result.value?.data ?? []).map((row) => ({
    date: row[seriesKey.value],
    total: row.total ?? row.net ?? row.income ?? 0,
  })),
)

const listColumns = computed(() => {
  const first = result.value?.data?.[0]

  return first ? Object.keys(first).filter((key) => typeof first[key] !== 'object') : []
})

function params() {
  return {
    from: from.value,
    to: to.value,
    days: days.value,
    months: months.value,
    date: date.value,
    limit: 50,
  }
}

async function run(key) {
  if (!key) return

  active.value = key
  loading.value = true

  try {
    const { data } = await api.get(`/reports/${key}`, { params: params() })

    result.value = data
  } finally {
    loading.value = false
  }
}

function exportCsv() {
  const token = localStorage.getItem('gymflow.token')
  const tenant = localStorage.getItem('gymflow.tenant')
  const base = import.meta.env.VITE_API_URL || '/api/v1'
  const query = new URLSearchParams(params()).toString()

  // The download goes through fetch so the auth headers ride along.
  fetch(`${base}/reports/${active.value}/export?${query}`, {
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

/** `income_by_category` reads better as "Income by category". */
function humanise(key) {
  return String(key).replace(/_/g, ' ').replace(/^./, (c) => c.toUpperCase())
}

/** A map value that is itself a list of {label,total} rows. */
function isBreakdown(value) {
  return Array.isArray(value) && value.length > 0 && typeof value[0] === 'object'
}

function cell(value) {
  if (value === null || value === undefined || value === '') return '—'
  if (typeof value === 'number') return value.toLocaleString()
  if (typeof value === 'boolean') return value ? '✓' : '—'

  return value
}

// Typing narrows the list; opening the only remaining group saves a click.
watch(search, (term) => {
  const keys = Object.keys(grouped.value)

  if (term && keys.length === 1) openGroup.value = keys[0]
})

onMounted(async () => {
  const { data } = await api.get('/reports')

  catalogue.value = data.reports
  groups.value = data.groups
  total.value = data.total
  openGroup.value = data.groups[0]?.key ?? null

  run('profit_and_loss')
})
</script>

<template>
  <div class="grid gap-6 lg:grid-cols-[300px_minmax(0,1fr)]">
    <GlassCard :title="`${ui.t('nav.reports', 'Reports')} · ${total}`">
      <input
        v-model="search"
        class="field mb-3"
        type="search"
        :placeholder="ui.t('reports.search', 'Search reports…')"
      />

      <div class="max-h-[70vh] space-y-1.5 overflow-y-auto pe-1">
        <div v-for="(reports, group) in grouped" :key="group">
          <button
            type="button"
            class="flex w-full items-center justify-between rounded-lg px-2 py-1.5 text-xs font-semibold uppercase tracking-wider text-ink-400 transition hover:text-ink-200"
            @click="openGroup = openGroup === group ? null : group"
          >
            <span>{{ groupLabel(group) }}</span>
            <span class="tabular-nums opacity-60">{{ reports.length }}</span>
          </button>

          <div v-show="openGroup === group || search" class="mb-2 space-y-0.5">
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

        <p v-if="!Object.keys(grouped).length" class="py-6 text-center text-sm text-ink-400">
          {{ ui.t('reports.no_match', 'No report matches that.') }}
        </p>
      </div>
    </GlassCard>

    <div class="space-y-4">
      <div class="flex flex-wrap items-end gap-3">
        <template v-if="wants.has('range')">
          <div>
            <label class="label">{{ ui.t('reports.from', 'From') }}</label>
            <input v-model="from" class="field !w-auto" type="date" @change="run(active)" />
          </div>
          <div>
            <label class="label">{{ ui.t('reports.to', 'To') }}</label>
            <input v-model="to" class="field !w-auto" type="date" @change="run(active)" />
          </div>
        </template>

        <div v-if="wants.has('days')">
          <label class="label">{{ ui.t('reports.days', 'Days') }}</label>
          <select v-model.number="days" class="field !w-auto" @change="run(active)">
            <option :value="7">7</option>
            <option :value="30">30</option>
            <option :value="90">90</option>
            <option :value="365">365</option>
          </select>
        </div>

        <div v-if="wants.has('months')">
          <label class="label">{{ ui.t('reports.months', 'Months') }}</label>
          <select v-model.number="months" class="field !w-auto" @change="run(active)">
            <option :value="6">6</option>
            <option :value="12">12</option>
            <option :value="24">24</option>
          </select>
        </div>

        <div v-if="wants.has('date')">
          <label class="label">{{ ui.t('finance.date', 'Date') }}</label>
          <input v-model="date" class="field !w-auto" type="date" @change="run(active)" />
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
          <LineChart v-if="shape === 'series'" :series="series" :label="result.label" />

          <dl v-else-if="shape === 'map'" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <div
              v-for="(value, key) in result.data"
              :key="key"
              class="rounded-xl bg-white/5 p-4"
              :class="isBreakdown(value) ? 'sm:col-span-2 lg:col-span-1' : ''"
            >
              <dt class="text-xs text-ink-400">{{ humanise(key) }}</dt>

              <!-- A nested breakdown is worth showing, not counting. -->
              <dd v-if="isBreakdown(value)" class="mt-2 space-y-1">
                <div
                  v-for="(row, index) in value.slice(0, 6)"
                  :key="index"
                  class="flex items-baseline justify-between gap-3 text-sm"
                >
                  <span class="truncate text-ink-200">{{ row.label ?? row.month ?? row.date ?? '—' }}</span>
                  <span class="font-semibold tabular-nums">{{ cell(row.total ?? row.delivered ?? row.at_cost) }}</span>
                </div>
                <p v-if="value.length > 6" class="text-xs text-ink-400">
                  +{{ value.length - 6 }}
                </p>
              </dd>

              <dd v-else-if="Array.isArray(value) || (typeof value === 'object' && value)" class="mt-1 text-lg font-bold text-ink-400">
                —
              </dd>

              <dd v-else class="mt-1 text-lg font-bold tabular-nums">{{ cell(value) }}</dd>
            </div>
          </dl>

          <div v-else-if="shape === 'list'" class="overflow-x-auto">
            <table class="w-full min-w-max">
              <thead>
                <tr class="border-b border-white/8">
                  <th v-for="column in listColumns" :key="column" class="table-head">{{ column }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(row, index) in result.data" :key="index" class="border-b border-white/5">
                  <td v-for="column in listColumns" :key="column" class="table-cell">
                    {{ cell(row[column]) }}
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <p v-else-if="shape === 'scalar'" class="py-8 text-center text-3xl font-bold tabular-nums">
            {{ cell(result.data) }}
          </p>

          <p v-else class="py-10 text-center text-sm text-ink-400">
            {{ ui.t('reports.no_data', 'No data for this period.') }}
          </p>
        </template>
      </GlassCard>
    </div>
  </div>
</template>
