<script setup>
import { onMounted, ref } from 'vue'
import api from '@/api/client'
import { useUiStore } from '@/stores/ui'
import GlassCard from '@/components/GlassCard.vue'
import StatTile from '@/components/StatTile.vue'
import LineChart from '@/components/LineChart.vue'

const ui = useUiStore()
const data = ref(null)
const loading = ref(true)

onMounted(async () => {
  try {
    const response = await api.get('/dashboard')
    data.value = response.data
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <div v-if="loading" class="grid place-items-center py-24">
    <span class="size-8 animate-spin rounded-full border-2 border-white/20 border-t-brand" />
  </div>

  <div v-else-if="data" class="space-y-6">
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
      <StatTile :label="ui.t('nav.members', 'Members')" :value="data.members.total" icon="👥"
        :hint="`${data.members.active} ${ui.t('dash.active', 'active')}`" />
      <StatTile :label="ui.t('dash.today_entries', 'Entries today')" :value="data.attendance.checkins_today" icon="🚪"
        :hint="`${data.attendance.inside_now} ${ui.t('dash.inside_now', 'inside now')}`" />
      <StatTile :label="ui.t('dash.revenue_today', 'Revenue today')" :value="data.revenue.today" icon="💰" money />
      <StatTile :label="ui.t('dash.revenue_month', 'Revenue this month')" :value="data.revenue.month" icon="📅" money />
    </div>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
      <StatTile :label="ui.t('dash.active_memberships', 'Active memberships')" :value="data.memberships.active" icon="🎟️" />
      <StatTile :label="ui.t('dash.expiring', 'Expiring in 7 days')" :value="data.memberships.expiring_7_days" icon="⏳" />
      <StatTile :label="ui.t('dash.sessions_left', 'Sessions left')" :value="data.memberships.remaining_sessions" icon="🔢" />
      <StatTile :label="ui.t('dash.checkouts', 'Exits today')" :value="data.attendance.checkouts_today" icon="🚶" />
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
      <GlassCard :title="ui.t('reports.revenue_series', 'Revenue over time')" :subtitle="ui.t('dash.last_30', 'Last 30 days')">
        <LineChart :series="data.charts.revenue_last_30_days" :label="ui.t('dash.revenue', 'Revenue')" />
      </GlassCard>

      <GlassCard :title="ui.t('reports.attendance_series', 'Attendance over time')" :subtitle="ui.t('dash.last_30', 'Last 30 days')">
        <LineChart :series="data.charts.attendance_last_30_days" :label="ui.t('nav.check_in', 'Check-ins')" color="#8ff8b6" />
      </GlassCard>
    </div>

    <GlassCard :title="ui.t('reports.attendance_by_hour', 'Attendance by hour')">
      <div class="flex h-40 items-end gap-1.5">
        <div
          v-for="bucket in data.charts.attendance_by_hour"
          :key="bucket.hour"
          class="group relative flex-1 rounded-t-md bg-brand/25 transition hover:bg-brand/60"
          :style="{
            height: `${Math.max(3, (bucket.total / Math.max(1, ...data.charts.attendance_by_hour.map((b) => b.total))) * 100)}%`,
          }"
        >
          <span
            class="pointer-events-none absolute -top-6 start-1/2 -translate-x-1/2 rounded-md bg-ink-900 px-1.5 py-0.5 text-[10px] opacity-0 transition group-hover:opacity-100"
          >
            {{ bucket.hour }}:00 · {{ bucket.total }}
          </span>
        </div>
      </div>
      <div class="mt-2 flex justify-between text-[10px] text-ink-400">
        <span>00:00</span><span>12:00</span><span>23:00</span>
      </div>
    </GlassCard>

    <div class="grid gap-4 sm:grid-cols-3">
      <StatTile :label="ui.t('dash.classes_today', 'Sessions today')" :value="data.classes.sessions_today" icon="🧘" />
      <StatTile :label="ui.t('dash.bookings_today', 'Bookings today')" :value="data.classes.bookings_today" icon="📆" />
      <StatTile :label="ui.t('dash.expense_month', 'Expenses this month')" :value="data.revenue.expense_month" icon="🧾" money />
    </div>
  </div>
</template>
