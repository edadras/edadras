<script setup>
import { onMounted, reactive, ref, watch } from 'vue'
import api from '@/api/client'
import { useUiStore } from '@/stores/ui'
import GlassCard from '@/components/GlassCard.vue'

const ui = useUiStore()

const logs = ref([])
const loading = ref(true)
const expanded = ref(null)

const filters = reactive({
  action: '',
  from: '',
  to: '',
})

/** The prefixes worth filtering by, rather than every action ever written. */
const ACTIONS = ['auth', 'member', 'membership', 'payment', 'invoice', 'transaction', 'user', 'role', 'campaign']

async function load() {
  loading.value = true

  try {
    const { data } = await api.get('/club/audit-logs', {
      params: { ...filters, per_page: 50 },
    })

    logs.value = data.data
  } finally {
    loading.value = false
  }
}

/** Old and new side by side, for the columns that actually moved. */
function changes(log) {
  const keys = new Set([
    ...Object.keys(log.old_values ?? {}),
    ...Object.keys(log.new_values ?? {}),
  ])

  return [...keys].map((key) => ({
    key,
    before: log.old_values?.[key],
    after: log.new_values?.[key],
  }))
}

function subject(log) {
  if (!log.auditable_type) return '—'

  return `${log.auditable_type.split('\\').pop()} #${log.auditable_id}`
}

function format(value) {
  if (value === null || value === undefined || value === '') return '—'
  if (typeof value === 'object') return JSON.stringify(value)

  return String(value)
}

watch(filters, load)

onMounted(load)
</script>

<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-end gap-3">
      <div>
        <label class="label">{{ ui.t('audit.action', 'Action') }}</label>
        <select v-model="filters.action" class="field !w-auto">
          <option value="">{{ ui.t('members.all', 'All') }}</option>
          <option v-for="action in ACTIONS" :key="action" :value="action">{{ action }}</option>
        </select>
      </div>
      <div>
        <label class="label">{{ ui.t('reports.from', 'From') }}</label>
        <input v-model="filters.from" class="field !w-auto" type="date" />
      </div>
      <div>
        <label class="label">{{ ui.t('reports.to', 'To') }}</label>
        <input v-model="filters.to" class="field !w-auto" type="date" />
      </div>
    </div>

    <GlassCard :title="ui.t('audit.title', 'Audit trail')">
      <p class="mb-4 text-xs text-ink-400">
        {{ ui.t('audit.hint', 'Every change to members, money, staff and permissions, and who made it.') }}
      </p>

      <div v-if="loading" class="py-12 text-center">
        <span class="inline-block size-6 animate-spin rounded-full border-2 border-white/20 border-t-brand" />
      </div>

      <p v-else-if="!logs.length" class="py-10 text-center text-sm text-ink-400">
        {{ ui.t('audit.empty', 'Nothing recorded in this period.') }}
      </p>

      <div v-else class="overflow-x-auto">
        <table class="w-full min-w-max">
          <thead>
            <tr class="border-b border-white/8">
              <th class="table-head">{{ ui.t('audit.when', 'When') }}</th>
              <th class="table-head">{{ ui.t('audit.who', 'Who') }}</th>
              <th class="table-head">{{ ui.t('audit.action', 'Action') }}</th>
              <th class="table-head">{{ ui.t('audit.subject', 'Record') }}</th>
              <th class="table-head">{{ ui.t('audit.ip', 'Address') }}</th>
              <th class="table-head"></th>
            </tr>
          </thead>
          <tbody>
            <template v-for="log in logs" :key="log.id">
              <tr class="border-b border-white/5">
                <td class="table-cell whitespace-nowrap">
                  {{ new Date(log.created_at).toLocaleString() }}
                </td>
                <td class="table-cell">{{ log.user?.name ?? '—' }}</td>
                <td class="table-cell">
                  <span class="rounded-full bg-white/8 px-2.5 py-0.5 text-xs">{{ log.action }}</span>
                </td>
                <td class="table-cell">{{ subject(log) }}</td>
                <td class="table-cell text-ink-400">{{ log.ip_address ?? '—' }}</td>
                <td class="table-cell">
                  <button
                    v-if="log.old_values || log.new_values"
                    class="btn-ghost !px-3 !py-1 text-xs"
                    type="button"
                    @click="expanded = expanded === log.id ? null : log.id"
                  >
                    {{ expanded === log.id ? '−' : '+' }}
                  </button>
                </td>
              </tr>

              <tr v-if="expanded === log.id" class="border-b border-white/5 bg-white/3">
                <td class="px-4 py-3" colspan="6">
                  <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                    <div
                      v-for="change in changes(log)"
                      :key="change.key"
                      class="rounded-lg bg-white/5 px-3 py-2"
                    >
                      <p class="text-xs text-ink-400">{{ change.key }}</p>
                      <p class="mt-0.5 text-sm">
                        <span class="text-ink-400 line-through">{{ format(change.before) }}</span>
                        <span class="mx-1.5 text-brand">→</span>
                        <span>{{ format(change.after) }}</span>
                      </p>
                    </div>
                  </div>
                </td>
              </tr>
            </template>
          </tbody>
        </table>
      </div>
    </GlassCard>
  </div>
</template>
