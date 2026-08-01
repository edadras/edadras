<script setup>
import { computed, onMounted, ref } from 'vue'
import api, { errorMessage } from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'
import GlassCard from '@/components/GlassCard.vue'

const ui = useUiStore()
const auth = useAuthStore()

const products = ref([])
const basket = ref([])
const loading = ref(true)
const selling = ref(false)

const total = computed(() =>
  basket.value.reduce((sum, line) => sum + Number(line.product.price) * line.quantity, 0),
)

async function load() {
  loading.value = true

  const { data } = await api.get('/products', { params: { per_page: 100 } })

  products.value = data.data
  loading.value = false
}

function add(product) {
  const line = basket.value.find((item) => item.product.id === product.id)

  if (line) {
    if (line.quantity < product.stock) line.quantity += 1

    return
  }

  if (product.stock > 0) basket.value.push({ product, quantity: 1 })
}

function remove(productId) {
  basket.value = basket.value.filter((line) => line.product.id !== productId)
}

async function checkout(method) {
  if (!basket.value.length) return

  selling.value = true

  try {
    await api.post('/shop/sell', {
      items: basket.value.map((line) => ({ product_id: line.product.id, quantity: line.quantity })),
      pay_now: true,
      method,
    })

    ui.notify(ui.t('shop.sold', 'Sale recorded.'))
    basket.value = []
    load()
  } catch (error) {
    ui.notify(errorMessage(error), 'error')
  } finally {
    selling.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_340px]">
    <GlassCard :title="ui.t('nav.shop', 'Shop')" :subtitle="ui.t('shop.tap_to_add', 'Tap a product to add it to the basket')">
      <div v-if="loading" class="py-10 text-center">
        <span class="inline-block size-6 animate-spin rounded-full border-2 border-white/20 border-t-brand" />
      </div>

      <div v-else class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
        <button
          v-for="product in products"
          :key="product.id"
          type="button"
          class="glass glass-hover p-4 text-start disabled:opacity-40"
          :disabled="product.stock <= 0"
          @click="add(product)"
        >
          <div class="flex items-start justify-between gap-2">
            <p class="min-w-0 flex-1 truncate text-sm font-semibold">{{ product.name }}</p>
            <span :class="product.stock <= product.min_stock ? 'chip-bad' : 'chip-muted'">
              {{ product.stock }}
            </span>
          </div>
          <p class="mt-2 text-lg font-bold tabular-nums text-brand">
            {{ Number(product.price).toLocaleString() }}
          </p>
          <p class="mt-0.5 text-xs text-ink-400">{{ product.category }}</p>
        </button>
      </div>
    </GlassCard>

    <GlassCard :title="ui.t('shop.basket', 'Basket')">
      <ul class="space-y-2">
        <li v-if="!basket.length" class="py-6 text-center text-sm text-ink-400">
          {{ ui.t('shop.empty_basket', 'The basket is empty.') }}
        </li>

        <li
          v-for="line in basket"
          :key="line.product.id"
          class="flex items-center gap-2 rounded-xl bg-white/5 px-3 py-2"
        >
          <span class="min-w-0 flex-1 truncate text-sm">{{ line.product.name }}</span>

          <input
            v-model.number="line.quantity"
            class="field !w-16 !px-2 !py-1 text-center text-sm"
            type="number"
            min="1"
            :max="line.product.stock"
          />

          <span class="w-24 text-end text-sm font-semibold tabular-nums">
            {{ (line.product.price * line.quantity).toLocaleString() }}
          </span>

          <button class="text-rose-300 hover:text-rose-200" type="button" @click="remove(line.product.id)">✕</button>
        </li>
      </ul>

      <div class="mt-4 flex items-center justify-between border-t border-white/8 pt-4">
        <span class="text-sm text-ink-400">{{ ui.t('shop.total', 'Total') }}</span>
        <span class="text-xl font-bold tabular-nums text-brand">{{ total.toLocaleString() }}</span>
      </div>

      <div v-if="auth.can('shop.create')" class="mt-4 grid grid-cols-2 gap-2">
        <button class="btn-primary" type="button" :disabled="selling || !basket.length" @click="checkout('cash')">
          💵 {{ ui.t('shop.cash', 'Cash') }}
        </button>
        <button class="btn-ghost" type="button" :disabled="selling || !basket.length" @click="checkout('card')">
          💳 {{ ui.t('shop.card', 'Card') }}
        </button>
      </div>
    </GlassCard>
  </div>
</template>
