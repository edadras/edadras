<script setup>
import { onMounted, ref, watch } from 'vue'
import api from '@/api/client'
import { useUiStore } from '@/stores/ui'
import GlassCard from '@/components/GlassCard.vue'

const ui = useUiStore()

const locales = ref({})
const locale = ref('fa')
const groups = ref([])
const group = ref('panel')
const search = ref('')

const lines = ref([])
const total = ref(0)
const loading = ref(true)
const editing = ref(null)
const draft = ref('')

async function load() {
  loading.value = true

  try {
    const { data } = await api.get('/platform/templates', {
      params: { locale: locale.value, group: group.value, search: search.value || undefined },
    })

    groups.value = data.groups
    lines.value = data.lines
    total.value = data.total
  } finally {
    loading.value = false
  }
}

function edit(line) {
  editing.value = `${line.group}.${line.key}`
  draft.value = line.value
}

async function save(line) {
  await api.put('/platform/templates', {
    locale: locale.value,
    group: line.group,
    key: line.key,
    value: draft.value,
  })

  editing.value = null
  ui.notify(ui.t('general.saved', 'Saved.'))
  await load()
}

async function reset(line) {
  await api.delete('/platform/templates', {
    data: { locale: locale.value, group: line.group, key: line.key },
  })

  ui.notify(ui.t('templates.reset_done', 'Back to the shipped wording.'))
  await load()
}

watch([locale, group], load)

let timer
watch(search, () => {
  clearTimeout(timer)
  timer = setTimeout(load, 300)
})

onMounted(async () => {
  const { data } = await api.get('/locales')

  locales.value = data.locales
  await load()
})
</script>

<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-end gap-3">
      <div>
        <label class="label">{{ ui.t('nav.language', 'Language') }}</label>
        <select v-model="locale" class="field !w-auto">
          <option v-for="(meta, key) in locales" :key="key" :value="key">
            {{ meta.flag }} {{ meta.native }}
          </option>
        </select>
      </div>
      <div>
        <label class="label">{{ ui.t('templates.group', 'Group') }}</label>
        <select v-model="group" class="field !w-auto">
          <option v-for="name in groups" :key="name" :value="name">{{ name }}</option>
        </select>
      </div>
      <div class="min-w-56 grow">
        <label class="label">{{ ui.t('members.search', 'Search') }}</label>
        <input v-model="search" class="field" type="search" />
      </div>
    </div>

    <GlassCard :title="`${ui.t('templates.title', 'Wording')} · ${total}`">
      <p class="mb-4 text-xs leading-relaxed text-ink-400">
        {{ ui.t('templates.hint', 'The wording every club starts from. A club that rewrites a line for itself keeps its own version.') }}
      </p>

      <div v-if="loading" class="py-12 text-center">
        <span class="inline-block size-6 animate-spin rounded-full border-2 border-white/20 border-t-brand" />
      </div>

      <div v-else class="space-y-1.5">
        <div
          v-for="line in lines"
          :key="`${line.group}.${line.key}`"
          class="rounded-xl px-4 py-3 transition"
          :class="line.overridden ? 'bg-brand/8 ring-1 ring-brand/20' : 'bg-white/4'"
        >
          <div class="flex flex-wrap items-start gap-3">
            <div class="min-w-40">
              <code class="text-xs text-ink-400">{{ line.key }}</code>
              <p v-if="line.clubs_overriding" class="mt-1 text-xs text-warning">
                {{ line.clubs_overriding }} {{ ui.t('templates.clubs_overriding', 'clubs use their own') }}
              </p>
            </div>

            <div class="min-w-56 grow">
              <template v-if="editing === `${line.group}.${line.key}`">
                <textarea
                  v-model="draft"
                  class="field min-h-20"
                  :dir="locales[locale]?.dir"
                  @keydown.escape="editing = null"
                />
                <div class="mt-2 flex gap-2">
                  <button class="btn-primary !px-4 !py-1.5 text-xs" type="button" @click="save(line)">
                    {{ ui.t('general.save', 'Save') }}
                  </button>
                  <button class="btn-ghost !px-4 !py-1.5 text-xs" type="button" @click="editing = null">
                    {{ ui.t('general.cancel', 'Cancel') }}
                  </button>
                </div>
              </template>

              <p v-else class="text-sm" :dir="locales[locale]?.dir">{{ line.value }}</p>
            </div>

            <div v-if="editing !== `${line.group}.${line.key}`" class="flex gap-2">
              <button class="btn-ghost !px-3 !py-1 text-xs" type="button" @click="edit(line)">
                {{ ui.t('templates.edit', 'Edit') }}
              </button>
              <button
                v-if="line.overridden"
                class="btn-ghost !px-3 !py-1 text-xs"
                type="button"
                @click="reset(line)"
              >
                {{ ui.t('templates.reset', 'Reset') }}
              </button>
            </div>
          </div>
        </div>

        <p v-if="!lines.length" class="py-10 text-center text-sm text-ink-400">
          {{ ui.t('reports.no_data', 'Nothing here.') }}
        </p>
      </div>
    </GlassCard>
  </div>
</template>
