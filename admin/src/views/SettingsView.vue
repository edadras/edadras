<script setup>
import { onMounted, ref } from 'vue'
import api, { errorMessage } from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'
import GlassCard from '@/components/GlassCard.vue'

const ui = useUiStore()
const auth = useAuthStore()

const club = ref(null)
const staff = ref([])
const roles = ref([])
const plans = ref([])
const saving = ref(false)
const tab = ref('club')

const newStaff = ref({ name: '', email: '', password: '', roles: [] })

async function load() {
  const [clubResponse, staffResponse, roleResponse, planResponse] = await Promise.all([
    api.get('/club'),
    api.get('/club/staff'),
    api.get('/club/roles'),
    api.get('/membership-plans', { params: { include_inactive: 1 } }),
  ])

  // A club that has never filled these in comes back with nulls, and the
  // form binds straight into them.
  club.value = {
    ...clubResponse.data,
    socials: clubResponse.data.socials || {},
    working_hours: clubResponse.data.working_hours || {},
  }
  staff.value = staffResponse.data.data
  roles.value = roleResponse.data.roles
  plans.value = planResponse.data
}

async function saveClub() {
  saving.value = true

  try {
    const { data } = await api.put('/club', {
      name: club.value.name,
      phone: club.value.phone,
      email: club.value.email,
      address: club.value.address,
      city: club.value.city,
      brand_color: club.value.brand_color,
      rules: club.value.rules,
      locale: club.value.locale,
      currency: club.value.currency,
      socials: club.value.socials || {},
      working_hours: club.value.working_hours || {},
    })

    club.value = data
    auth.club = data
    ui.notify(ui.t('general.saved', 'Saved.'))
  } catch (error) {
    ui.notify(errorMessage(error), 'error')
  } finally {
    saving.value = false
  }
}

async function addStaff() {
  saving.value = true

  try {
    await api.post('/club/staff', newStaff.value)
    ui.notify(ui.t('general.saved', 'Staff member added.'))
    newStaff.value = { name: '', email: '', password: '', roles: [] }
    load()
  } catch (error) {
    ui.notify(errorMessage(error), 'error')
  } finally {
    saving.value = false
  }
}

const days = ['saturday', 'sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday']

onMounted(load)
</script>

<template>
  <div v-if="club" class="space-y-6">
    <div class="flex flex-wrap gap-2">
      <button
        v-for="key in ['club', 'staff', 'plans']"
        :key="key"
        type="button"
        class="rounded-xl px-4 py-2 text-sm font-medium transition"
        :class="tab === key ? 'bg-brand text-ink-900' : 'bg-white/5 text-ink-200 hover:bg-white/10'"
        @click="tab = key"
      >
        {{ ui.t(`settings.${key}`, key) }}
      </button>
    </div>

    <GlassCard v-if="tab === 'club'" :title="ui.t('settings.club', 'Club profile')">
      <form class="grid gap-4 sm:grid-cols-2" @submit.prevent="saveClub">
        <div>
          <label class="label">{{ ui.t('auth.club_name', 'Club name') }}</label>
          <input v-model="club.name" class="field" required />
        </div>
        <div>
          <label class="label">{{ ui.t('auth.phone', 'Phone') }}</label>
          <input v-model="club.phone" class="field" />
        </div>
        <div>
          <label class="label">{{ ui.t('auth.email', 'Email') }}</label>
          <input v-model="club.email" class="field" type="email" />
        </div>
        <div>
          <label class="label">{{ ui.t('settings.city', 'City') }}</label>
          <input v-model="club.city" class="field" />
        </div>
        <div class="sm:col-span-2">
          <label class="label">{{ ui.t('auth.address', 'Address') }}</label>
          <input v-model="club.address" class="field" />
        </div>

        <div>
          <label class="label">{{ ui.t('settings.brand_color', 'Brand colour') }}</label>
          <div class="flex items-center gap-2">
            <input v-model="club.brand_color" class="field" />
            <input v-model="club.brand_color" class="size-10 shrink-0 rounded-lg border border-white/12 bg-transparent" type="color" />
          </div>
        </div>
        <div>
          <label class="label">{{ ui.t('settings.currency', 'Currency') }}</label>
          <input v-model="club.currency" class="field" maxlength="3" />
        </div>

        <div class="sm:col-span-2">
          <label class="label">{{ ui.t('settings.socials', 'Social links') }}</label>
          <div class="grid gap-2 sm:grid-cols-2">
            <input
              v-for="network in ['instagram', 'telegram', 'whatsapp', 'website']"
              :key="network"
              v-model="club.socials[network]"
              class="field"
              :placeholder="network"
            />
          </div>
        </div>

        <div class="sm:col-span-2">
          <label class="label">{{ ui.t('settings.working_hours', 'Working hours') }}</label>
          <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
            <div v-for="day in days" :key="day">
              <span class="mb-1 block text-xs capitalize text-ink-400">{{ day }}</span>
              <input
                v-model="club.working_hours[day]"
                class="field"
                placeholder="08:00-23:00"
              />
            </div>
          </div>
        </div>

        <div class="sm:col-span-2">
          <label class="label">{{ ui.t('settings.rules', 'Club rules') }}</label>
          <textarea v-model="club.rules" class="field" rows="4" />
        </div>

        <div class="sm:col-span-2">
          <button class="btn-primary" type="submit" :disabled="saving">
            {{ saving ? '…' : ui.t('general.save', 'Save') }}
          </button>
        </div>
      </form>
    </GlassCard>

    <template v-if="tab === 'staff'">
      <GlassCard :title="ui.t('settings.staff', 'Staff')">
        <ul class="divide-y divide-white/5">
          <li v-for="person in staff" :key="person.id" class="flex flex-wrap items-center gap-3 py-3">
            <span class="grid size-9 place-items-center rounded-full bg-white/8 text-xs font-bold">
              {{ person.name.slice(0, 1) }}
            </span>
            <div class="min-w-0 flex-1">
              <p class="truncate text-sm font-medium">{{ person.name }}</p>
              <p class="truncate text-xs text-ink-400">{{ person.email }}</p>
            </div>
            <div class="flex flex-wrap gap-1">
              <span v-for="role in person.roles" :key="role.id" class="chip-muted">
                {{ role.name?.[ui.locale] || role.slug }}
              </span>
            </div>
          </li>
        </ul>
      </GlassCard>

      <GlassCard v-if="auth.can('staff.create')" :title="ui.t('settings.add_staff', 'Add a staff member')">
        <form class="grid gap-4 sm:grid-cols-2" @submit.prevent="addStaff">
          <div>
            <label class="label">{{ ui.t('members.name', 'Name') }}</label>
            <input v-model="newStaff.name" class="field" required />
          </div>
          <div>
            <label class="label">{{ ui.t('auth.email', 'Email') }}</label>
            <input v-model="newStaff.email" class="field" type="email" required />
          </div>
          <div>
            <label class="label">{{ ui.t('auth.password_label', 'Password') }}</label>
            <input v-model="newStaff.password" class="field" type="password" minlength="8" required autocomplete="new-password" />
          </div>
          <div>
            <label class="label">{{ ui.t('settings.roles', 'Roles') }}</label>
            <div class="flex flex-wrap gap-2">
              <label
                v-for="role in roles"
                :key="role.id"
                class="cursor-pointer rounded-lg px-2.5 py-1.5 text-xs transition"
                :class="newStaff.roles.includes(role.id) ? 'bg-brand text-ink-900' : 'bg-white/5 text-ink-200'"
              >
                <input v-model="newStaff.roles" type="checkbox" :value="role.id" class="sr-only" />
                {{ role.name?.[ui.locale] || role.slug }}
              </label>
            </div>
          </div>
          <div class="sm:col-span-2">
            <button class="btn-primary" type="submit" :disabled="saving">
              {{ ui.t('general.save', 'Save') }}
            </button>
          </div>
        </form>
      </GlassCard>
    </template>

    <GlassCard v-if="tab === 'plans'" :title="ui.t('settings.plans', 'Membership plans')" :padded="false">
      <ul class="divide-y divide-white/5">
        <li v-for="plan in plans" :key="plan.id" class="flex flex-wrap items-center gap-3 px-5 py-3">
          <div class="min-w-0 flex-1">
            <p class="truncate text-sm font-medium">{{ plan.name?.[ui.locale] || plan.name?.en }}</p>
            <p class="text-xs text-ink-400">
              {{ plan.type }}
              <template v-if="plan.duration_days"> · {{ plan.duration_days }} {{ ui.t('settings.days', 'days') }}</template>
              <template v-if="plan.session_count"> · {{ plan.session_count }} {{ ui.t('settings.sessions', 'sessions') }}</template>
            </p>
          </div>
          <span class="text-sm font-semibold tabular-nums">{{ Number(plan.price).toLocaleString() }}</span>
          <span :class="plan.is_active ? 'chip-ok' : 'chip-muted'">
            {{ plan.is_active ? ui.t('members.active', 'active') : ui.t('members.inactive', 'inactive') }}
          </span>
        </li>
      </ul>
    </GlassCard>
  </div>
</template>
