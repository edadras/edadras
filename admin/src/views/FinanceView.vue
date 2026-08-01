<script setup>
import { onMounted, ref } from 'vue'
import api, { errorMessage } from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'
import GlassCard from '@/components/GlassCard.vue'
import StatTile from '@/components/StatTile.vue'
import DataTable from '@/components/DataTable.vue'

const ui = useUiStore()
const auth = useAuthStore()

const register = ref(null)
const transactions = ref([])
const loading = ref(true)
const saving = ref(false)
const expense = ref({ amount: '', category: 'bills', description: '' })

const columns = [
  { key: 'occurred_at', label: ui.t('finance.date', 'Date') },
  { key: 'type', label: ui.t('finance.type', 'Type') },
  { key: 'category', label: ui.t('finance.category', 'Category') },
  { key: 'method', label: ui.t('finance.method', 'Method') },
  { key: 'description', label: ui.t('finance.description', 'Description') },
  { key: 'amount', label: ui.t('finance.amount', 'Amount') },
]

const categories = ['rent', 'salary', 'bills', 'equipment', 'marketing', 'tax', 'supplies', 'other']

async function load() {
  loading.value = true

  const [registerResponse, transactionResponse] = await Promise.all([
    api.get('/finance/register'),
    api.get('/finance/transactions', { params: { per_page: 30 } }),
  ])

  register.value = registerResponse.data
  transactions.value = transactionResponse.data.data
  loading.value = false
}

async function saveExpense() {
  saving.value = true

  try {
    await api.post('/finance/expenses', expense.value)
    ui.notify(ui.t('general.saved', 'Saved.'))
    expense.value = { amount: '', category: 'bills', description: '' }
    load()
  } catch (error) {
    ui.notify(errorMessage(error), 'error')
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="space-y-6">
    <div v-if="register" class="grid gap-4 sm:grid-cols-3">
      <StatTile :label="ui.t('finance.income_today', 'Income today')" :value="register.income" icon="📥" money />
      <StatTile :label="ui.t('finance.expense_today', 'Expenses today')" :value="register.expense" icon="📤" money />
      <StatTile :label="ui.t('finance.net_today', 'Net today')" :value="register.net" icon="🧮" money />
    </div>

    <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_340px]">
      <GlassCard :title="ui.t('finance.transactions', 'Transactions')" :padded="false">
        <DataTable
          :columns="columns"
          :rows="transactions"
          :loading="loading"
          :empty="ui.t('finance.empty', 'No transactions in this period.')"
        >
          <template #cell-occurred_at="{ value }">
            {{ new Date(value).toLocaleDateString() }}
          </template>

          <template #cell-type="{ value }">
            <span :class="value === 'income' ? 'chip-ok' : 'chip-bad'">{{ value }}</span>
          </template>

          <template #cell-amount="{ row }">
            <span class="font-semibold tabular-nums" :class="row.type === 'income' ? 'text-brand' : 'text-rose-300'">
              {{ row.type === 'income' ? '+' : '−' }}{{ Number(row.amount).toLocaleString() }}
            </span>
          </template>
        </DataTable>
      </GlassCard>

      <div class="space-y-4">
        <GlassCard v-if="auth.can('finance.create')" :title="ui.t('finance.add_expense', 'Record an expense')">
          <form class="space-y-3" @submit.prevent="saveExpense">
            <div>
              <label class="label">{{ ui.t('finance.amount', 'Amount') }}</label>
              <input v-model="expense.amount" class="field" type="number" step="0.01" min="0" required />
            </div>
            <div>
              <label class="label">{{ ui.t('finance.category', 'Category') }}</label>
              <select v-model="expense.category" class="field">
                <option v-for="category in categories" :key="category" :value="category">{{ category }}</option>
              </select>
            </div>
            <div>
              <label class="label">{{ ui.t('finance.description', 'Description') }}</label>
              <input v-model="expense.description" class="field" />
            </div>
            <button class="btn-primary w-full" type="submit" :disabled="saving">
              {{ saving ? '…' : ui.t('general.save', 'Save') }}
            </button>
          </form>
        </GlassCard>

        <GlassCard v-if="register" :title="ui.t('finance.by_method', 'By payment method')">
          <ul class="space-y-2">
            <li v-if="!Object.keys(register.by_method).length" class="text-sm text-ink-400">
              {{ ui.t('finance.empty', 'Nothing yet today.') }}
            </li>
            <li
              v-for="(amount, method) in register.by_method"
              :key="method"
              class="flex items-center justify-between rounded-lg bg-white/5 px-3 py-2 text-sm"
            >
              <span class="capitalize">{{ method }}</span>
              <span class="font-semibold tabular-nums">{{ Number(amount).toLocaleString() }}</span>
            </li>
          </ul>
        </GlassCard>
      </div>
    </div>
  </div>
</template>
