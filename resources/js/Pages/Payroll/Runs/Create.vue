<!-- ponytail: Create Draft Payroll Run form. -->
<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import FormInput from '@/Components/forms/FormInput.vue'

const props = defineProps<{
  payrollGroups: Array<{ id: number; name: string; code: string }>
}>()

const now = new Date()
const firstDay = new Date(now.getFullYear(), now.getMonth(), 1).toISOString().split('T')[0]
const lastDay = new Date(now.getFullYear(), now.getMonth() + 1, 0).toISOString().split('T')[0]

const form = useForm({
  run_number: '',
  payroll_group_id: null as number | null,
  period_start: firstDay,
  period_end: lastDay,
  pay_date: lastDay,
  run_type: 'regular',
})

const submit = () => {
  form.post(route('payroll.runs.store'))
}
</script>

<template>
  <AppLayout title="Create Payroll Run">
    <PageHeader title="New Payroll Run" subtitle="Initiate a new salary, THR, or off-cycle calculation batch." />

    <form @submit.prevent="submit" class="mt-6 max-w-2xl space-y-6">
      <Panel title="Payroll Batch Configuration">
        <div class="space-y-4">
          <div>
            <FormSelect
              label="Run Type"
              name="run_type"
              v-model="form.run_type"
              :error="form.errors.run_type"
              :options="[
                { label: 'Regular Monthly Salary', value: 'regular' },
                { label: 'Tunjangan Hari Raya (THR)', value: 'thr' },
                { label: 'Off Cycle', value: 'off_cycle' },
                { label: 'Bonus Kinerja', value: 'bonus' },
                { label: 'Severance / Pesangon', value: 'severance' },
              ]"
              required
            />
          </div>

          <div>
            <FormSelect
              label="Target Payroll Group (Optional)"
              name="payroll_group_id"
              v-model="form.payroll_group_id"
              :error="form.errors.payroll_group_id"
              :options="payrollGroups.map(g => ({ label: `${g.name} (${g.code})`, value: g.id }))"
              placeholder="All Groups & Active Employees"
            />
          </div>

          <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
              <FormInput
                label="Period Start"
                name="period_start"
                type="date"
                v-model="form.period_start"
                :error="form.errors.period_start"
                required
              />
            </div>
            <div>
              <FormInput
                label="Period End"
                name="period_end"
                type="date"
                v-model="form.period_end"
                :error="form.errors.period_end"
                required
              />
            </div>
          </div>

          <div>
            <FormInput
              label="Pay / Disbursement Date"
              name="pay_date"
              type="date"
              v-model="form.pay_date"
              :error="form.errors.pay_date"
              required
            />
          </div>
        </div>
      </Panel>

      <div class="flex items-center justify-end gap-3">
        <SecondaryButton :href="route('payroll.runs.index')">Cancel</SecondaryButton>
        <PrimaryButton type="submit" :disabled="form.processing">Create Draft Run</PrimaryButton>
      </div>
    </form>
  </AppLayout>
</template>
