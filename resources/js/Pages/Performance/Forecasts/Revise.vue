<!-- ponytail: Revise Forecast (§3H) — always creates a new version; subject/budget/kpi link can't
     change on a revision, only the horizon, notes, and lines. Pre-filled from the current version. -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormTextarea from '@/Components/forms/FormTextarea.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import ForecastLineListInput, { type ForecastLineRow } from '@/Components/performance/ForecastLineListInput.vue'
import PerformanceSubNav from '@/Components/performance/PerformanceSubNav.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'

const props = defineProps<{
  periods: Array<{ id: number; label: string }>
  forecast: {
    id: number
    linked_label: string
    subject_label: string
    period_id: number
    notes: string | null
    version_no: number
    lines: Array<{ period_id: number; forecast_value: number }>
  }
}>()

const form = useForm({
  period_id: props.forecast.period_id,
  notes: props.forecast.notes ?? '',
  lines: props.forecast.lines.map((l): ForecastLineRow => ({ period_id: l.period_id, forecast_value: l.forecast_value })),
})

const submit = () => form.post(route('performance.forecasts.revise', props.forecast.id))
</script>

<template>
  <AppLayout>
    <PageHeader :title="`Revise: ${forecast.linked_label}`" :description="`Creates version ${forecast.version_no + 1} — ${forecast.subject_label}`" />

    <PerformanceSubNav active="forecasts" class="mt-6" />

    <Panel class="mt-6 max-w-3xl">
      <form class="space-y-4" @submit.prevent="submit">
        <FormSelect
          v-model="form.period_id"
          name="period_id"
          label="Horizon"
          placeholder="Select a period…"
          :options="periods.map((p) => ({ label: p.label, value: p.id }))"
          :error="form.errors.period_id"
          required
        />

        <FormTextarea v-model="form.notes" name="notes" label="Notes" :error="form.errors.notes" />

        <ForecastLineListInput v-model="form.lines" :periods="periods" />
        <p v-if="form.errors.lines" class="text-sm text-signal-danger">{{ form.errors.lines }}</p>

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <Link
            :href="route('performance.forecasts.edit', forecast.id)"
            class="inline-flex items-center justify-center rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm font-semibold text-ink-900 shadow-sm transition hover:bg-surface-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            Cancel
          </Link>
          <PrimaryButton type="submit" :disabled="form.processing">Save new version</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
