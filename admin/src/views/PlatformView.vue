<script setup>
import { onMounted, ref } from 'vue'
import api, { errorMessage } from '@/api/client'
import { useUiStore } from '@/stores/ui'
import GlassCard from '@/components/GlassCard.vue'
import StatTile from '@/components/StatTile.vue'
import DataTable from '@/components/DataTable.vue'

const ui = useUiStore()

const stats = ref(null)
const tenants = ref([])
const plans = ref([])
const loading = ref(true)

const columns = [
  { key: 'name', label: ui.t('platform.club', 'Club') },
  { key: 'type', label: ui.t('platform.type', 'Type') },
  { key: 'plan', label: ui.t('platform.plan', 'Plan') },
  { key: 'users_count', label: ui.t('platform.staff', 'Staff') },
  { key: 'status', label: ui.t('members.status', 'Status') },
  { key: 'actions', label: '' },
]

async function load() {
  loading.value = true

  const [statsResponse, tenantResponse, planResponse] = await Promise.all([
    api.get('/platform/dashboard'),
    api.get('/platform/tenants', { params: { per_page: 50 } }),
    api.get('/platform/plans'),
  ])

  stats.value = statsResponse.data
  tenants.value = tenantResponse.data.data
  plans.value = planResponse.data
  loading.value = false
}

async function toggleStatus(tenant) {
  try {
    await api.put(`/platform/tenants/${tenant.id}`, {
      status: tenant.status === 'active' ? 'suspended' : 'active',
    })

    ui.notify(ui.t('general.saved', 'Updated.'))
    load()
  } catch (error) {
    ui.notify(errorMessage(error), 'error')
  }
}

onMounted(load)
</script>

<template>
  <div v-if="loading" class="grid place-items-center py-24">
    <span class="size-8 animate-spin rounded-full border-2 border-white/20 border-t-brand" />
  </div>

  <div v-else-if="stats" class="space-y-6">
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
      <StatTile :label="ui.t('platform.clubs', 'Clubs')" :value="stats.clubs.total" icon="🏟️"
        :hint="`${stats.clubs.active} ${ui.t('dash.active', 'active')}`" />
      <StatTile :label="ui.t('platform.mrr', 'Monthly recurring revenue')" :value="stats.revenue.mrr" icon="💎" money />
      <StatTile :label="ui.t('platform.members', 'Members on the platform')" :value="stats.users.members" icon="👥" />
      <StatTile :label="ui.t('platform.new_clubs', 'New clubs this month')" :value="stats.clubs.new_this_month" icon="🌱" />
    </div>

    <div class="grid gap-4 lg:grid-cols-3">
      <GlassCard :title="ui.t('platform.by_plan', 'Subscriptions by plan')" class="lg:col-span-1">
        <ul class="space-y-2">
          <li
            v-for="row in stats.revenue.by_plan"
            :key="row.plan"
            class="flex items-center justify-between rounded-lg bg-white/5 px-3 py-2 text-sm"
          >
            <span>{{ row.plan }}</span>
            <span class="text-xs text-ink-400">{{ row.clubs }} · {{ Number(row.revenue).toLocaleString() }}</span>
          </li>
        </ul>
      </GlassCard>

      <GlassCard :title="ui.t('platform.by_type', 'Clubs by type')" class="lg:col-span-2">
        <div class="flex flex-wrap gap-2">
          <span v-for="(count, type) in stats.clubs.by_type" :key="type" class="chip-ok">
            {{ type }} · {{ count }}
          </span>
        </div>
      </GlassCard>
    </div>

    <GlassCard :title="ui.t('platform.all_clubs', 'All clubs')" :padded="false">
      <DataTable :columns="columns" :rows="tenants" :empty="ui.t('platform.no_clubs', 'No clubs yet.')">
        <template #cell-name="{ row }">
          <div>
            <p class="font-medium">{{ row.name }}</p>
            <p class="text-xs text-ink-400">{{ row.slug }}</p>
          </div>
        </template>

        <template #cell-plan="{ row }">
          {{ row.active_subscription?.plan?.name?.[ui.locale] || row.active_subscription?.plan?.slug || '—' }}
        </template>

        <template #cell-status="{ row }">
          <span :class="row.status === 'active' ? 'chip-ok' : 'chip-bad'">{{ row.status }}</span>
        </template>

        <template #cell-actions="{ row }">
          <button class="btn-ghost !px-2.5 !py-1 text-xs" type="button" @click.stop="toggleStatus(row)">
            {{ row.status === 'active' ? ui.t('platform.suspend', 'Suspend') : ui.t('platform.activate', 'Activate') }}
          </button>
        </template>
      </DataTable>
    </GlassCard>

    <GlassCard :title="ui.t('platform.plans', 'SaaS plans')" :padded="false">
      <ul class="divide-y divide-white/5">
        <li v-for="plan in plans" :key="plan.id" class="flex flex-wrap items-center gap-3 px-5 py-3">
          <div class="min-w-0 flex-1">
            <p class="truncate text-sm font-semibold">{{ plan.name?.[ui.locale] || plan.name?.en }}</p>
            <p class="text-xs text-ink-400">
              {{ plan.max_members ?? '∞' }} {{ ui.t('nav.members', 'members') }} ·
              {{ plan.max_staff ?? '∞' }} {{ ui.t('platform.staff', 'staff') }}
            </p>
          </div>
          <span class="text-sm font-semibold tabular-nums">{{ Number(plan.price).toLocaleString() }}</span>
          <span class="chip-muted">{{ plan.period }}</span>
        </li>
      </ul>
    </GlassCard>
  </div>
</template>
