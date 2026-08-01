<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import api, { errorMessage } from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'
import GlassCard from '@/components/GlassCard.vue'
import StatTile from '@/components/StatTile.vue'
import LineChart from '@/components/LineChart.vue'

const route = useRoute()
const ui = useUiStore()
const auth = useAuthStore()

const member = ref(null)
const plans = ref([])
const measurements = ref({ series: {}, change: {} })
const attendance = ref([])
const loading = ref(true)
const selling = ref(false)
const selectedPlan = ref('')
const qrUrl = ref(null)

const membership = computed(() => member.value?.active_membership)

async function load() {
  loading.value = true

  const [memberResponse, plansResponse, measurementResponse, attendanceResponse] = await Promise.all([
    api.get(`/members/${route.params.id}`),
    api.get('/membership-plans'),
    api.get(`/members/${route.params.id}/measurements`),
    api.get('/attendance', { params: { member_id: route.params.id, period: 'month', per_page: 10 } }),
  ])

  member.value = memberResponse.data
  plans.value = plansResponse.data
  measurements.value = measurementResponse.data
  attendance.value = attendanceResponse.data.data
  loading.value = false
}

async function sellMembership() {
  if (!selectedPlan.value) return

  selling.value = true

  try {
    await api.post('/memberships', {
      member_id: member.value.id,
      membership_plan_id: selectedPlan.value,
    })

    ui.notify(ui.t('general.saved', 'Membership sold.'))
    await load()
  } catch (error) {
    ui.notify(errorMessage(error), 'error')
  } finally {
    selling.value = false
  }
}

async function generatePlan(kind) {
  try {
    await api.post(`/members/${member.value.id}/generate-${kind}`, { goal: 'general_fitness', days_per_week: 4 })
    ui.notify(ui.t('ai.plan_ready', 'Program generated.'))
  } catch (error) {
    ui.notify(errorMessage(error), 'error')
  }
}

async function showQr() {
  const { data } = await api.get(`/members/${member.value.id}/qr`, { responseType: 'blob' })

  qrUrl.value = URL.createObjectURL(data)
}

onMounted(load)
</script>

<template>
  <div v-if="loading" class="grid place-items-center py-24">
    <span class="size-8 animate-spin rounded-full border-2 border-white/20 border-t-brand" />
  </div>

  <div v-else-if="member" class="space-y-6">
    <GlassCard>
      <div class="flex flex-wrap items-start gap-5">
        <span class="grid size-16 shrink-0 place-items-center rounded-3xl bg-white/10 text-2xl font-bold">
          {{ member.first_name.slice(0, 1) }}
        </span>

        <div class="min-w-0 flex-1">
          <h1 class="text-xl font-bold">{{ member.first_name }} {{ member.last_name }}</h1>
          <p class="mt-1 text-sm text-ink-400">
            #{{ member.code }} · {{ member.phone }}
            <template v-if="member.blood_type"> · {{ member.blood_type }}</template>
          </p>
          <div class="mt-3 flex flex-wrap gap-2">
            <span :class="member.status === 'active' ? 'chip-ok' : 'chip-muted'">{{ member.status }}</span>
            <span v-if="member.diseases" class="chip-warn">⚕️ {{ member.diseases }}</span>
            <span v-if="member.allergies" class="chip-warn">🌾 {{ member.allergies }}</span>
          </div>
        </div>

        <div class="flex gap-2">
          <button class="btn-ghost" type="button" @click="showQr">
            {{ ui.t('members.qr', 'QR badge') }}
          </button>
        </div>
      </div>

      <div v-if="qrUrl" class="mt-5 flex justify-center">
        <img :src="qrUrl" alt="Member QR code" class="size-48 rounded-2xl bg-white p-3" />
      </div>
    </GlassCard>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
      <StatTile
        :label="ui.t('checkin.sessions_left', 'Sessions left')"
        :value="membership?.remaining_sessions ?? '∞'"
        icon="🔢"
      />
      <StatTile
        :label="ui.t('members.expires', 'Expires')"
        :value="membership?.ends_at?.slice(0, 10) || '—'"
        icon="📅"
      />
      <StatTile
        :label="ui.t('members.wallet', 'Wallet')"
        :value="Number(member.wallet?.balance ?? 0)"
        icon="👛"
        money
      />
      <StatTile
        :label="ui.t('members.weight_change', 'Weight change')"
        :value="measurements.change?.weight ?? 0"
        icon="⚖️"
      />
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
      <GlassCard :title="ui.t('members.membership', 'Membership')">
        <div v-if="membership" class="mb-4 rounded-xl bg-white/5 p-4">
          <p class="text-sm font-semibold">
            {{ membership.plan?.name?.[ui.locale] || membership.type }}
          </p>
          <p class="mt-1 text-xs text-ink-400">
            {{ membership.starts_at?.slice(0, 10) }} → {{ membership.ends_at?.slice(0, 10) || '∞' }}
          </p>
        </div>

        <div v-if="auth.can('memberships.create')" class="flex gap-2">
          <select v-model="selectedPlan" class="field" :aria-label="ui.t('members.plan', 'Plan')">
            <option value="">{{ ui.t('members.choose_plan', 'Choose a plan…') }}</option>
            <option v-for="plan in plans" :key="plan.id" :value="plan.id">
              {{ plan.name?.[ui.locale] || plan.name?.en }} — {{ Number(plan.price).toLocaleString() }}
            </option>
          </select>
          <button class="btn-primary" type="button" :disabled="selling || !selectedPlan" @click="sellMembership">
            {{ ui.t('members.sell', 'Sell') }}
          </button>
        </div>
      </GlassCard>

      <GlassCard :title="ui.t('members.programs', 'Programs')" :subtitle="ui.t('ai.generated_hint', 'Generated from the member profile')">
        <div class="flex flex-wrap gap-2">
          <button class="btn-ghost" type="button" @click="generatePlan('workout')">
            🏋️ {{ ui.t('ai.generate_workout', 'Generate workout plan') }}
          </button>
          <button class="btn-ghost" type="button" @click="generatePlan('nutrition')">
            🥗 {{ ui.t('ai.generate_nutrition', 'Generate meal plan') }}
          </button>
        </div>
      </GlassCard>
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
      <GlassCard :title="ui.t('members.weight_chart', 'Weight')">
        <LineChart :series="measurements.series.weight || []" :label="ui.t('members.weight', 'Weight')" />
      </GlassCard>

      <GlassCard :title="ui.t('members.fat_chart', 'Body fat %')">
        <LineChart :series="measurements.series.fat_percent || []" :label="ui.t('members.fat', 'Fat %')" color="#fcd34d" />
      </GlassCard>
    </div>

    <GlassCard :title="ui.t('checkin.recent_visits', 'Recent visits')">
      <ul class="divide-y divide-white/5">
        <li v-if="!attendance.length" class="py-6 text-center text-sm text-ink-400">
          {{ ui.t('checkin.no_entries', 'No visits recorded.') }}
        </li>
        <li v-for="entry in attendance" :key="entry.id" class="flex items-center justify-between py-2.5 text-sm">
          <span>{{ new Date(entry.checked_in_at).toLocaleString() }}</span>
          <span class="text-xs text-ink-400">{{ entry.method }}</span>
        </li>
      </ul>
    </GlassCard>
  </div>
</template>
