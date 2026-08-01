<script setup>
import { computed } from 'vue'

const props = defineProps({
  label: { type: String, required: true },
  value: { type: [String, Number], default: 0 },
  icon: { type: String, default: '📊' },
  hint: { type: String, default: null },
  trend: { type: Number, default: null },
  money: { type: Boolean, default: false },
})

const formatted = computed(() => {
  if (typeof props.value !== 'number') return props.value

  return new Intl.NumberFormat().format(props.money ? Math.round(props.value) : props.value)
})

const trendClass = computed(() => {
  if (props.trend === null) return ''

  return props.trend >= 0 ? 'text-brand' : 'text-rose-300'
})
</script>

<template>
  <div class="glass glass-hover animate-rise p-5">
    <div class="flex items-start justify-between gap-3">
      <div class="min-w-0">
        <p class="truncate text-xs font-medium tracking-wide text-ink-400">{{ label }}</p>
        <p class="mt-2 text-2xl font-bold tabular-nums text-white">{{ formatted }}</p>
        <p v-if="hint" class="mt-1 truncate text-xs text-ink-400">{{ hint }}</p>
      </div>

      <!-- A soft-lit glyph plate: the "3D icon" of the design language. -->
      <span
        class="grid size-11 shrink-0 place-items-center rounded-2xl text-xl"
        style="
          background: linear-gradient(150deg, rgba(94, 243, 140, 0.28), rgba(255, 255, 255, 0.06));
          box-shadow:
            inset 0 1px 0 rgba(255, 255, 255, 0.35),
            0 10px 22px -14px rgba(94, 243, 140, 0.9);
        "
        aria-hidden="true"
      >
        {{ icon }}
      </span>
    </div>

    <p v-if="trend !== null" class="mt-3 text-xs font-semibold" :class="trendClass">
      {{ trend >= 0 ? '▲' : '▼' }} {{ Math.abs(trend) }}%
    </p>
  </div>
</template>
