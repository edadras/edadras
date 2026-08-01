<script setup>
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'

const auth = useAuthStore()
const ui = useUiStore()
const router = useRouter()
const sidebarOpen = ref(false)

const nav = computed(() =>
  [
    { name: 'dashboard', label: ui.t('nav.dashboard', 'Dashboard'), icon: '🏠', permission: 'dashboard.view' },
    { name: 'check-in', label: ui.t('nav.check_in', 'Check-in'), icon: '🎫', permission: 'attendance.checkin' },
    { name: 'members', label: ui.t('nav.members', 'Members'), icon: '👥', permission: 'members.view' },
    { name: 'classes', label: ui.t('nav.classes', 'Classes'), icon: '🧘', permission: 'classes.view' },
    { name: 'finance', label: ui.t('nav.finance', 'Finance'), icon: '💳', permission: 'finance.view' },
    { name: 'shop', label: ui.t('nav.shop', 'Shop'), icon: '🛍️', permission: 'shop.view' },
    { name: 'reports', label: ui.t('nav.reports', 'Reports'), icon: '📈', permission: 'reports.view' },
    { name: 'ai', label: ui.t('nav.ai', 'AI'), icon: '✨', permission: 'ai.view' },
    { name: 'crm', label: ui.t('nav.crm', 'Messaging'), icon: '📣', permission: 'crm.view' },
    { name: 'audit', label: ui.t('nav.audit', 'Audit trail'), icon: '🛡️', permission: 'audit.view' },
    { name: 'settings', label: ui.t('nav.settings', 'Settings'), icon: '⚙️', permission: 'settings.view' },
    { name: 'security', label: ui.t('nav.security', 'Security'), icon: '🔐' },
  ].filter((item) => auth.can(item.permission)),
)

async function signOut() {
  await auth.logout()
  router.push({ name: 'login' })
}
</script>

<template>
  <div class="min-h-screen lg:flex">
    <!-- Sidebar -->
    <aside
      class="glass fixed inset-y-0 start-0 z-40 w-64 shrink-0 rounded-none border-y-0 border-s-0 p-4 transition-transform lg:static lg:translate-x-0"
      :class="
        sidebarOpen
          ? 'translate-x-0'
          : 'max-lg:ltr:-translate-x-full max-lg:rtl:translate-x-full'
      "
    >
      <div class="mb-6 flex items-center gap-3 px-2">
        <span
          class="grid size-10 place-items-center rounded-2xl text-lg font-black text-ink-900"
          style="background: linear-gradient(150deg, #8ff8b6, #2fd96b)"
          aria-hidden="true"
        >
          G
        </span>
        <div class="min-w-0">
          <p class="truncate text-sm font-bold">{{ auth.club?.name || 'GymFlow AI' }}</p>
          <p class="truncate text-xs text-ink-400">{{ auth.club?.type || 'platform' }}</p>
        </div>
      </div>

      <nav class="space-y-1">
        <RouterLink
          v-for="item in nav"
          :key="item.name"
          :to="{ name: item.name }"
          class="flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium text-ink-200 transition hover:bg-white/8 hover:text-white"
          active-class="bg-brand/15 text-brand"
          @click="sidebarOpen = false"
        >
          <span class="text-base" aria-hidden="true">{{ item.icon }}</span>
          {{ item.label }}
        </RouterLink>

        <RouterLink
          v-if="auth.isSuperAdmin"
          :to="{ name: 'platform' }"
          class="flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium text-ink-200 transition hover:bg-white/8 hover:text-white"
          active-class="bg-brand/15 text-brand"
          @click="sidebarOpen = false"
        >
          <span class="text-base" aria-hidden="true">🛰️</span>
          {{ ui.t('nav.platform', 'Platform') }}
        </RouterLink>

        <RouterLink
          v-if="auth.isSuperAdmin"
          :to="{ name: 'templates' }"
          class="flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium text-ink-200 transition hover:bg-white/8 hover:text-white"
          active-class="bg-brand/15 text-brand"
          @click="sidebarOpen = false"
        >
          <span class="text-base" aria-hidden="true">🈯</span>
          {{ ui.t('nav.templates', 'Wording') }}
        </RouterLink>
      </nav>

      <div class="absolute inset-x-4 bottom-4">
        <button class="btn-ghost w-full" type="button" @click="signOut">
          {{ ui.t('nav.sign_out', 'Sign out') }}
        </button>
      </div>
    </aside>

    <div
      v-if="sidebarOpen"
      class="fixed inset-0 z-30 bg-black/50 lg:hidden"
      @click="sidebarOpen = false"
    />

    <!-- Main column -->
    <div class="flex min-w-0 flex-1 flex-col">
      <header class="glass sticky top-0 z-20 flex items-center gap-3 rounded-none border-x-0 border-t-0 px-4 py-3">
        <button class="btn-ghost !px-2.5 lg:hidden" type="button" @click="sidebarOpen = !sidebarOpen">
          ☰
        </button>

        <div class="min-w-0 flex-1">
          <p class="truncate text-sm font-semibold">{{ $route.meta.title || auth.club?.name || 'GymFlow AI' }}</p>
        </div>

        <select
          class="field !w-auto !py-1.5 text-xs"
          :value="ui.locale"
          :aria-label="ui.t('nav.language', 'Language')"
          @change="ui.setLocale($event.target.value)"
        >
          <option v-for="(meta, code) in ui.locales" :key="code" :value="code">
            {{ meta.flag }} {{ meta.native }}
          </option>
        </select>

        <div class="hidden items-center gap-2 sm:flex">
          <span class="grid size-8 place-items-center rounded-full bg-white/10 text-xs font-bold">
            {{ (auth.user?.name || '?').slice(0, 1) }}
          </span>
          <span class="max-w-32 truncate text-xs text-ink-200">{{ auth.user?.name }}</span>
        </div>
      </header>

      <main class="min-w-0 flex-1 p-4 sm:p-6">
        <RouterView />
      </main>
    </div>
  </div>
</template>
