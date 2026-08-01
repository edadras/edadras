<script setup>
import { onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import api, { errorMessage } from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'
import GlassCard from '@/components/GlassCard.vue'
import DataTable from '@/components/DataTable.vue'

const ui = useUiStore()
const auth = useAuthStore()
const router = useRouter()

const rows = ref([])
const meta = ref({ current_page: 1, last_page: 1, total: 0 })
const loading = ref(true)
const search = ref('')
const status = ref('')
const page = ref(1)
const showForm = ref(false)
const saving = ref(false)
const formError = ref(null)

const blank = {
  first_name: '',
  last_name: '',
  phone: '',
  national_id: '',
  email: '',
  gender: '',
  birth_date: '',
  height: '',
  weight: '',
  blood_type: '',
  diseases: '',
  allergies: '',
  emergency_name: '',
  emergency_phone: '',
}
const form = ref({ ...blank })

const columns = [
  { key: 'code', label: '#' },
  { key: 'name', label: ui.t('members.name', 'Name') },
  { key: 'phone', label: ui.t('auth.phone', 'Phone') },
  { key: 'membership', label: ui.t('members.membership', 'Membership') },
  { key: 'remaining', label: ui.t('checkin.sessions_left', 'Sessions') },
  { key: 'status', label: ui.t('members.status', 'Status') },
]

let searchTimer

async function load() {
  loading.value = true

  try {
    const { data } = await api.get('/members', {
      params: { q: search.value || undefined, status: status.value || undefined, page: page.value },
    })

    rows.value = data.data
    meta.value = { current_page: data.current_page, last_page: data.last_page, total: data.total }
  } finally {
    loading.value = false
  }
}

watch(search, () => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => {
    page.value = 1
    load()
  }, 300)
})

watch([status, page], load)

async function save() {
  saving.value = true
  formError.value = null

  try {
    const payload = Object.fromEntries(
      Object.entries(form.value).filter(([, value]) => value !== '' && value !== null),
    )

    await api.post('/members', payload)

    ui.notify(ui.t('general.saved', 'Saved.'))
    showForm.value = false
    form.value = { ...blank }
    load()
  } catch (error) {
    formError.value = errorMessage(error)
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="space-y-5">
    <div class="flex flex-wrap items-center gap-3">
      <input v-model="search" class="field max-w-xs" :placeholder="ui.t('members.search', 'Search name, phone, code…')" />

      <select v-model="status" class="field !w-auto" :aria-label="ui.t('members.status', 'Status')">
        <option value="">{{ ui.t('members.all', 'All') }}</option>
        <option value="active">{{ ui.t('members.active', 'Active') }}</option>
        <option value="inactive">{{ ui.t('members.inactive', 'Inactive') }}</option>
        <option value="blocked">{{ ui.t('members.blocked', 'Blocked') }}</option>
      </select>

      <span class="text-sm text-ink-400">{{ meta.total }} {{ ui.t('nav.members', 'members') }}</span>

      <button v-if="auth.can('members.create')" class="btn-primary ms-auto" type="button" @click="showForm = !showForm">
        {{ showForm ? ui.t('general.cancel', 'Cancel') : `+ ${ui.t('members.add', 'Add member')}` }}
      </button>
    </div>

    <GlassCard v-if="showForm" :title="ui.t('members.add', 'Add member')">
      <form class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3" @submit.prevent="save">
        <div>
          <label class="label">{{ ui.t('members.first_name', 'First name') }}</label>
          <input v-model="form.first_name" class="field" required />
        </div>
        <div>
          <label class="label">{{ ui.t('members.last_name', 'Last name') }}</label>
          <input v-model="form.last_name" class="field" required />
        </div>
        <div>
          <label class="label">{{ ui.t('auth.phone', 'Phone') }}</label>
          <input v-model="form.phone" class="field" required />
        </div>
        <div>
          <label class="label">{{ ui.t('members.national_id', 'National ID') }}</label>
          <input v-model="form.national_id" class="field" />
        </div>
        <div>
          <label class="label">{{ ui.t('auth.email', 'Email') }}</label>
          <input v-model="form.email" class="field" type="email" />
        </div>
        <div>
          <label class="label">{{ ui.t('members.gender', 'Gender') }}</label>
          <select v-model="form.gender" class="field">
            <option value="">—</option>
            <option value="male">{{ ui.t('members.male', 'Male') }}</option>
            <option value="female">{{ ui.t('members.female', 'Female') }}</option>
            <option value="other">{{ ui.t('members.other', 'Other') }}</option>
          </select>
        </div>
        <div>
          <label class="label">{{ ui.t('members.birth_date', 'Birth date') }}</label>
          <input v-model="form.birth_date" class="field" type="date" />
        </div>
        <div>
          <label class="label">{{ ui.t('members.height', 'Height (cm)') }}</label>
          <input v-model="form.height" class="field" type="number" min="80" max="260" />
        </div>
        <div>
          <label class="label">{{ ui.t('members.weight', 'Weight (kg)') }}</label>
          <input v-model="form.weight" class="field" type="number" step="0.1" />
        </div>
        <div>
          <label class="label">{{ ui.t('members.blood_type', 'Blood type') }}</label>
          <input v-model="form.blood_type" class="field" placeholder="O+" />
        </div>
        <div>
          <label class="label">{{ ui.t('members.emergency_name', 'Emergency contact') }}</label>
          <input v-model="form.emergency_name" class="field" />
        </div>
        <div>
          <label class="label">{{ ui.t('members.emergency_phone', 'Emergency phone') }}</label>
          <input v-model="form.emergency_phone" class="field" />
        </div>
        <div class="sm:col-span-2 lg:col-span-3">
          <label class="label">{{ ui.t('members.diseases', 'Medical conditions') }}</label>
          <textarea v-model="form.diseases" class="field" rows="2" />
        </div>
        <div class="sm:col-span-2 lg:col-span-3">
          <label class="label">{{ ui.t('members.allergies', 'Allergies') }}</label>
          <textarea v-model="form.allergies" class="field" rows="2" />
        </div>

        <p v-if="formError" class="rounded-xl bg-rose-500/15 px-3 py-2 text-sm text-rose-200 sm:col-span-2 lg:col-span-3">
          {{ formError }}
        </p>

        <div class="sm:col-span-2 lg:col-span-3">
          <button class="btn-primary" type="submit" :disabled="saving">
            {{ saving ? '…' : ui.t('general.save', 'Save') }}
          </button>
        </div>
      </form>
    </GlassCard>

    <GlassCard :padded="false">
      <DataTable
        :columns="columns"
        :rows="rows"
        :loading="loading"
        :empty="ui.t('members.empty', 'No members found.')"
        @row-click="router.push({ name: 'member', params: { id: $event.id } })"
      >
        <template #cell-name="{ row }">
          <span class="font-medium">{{ row.first_name }} {{ row.last_name }}</span>
        </template>

        <template #cell-membership="{ row }">
          <span v-if="row.active_membership" class="chip-ok">
            {{ row.active_membership.plan?.name?.[ui.locale] || row.active_membership.type }}
          </span>
          <span v-else class="chip-bad">{{ ui.t('checkin.no_membership', 'None') }}</span>
        </template>

        <template #cell-remaining="{ row }">
          {{ row.active_membership?.remaining_sessions ?? '∞' }}
        </template>

        <template #cell-status="{ row }">
          <span :class="row.status === 'active' ? 'chip-ok' : 'chip-muted'">{{ row.status }}</span>
        </template>
      </DataTable>
    </GlassCard>

    <div v-if="meta.last_page > 1" class="flex items-center justify-center gap-3">
      <button class="btn-ghost" :disabled="page <= 1" @click="page--">‹</button>
      <span class="text-sm text-ink-400">{{ meta.current_page }} / {{ meta.last_page }}</span>
      <button class="btn-ghost" :disabled="page >= meta.last_page" @click="page++">›</button>
    </div>
  </div>
</template>
