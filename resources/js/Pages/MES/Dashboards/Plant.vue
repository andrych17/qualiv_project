<!-- ponytail: Plant Dashboard (MES_SPECS.md §3T) — one focused KPI panel, composed from StatCard
     only. Read model over §3C/§3J/§3L/§3M/§3O; see MesDashboardService::plant(). -->
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
  production_to_plan_pct: number | null
  oee_pct: number | null
  process_yield_pct: number | null
  downtime_minutes: number
  reject_rate_pct: number | null
  active_orders: number
  active_batches: number
  open_andon_alert_count: number
}>()

const date = ref(props.date)

watch(date, debounce(() => {
  router.get(route('mes.dashboards.plant'), { date: date.value }, { preserveState: true, replace: true })
}, 300))

const pct = (value: number | null) => (value === null ? '—' : `${value}%`)
</script>

<template>
  <AppLayout>
    <PageHeader title="Plant Dashboard" description="Plant-wide production-to-plan, OEE, downtime, and reject rate for the day (MES_SPECS.md §3T) — a read model, nothing stored here." />

    <Panel class="mt-6 max-w-xs">
      <FormInput v-model="date" name="date" label="Day" type="date" />
    </Panel>

    <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-3 lg:grid-cols-4">
      <StatCard title="Production to Plan" :value="pct(production_to_plan_pct)" description="orders due today, completed" icon="Target" />
      <StatCard title="OEE" :value="pct(oee_pct)" description="assembly, plant-wide" icon="Gauge" :href="route('mes.oee.index')" />
      <StatCard title="Process Yield" :value="pct(process_yield_pct)" icon="Percent" />
      <StatCard title="Reject Rate" :value="pct(reject_rate_pct)" icon="AlertTriangle" />
      <StatCard title="Downtime" :value="`${downtime_minutes.toFixed(0)} min`" icon="AlertOctagon" :href="route('mes.downtimeEvents.index')" />
      <StatCard title="Active Orders" :value="String(active_orders)" icon="ClipboardList" :href="route('mes.prodOrders.index')" />
      <StatCard title="Active Batches" :value="String(active_batches)" icon="Beaker" />
      <StatCard title="Open Andon Alerts" :value="String(open_andon_alert_count)" icon="AlarmClockCheck" :href="route('mes.andon.index')" />
    </div>
  </AppLayout>
</template>
