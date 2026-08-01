<script setup>
defineProps({
  columns: { type: Array, required: true },
  rows: { type: Array, default: () => [] },
  loading: { type: Boolean, default: false },
  empty: { type: String, default: 'Nothing to show yet.' },
  rowKey: { type: String, default: 'id' },
})

defineEmits(['row-click'])
</script>

<template>
  <!-- Wide tables scroll on their own so the page never does. -->
  <div class="overflow-x-auto">
    <table class="w-full min-w-max border-collapse">
      <thead>
        <tr class="border-b border-white/8">
          <th v-for="column in columns" :key="column.key" class="table-head">
            {{ column.label }}
          </th>
        </tr>
      </thead>

      <tbody>
        <tr v-if="loading">
          <td :colspan="columns.length" class="table-cell py-10 text-center text-ink-400">
            <span class="inline-block size-5 animate-spin rounded-full border-2 border-white/20 border-t-brand" />
          </td>
        </tr>

        <tr v-else-if="!rows.length">
          <td :colspan="columns.length" class="table-cell py-10 text-center text-ink-400">
            {{ empty }}
          </td>
        </tr>

        <tr
          v-for="row in rows"
          v-else
          :key="row[rowKey]"
          class="border-b border-white/5 transition hover:bg-white/5"
          @click="$emit('row-click', row)"
        >
          <td v-for="column in columns" :key="column.key" class="table-cell">
            <slot :name="`cell-${column.key}`" :row="row" :value="row[column.key]">
              {{ row[column.key] ?? '—' }}
            </slot>
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>
