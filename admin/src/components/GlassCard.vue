<script setup>
defineProps({
  title: { type: String, default: null },
  subtitle: { type: String, default: null },
  padded: { type: Boolean, default: true },
})
</script>

<template>
  <section class="glass animate-rise overflow-hidden">
    <header
      v-if="title || $slots.actions"
      class="flex items-start justify-between gap-4 border-b border-white/8 px-5 py-4"
    >
      <div>
        <h2 v-if="title" class="text-sm font-semibold tracking-wide text-white">{{ title }}</h2>
        <p v-if="subtitle" class="mt-0.5 text-xs text-ink-400">{{ subtitle }}</p>
      </div>
      <div class="shrink-0"><slot name="actions" /></div>
    </header>

    <div :class="padded ? 'p-5' : ''">
      <slot />
    </div>
  </section>
</template>
