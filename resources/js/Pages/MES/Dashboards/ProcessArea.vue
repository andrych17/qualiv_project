<!-- ponytail: Process Area Dashboard (MES_SPECS.md §3T) — one focused KPI panel for the whole
     process side of the plant, composed from StatCard only. Read model, nothing stored here. -->
<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import { ref, watch } from 'vue'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import StatCard from '@/Components/cards/StatCard.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import { debounce } from '@/Composables/debounce'

const props = defineProps<{
  date: string
  active_batches: number
  average_yield_pct: number | null
  parameter_alarm_count: number
  qc_hold_count: number
}>()

const date = ref(props.date)

watch(date, debounce(() => {
  router.get(route('mes.dashboards.processArea'), { date: date.value }, { preserveState: true, replace: true })
}, 300))

const pct = (value: number | null) => (value === null ? '—' : `${value}%`)
</script>

<template>
  <AppLayout>
    <PageHeader title="Process Area Dashboard" description="Active batches, average yield, parameter alarms, and QC holds across the process side of the plant (MES_SPECS.md §3T)." />

    <Panel class="mt-6 max-w-xs">
      <FormInput v-model="date" name="date" label="Day" type="date" />
    </Panel>

    <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
      <StatCard title="Active Batches" :value="String(active_batches)" icon="Beaker" />
      <StatCard title="Average Yield" :value="pct(average_yield_pct)" description="completed batches, today" icon="Percent" />
      <StatCard title="Parameter Alarms" :value="String(parameter_alarm_count)" description="open out-of-spec readings" icon="AlertTriangle" :href="route('mes.andon.index')" />
      <StatCard title="Open QC Holds" :value="String(qc_hold_count)" icon="ShieldAlert" />
    </div>
  </AppLayout>
</template>
