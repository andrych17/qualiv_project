<!-- ponytail: OEE & Process KPIs (MES_SPECS.md §3O) — pure read model composed from StatCard/Panel
     only (CLAUDE.md §9D), scoped to a Work Center × Day (see OeeService's own docblock for why
     Machine-level isn't supported by this build's data model). No dashboard-only storage. -->
<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import { ref, watch } from 'vue'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import StatCard from '@/Components/cards/StatCard.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import { debounce } from '@/Composables/debounce'

interface Assembly {
  availability_pct: number | null
  performance_pct: number | null
  quality_pct: number | null
  oee_pct: number | null
  operating_minutes: number
  downtime_minutes: number
}

interface Process {
  yield_pct: number | null
  parameter_in_spec_pct: number | null
  qc_hold_count: number
}

const props = defineProps<{
  date: string
  work_center_id: number | null
  assembly: Assembly
  qc_pass_rate_pct: number | null
  process: Process
  workCenters: Array<{ value: number; label: string }>
}>()

const date = ref(props.date)
const workCenterId = ref<number | null>(props.work_center_id)

watch([date, workCenterId], debounce(() => {
  router.get(route('mes.oee.index'), { date: date.value, work_center_id: workCenterId.value }, { preserveState: true, replace: true })
}, 300))

const pct = (value: number | null) => (value === null ? '—' : `${value}%`)
</script>

<template>
  <AppLayout>
    <PageHeader title="OEE & Process KPIs" description="Assembly OEE (Availability × Performance × Quality) and process-specific KPIs (yield, parameter-in-spec %) — a read model over §3C/§3J/§3L/§3M, nothing stored here (§3O)." />

    <Panel class="mt-6 max-w-xl">
      <div class="grid grid-cols-2 gap-4">
        <FormInput v-model="date" name="date" label="Day" type="date" />
        <FormSelect v-model="workCenterId" name="work_center_id" label="Work Center (all if blank)" :options="workCenters" />
      </div>
    </Panel>

    <h2 class="mt-8 text-sm font-semibold uppercase tracking-wide text-ink-600">Assembly OEE</h2>
    <div class="mt-3 grid grid-cols-1 gap-4 sm:grid-cols-4">
      <StatCard title="OEE" :value="pct(assembly.oee_pct)" description="Availability × Performance × Quality" icon="Gauge" />
      <StatCard title="Availability" :value="pct(assembly.availability_pct)" description="operating / (operating + downtime)" icon="Clock" :href="route('mes.downtimeEvents.index')" />
      <StatCard title="Performance" :value="pct(assembly.performance_pct)" description="standard minutes / operating minutes" icon="Zap" />
      <StatCard title="Quality" :value="pct(assembly.quality_pct)" description="good output / (good + scrap)" icon="CheckCircle2" />
    </div>

    <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-3">
      <StatCard title="Operating Time" :value="`${assembly.operating_minutes.toFixed(0)} min`" icon="PlayCircle" />
      <StatCard title="Downtime" :value="`${assembly.downtime_minutes.toFixed(0)} min`" icon="AlertOctagon" :href="route('mes.downtimeEvents.index')" />
      <StatCard title="QC Pass Rate" :value="pct(qc_pass_rate_pct)" description="§3L results, order-scoped, this day" icon="ClipboardCheck" />
    </div>

    <h2 class="mt-8 text-sm font-semibold uppercase tracking-wide text-ink-600">Process KPIs</h2>
    <div class="mt-3 grid grid-cols-1 gap-4 sm:grid-cols-3">
      <StatCard title="Yield" :value="pct(process.yield_pct)" description="good / (good + scrap), process orders" icon="Percent" />
      <StatCard title="Parameter In-Spec" :value="pct(process.parameter_in_spec_pct)" description="readings within [min, max]" icon="Sliders" />
      <StatCard title="Open QC Holds" :value="String(process.qc_hold_count)" icon="ShieldAlert" />
    </div>
  </AppLayout>
</template>
