<!-- ponytail: Add Period (§3C/§4) -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormNumberInput from '@/Components/forms/FormNumberInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import PerformanceSubNav from '@/Components/performance/PerformanceSubNav.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'

const form = useForm({
  label: '',
  period_type: 'month' as 'year' | 'quarter' | 'month',
  year: new Date().getFullYear(),
  quarter: null as number | null,
  month: null as number | null,
  start_date: '',
  end_date: '',
})

const submit = () => form.post(route('performance.periods.store'))
</script>

<template>
  <AppLayout>
    <PageHeader title="Add period" description="A fiscal period slice — year, quarter, or month." />

    <PerformanceSubNav active="periods" class="mt-6" />

    <Panel class="mt-6 max-w-xl">
      <form class="space-y-4" @submit.prevent="submit">
        <FormInput v-model="form.label" name="label" label="Label" placeholder="e.g. 2026-Q3" :error="form.errors.label" required />
        <FormSelect
          v-model="form.period_type"
          name="period_type"
          label="Type"
          :options="[{ label: 'Year', value: 'year' }, { label: 'Quarter', value: 'quarter' }, { label: 'Month', value: 'month' }]"
          :error="form.errors.period_type"
          required
        />

        <div class="grid grid-cols-3 gap-4">
          <FormNumberInput v-model="form.year" name="year" label="Year" :error="form.errors.year" />
          <FormNumberInput v-if="form.period_type === 'quarter'" v-model="form.quarter" name="quarter" label="Quarter (1-4)" :error="form.errors.quarter" />
          <FormNumberInput v-if="form.period_type === 'month'" v-model="form.month" name="month" label="Month (1-12)" :error="form.errors.month" />
        </div>

        <div class="grid grid-cols-2 gap-4">
          <FormInput v-model="form.start_date" name="start_date" type="date" label="Start date" :error="form.errors.start_date" required />
          <FormInput v-model="form.end_date" name="end_date" type="date" label="End date" :error="form.errors.end_date" required />
        </div>

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <Link
            :href="route('performance.periods.index')"
            class="inline-flex items-center justify-center rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm font-semibold text-ink-900 shadow-sm transition hover:bg-surface-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            Cancel
          </Link>
          <PrimaryButton type="submit" :disabled="form.processing">Save period</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
