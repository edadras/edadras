<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import api from '@/api/client'
import { useUiStore } from '@/stores/ui'
import GlassCard from '@/components/GlassCard.vue'

const ui = useUiStore()

const channels = ref([])
const placeholders = ref([])
const campaigns = ref([])
const loading = ref(true)
const sending = ref(false)
const preview = ref(null)
const reach = ref(null)

const form = reactive({
  title: '',
  channel: 'sms',
  body: '',
  audience: { type: 'active', days: 30 },
})

const AUDIENCES = ['all', 'active', 'inactive', 'expiring', 'birthday']

const liveChannels = computed(() => channels.value.filter((channel) => channel.live))

const needsDays = computed(() => ['inactive', 'expiring'].includes(form.audience.type))

async function load() {
  loading.value = true

  try {
    const [status, list] = await Promise.all([
      api.get('/campaigns/channels'),
      api.get('/campaigns', { params: { per_page: 25 } }),
    ])

    channels.value = status.data.channels
    placeholders.value = status.data.placeholders
    campaigns.value = list.data.data
  } finally {
    loading.value = false
  }
}

async function previewAudience() {
  const { data } = await api.post('/campaigns/audience', form.audience)

  reach.value = data
}

async function previewBody() {
  if (!form.body.trim()) return

  const { data } = await api.post('/campaigns/preview', { body: form.body })

  preview.value = data.body
}

async function create(send) {
  sending.value = true

  try {
    const { data } = await api.post('/campaigns', {
      title: form.title,
      channel: form.channel,
      body: form.body,
      audience: form.audience,
    })

    if (send) {
      const result = await api.post(`/campaigns/${data.id}/send`)

      ui.notify(
        result.data.queued
          ? ui.t('crm.queued', 'The campaign is being sent in the background.')
          : `${result.data.delivered}/${result.data.recipients}`,
      )
    } else {
      ui.notify(ui.t('general.saved', 'Saved.'))
    }

    form.title = ''
    form.body = ''
    preview.value = null
    await load()
  } finally {
    sending.value = false
  }
}

async function send(campaign) {
  const result = await api.post(`/campaigns/${campaign.id}/send`)

  ui.notify(
    result.data.queued
      ? ui.t('crm.queued', 'The campaign is being sent in the background.')
      : `${result.data.delivered}/${result.data.recipients}`,
  )
  await load()
}

onMounted(load)
</script>

<template>
  <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_380px]">
    <div class="space-y-6">
      <GlassCard :title="ui.t('crm.compose', 'New campaign')">
        <div class="space-y-4">
          <div class="grid gap-4 sm:grid-cols-2">
            <div>
              <label class="label">{{ ui.t('crm.title', 'Title') }}</label>
              <input v-model="form.title" class="field" type="text" />
            </div>
            <div>
              <label class="label">{{ ui.t('crm.channel', 'Channel') }}</label>
              <select v-model="form.channel" class="field">
                <option v-for="channel in channels" :key="channel.channel" :value="channel.channel">
                  {{ channel.label }}{{ channel.live ? '' : ' · ' + ui.t('crm.log_only', 'log only') }}
                </option>
              </select>
            </div>
          </div>

          <div class="grid gap-4 sm:grid-cols-2">
            <div>
              <label class="label">{{ ui.t('crm.audience', 'Who gets it') }}</label>
              <select v-model="form.audience.type" class="field" @change="reach = null">
                <option v-for="type in AUDIENCES" :key="type" :value="type">
                  {{ ui.t(`crm.audience_${type}`, type) }}
                </option>
              </select>
            </div>
            <div v-if="needsDays">
              <label class="label">{{ ui.t('reports.days', 'Days') }}</label>
              <input v-model.number="form.audience.days" class="field" type="number" min="1" />
            </div>
          </div>

          <div>
            <label class="label">{{ ui.t('crm.body', 'Message') }}</label>
            <textarea v-model="form.body" class="field min-h-32" @blur="previewBody" />
            <p class="mt-1.5 text-xs text-ink-400">
              {{ ui.t('crm.placeholders', 'Placeholders') }}:
              <code v-for="token in placeholders" :key="token" class="me-1.5">{{ token }}</code>
            </p>
          </div>

          <div v-if="preview" class="rounded-xl border border-brand/25 bg-brand/8 p-4">
            <p class="mb-1 text-xs uppercase tracking-wider text-brand">
              {{ ui.t('crm.preview', 'Preview') }}
            </p>
            <p class="whitespace-pre-line text-sm">{{ preview }}</p>
          </div>

          <div class="flex flex-wrap items-center gap-3">
            <button class="btn-ghost" type="button" @click="previewAudience">
              {{ ui.t('crm.check_reach', 'Check who it reaches') }}
            </button>
            <span v-if="reach" class="text-sm text-ink-200">
              {{ reach.count }} {{ ui.t('crm.recipients', 'recipients') }}
            </span>
            <span class="grow" />
            <button class="btn-ghost" type="button" :disabled="sending" @click="create(false)">
              {{ ui.t('general.save', 'Save') }}
            </button>
            <button
              class="btn-primary"
              type="button"
              :disabled="sending || !form.title || !form.body"
              @click="create(true)"
            >
              {{ ui.t('crm.send', 'Send') }}
            </button>
          </div>
        </div>
      </GlassCard>

      <GlassCard :title="ui.t('crm.campaigns', 'Campaigns')">
        <div v-if="loading" class="py-10 text-center text-sm text-ink-400">…</div>

        <p v-else-if="!campaigns.length" class="py-10 text-center text-sm text-ink-400">
          {{ ui.t('crm.no_campaigns', 'Nothing sent yet.') }}
        </p>

        <div v-else class="overflow-x-auto">
          <table class="w-full min-w-max">
            <thead>
              <tr class="border-b border-white/8">
                <th class="table-head">{{ ui.t('crm.title', 'Title') }}</th>
                <th class="table-head">{{ ui.t('crm.channel', 'Channel') }}</th>
                <th class="table-head">{{ ui.t('members.status', 'Status') }}</th>
                <th class="table-head">{{ ui.t('crm.recipients', 'recipients') }}</th>
                <th class="table-head"></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="campaign in campaigns" :key="campaign.id" class="border-b border-white/5">
                <td class="table-cell">{{ campaign.title }}</td>
                <td class="table-cell">{{ ui.t(`crm.channel_${campaign.channel}`, campaign.channel) }}</td>
                <td class="table-cell">{{ campaign.status }}</td>
                <td class="table-cell tabular-nums">
                  {{ campaign.delivered_count }} / {{ campaign.recipients_count }}
                </td>
                <td class="table-cell">
                  <button
                    v-if="!['sent', 'sending'].includes(campaign.status)"
                    class="btn-ghost !px-3 !py-1 text-xs"
                    type="button"
                    @click="send(campaign)"
                  >
                    {{ ui.t('crm.send', 'Send') }}
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </GlassCard>
    </div>

    <GlassCard :title="ui.t('crm.channels', 'Channels')">
      <p class="mb-4 text-xs leading-relaxed text-ink-400">
        {{ ui.t('crm.not_configured', 'This channel is not configured yet, so messages are only written to the log.') }}
      </p>

      <ul class="space-y-2">
        <li
          v-for="channel in channels"
          :key="channel.channel"
          class="flex items-center justify-between rounded-xl bg-white/5 px-4 py-3"
        >
          <span class="text-sm">{{ channel.label }}</span>
          <span
            class="rounded-full px-2.5 py-0.5 text-xs font-medium"
            :class="channel.live ? 'bg-brand/15 text-brand' : 'bg-white/8 text-ink-400'"
          >
            {{ channel.live ? ui.t('crm.live', 'Live') : ui.t('crm.log_only', 'log only') }}
          </span>
        </li>
      </ul>

      <p v-if="!liveChannels.length" class="mt-4 text-xs text-ink-400">
        {{ ui.t('crm.configure_hint', 'Add your provider under Settings to start sending for real.') }}
      </p>
    </GlassCard>
  </div>
</template>
