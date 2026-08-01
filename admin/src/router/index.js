import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const routes = [
  { path: '/login', name: 'login', component: () => import('@/views/LoginView.vue'), meta: { guest: true } },
  { path: '/register', name: 'register', component: () => import('@/views/RegisterClubView.vue'), meta: { guest: true } },

  {
    path: '/',
    component: () => import('@/layouts/AppShell.vue'),
    children: [
      { path: '', name: 'dashboard', component: () => import('@/views/DashboardView.vue'), meta: { permission: 'dashboard.view' } },
      { path: 'check-in', name: 'check-in', component: () => import('@/views/CheckInView.vue'), meta: { permission: 'attendance.checkin' } },
      { path: 'members', name: 'members', component: () => import('@/views/MembersView.vue'), meta: { permission: 'members.view' } },
      { path: 'members/:id', name: 'member', component: () => import('@/views/MemberDetailView.vue'), meta: { permission: 'members.view' } },
      { path: 'classes', name: 'classes', component: () => import('@/views/ClassesView.vue'), meta: { permission: 'classes.view' } },
      { path: 'finance', name: 'finance', component: () => import('@/views/FinanceView.vue'), meta: { permission: 'finance.view' } },
      { path: 'shop', name: 'shop', component: () => import('@/views/ShopView.vue'), meta: { permission: 'shop.view' } },
      { path: 'reports', name: 'reports', component: () => import('@/views/ReportsView.vue'), meta: { permission: 'reports.view' } },
      { path: 'ai', name: 'ai', component: () => import('@/views/AiView.vue'), meta: { permission: 'ai.view' } },
      { path: 'crm', name: 'crm', component: () => import('@/views/CrmView.vue'), meta: { permission: 'crm.view' } },
      { path: 'audit', name: 'audit', component: () => import('@/views/AuditView.vue'), meta: { permission: 'audit.view' } },
      { path: 'security', name: 'security', component: () => import('@/views/SecurityView.vue') },
      { path: 'settings', name: 'settings', component: () => import('@/views/SettingsView.vue'), meta: { permission: 'settings.view' } },
      { path: 'platform', name: 'platform', component: () => import('@/views/PlatformView.vue'), meta: { superAdmin: true } },
      { path: 'templates', name: 'templates', component: () => import('@/views/TemplatesView.vue'), meta: { superAdmin: true } },
    ],
  },

  { path: '/:pathMatch(.*)*', redirect: '/' },
]

const router = createRouter({
  history: createWebHistory(),
  routes,
})

router.beforeEach(async (to) => {
  const auth = useAuthStore()

  if (!auth.ready) await auth.restore()

  if (to.meta.guest) {
    return auth.isAuthenticated ? { name: 'dashboard' } : true
  }

  if (!auth.isAuthenticated) {
    return { name: 'login', query: { redirect: to.fullPath } }
  }

  if (to.meta.superAdmin && !auth.isSuperAdmin) {
    return { name: 'dashboard' }
  }

  // A receptionist landing on the dashboard should not be bounced to a
  // screen they cannot open either, so fall back to the scanner.
  if (to.meta.permission && !auth.can(to.meta.permission)) {
    return to.name === 'check-in' ? false : { name: 'check-in' }
  }

  return true
})

export default router
