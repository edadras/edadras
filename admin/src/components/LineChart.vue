<script setup>
import { computed } from 'vue'
import { Line } from 'vue-chartjs'
import {
  Chart as ChartJS,
  CategoryScale,
  LinearScale,
  PointElement,
  LineElement,
  Filler,
  Tooltip,
} from 'chart.js'

ChartJS.register(CategoryScale, LinearScale, PointElement, LineElement, Filler, Tooltip)

const props = defineProps({
  series: { type: Array, default: () => [] },
  label: { type: String, default: '' },
  color: { type: String, default: '#5ef38c' },
})

const chartData = computed(() => ({
  labels: props.series.map((point) => point.date?.slice(5) ?? point.hour ?? ''),
  datasets: [
    {
      label: props.label,
      data: props.series.map((point) => point.total ?? point.value ?? 0),
      borderColor: props.color,
      backgroundColor: `${props.color}22`,
      borderWidth: 2,
      pointRadius: 0,
      pointHoverRadius: 4,
      tension: 0.35,
      fill: true,
    },
  ],
}))

const options = {
  responsive: true,
  maintainAspectRatio: false,
  plugins: {
    legend: { display: false },
    tooltip: {
      backgroundColor: 'rgba(13,15,18,0.92)',
      borderColor: 'rgba(255,255,255,0.12)',
      borderWidth: 1,
      padding: 10,
      displayColors: false,
    },
  },
  scales: {
    x: {
      grid: { display: false },
      ticks: { color: '#6b7280', maxTicksLimit: 8, font: { size: 10 } },
      border: { display: false },
    },
    y: {
      grid: { color: 'rgba(255,255,255,0.06)' },
      ticks: { color: '#6b7280', maxTicksLimit: 5, font: { size: 10 } },
      border: { display: false },
      beginAtZero: true,
    },
  },
}
</script>

<template>
  <div class="h-56">
    <Line :data="chartData" :options="options" />
  </div>
</template>
