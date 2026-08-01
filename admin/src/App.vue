<script setup>
import { storeToRefs } from 'pinia'
import { useUiStore } from '@/stores/ui'

const ui = useUiStore()
const { toast } = storeToRefs(ui)
</script>

<template>
  <RouterView />

  <Transition
    enter-active-class="transition duration-300 ease-out"
    enter-from-class="translate-y-4 opacity-0"
    leave-active-class="transition duration-200 ease-in"
    leave-to-class="translate-y-4 opacity-0"
  >
    <div
      v-if="toast"
      class="glass glass-strong fixed bottom-6 start-1/2 z-50 -translate-x-1/2 px-5 py-3 text-sm font-medium"
      :class="toast.kind === 'error' ? 'text-rose-200' : 'text-brand'"
      role="status"
      aria-live="polite"
    >
      {{ toast.message }}
    </div>
  </Transition>
</template>
