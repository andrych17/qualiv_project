<!-- ponytail: Create Draft Payroll Run form. -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'

const props = defineProps<{
  payrollGroups: Array<{ id: number; name: string; code: string }>
}>()

const now = new Date()
const firstDay = new Date(now.getFullYear(), now.getMonth(), 1).toISOString().split('T')[0]
const lastDay = new Date(now.getFullYear(), now.getMonth() + 1, 0).toISOString().split('T')[0]

const form = useForm({
  run_number: '',
  payroll_group_id: '',
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

    <form @submit.prevent="submit" class="max-w-2xl space-y-6">
      <Panel title="Payroll Batch Configuration">
        <div class="space-y-4">
          <div>
            <label class="block text-xs font-medium text-ink-700">Run Type *</label>
            <select
              v-model="form.run_type"
              required
              class="mt-1 block w-full rounded-md border-border bg-surface text-sm text-ink-900 shadow-sm focus:border-accent focus:ring-accent"
            >
              <option value="regular">Regular Monthly Salary</option>
              <option value="thr">Tunjangan Hari Raya (THR)</option>
              <option value="off_cycle">Off Cycle</option>
              <option value="bonus">Bonus Kinerja</option>
              <option value="severance">Severance / Pesangon</option>
            </select>
          </div>

          <div>
            <label class="block text-xs font-medium text-ink-700">Target Payroll Group</label>
            <select
              v-model="form.payroll_group_id"
              class="mt-1 block w-full rounded-md border-border bg-surface text-sm text-ink-900 shadow-sm focus:border-accent focus:ring-accent"
            >
              <option value="">-- All Groups & Active Employees --</option>
              <option v-for="g in payrollGroups" :key="g.id" :value="g.id">{{ g.name }} ({{ g.code }})</option>
            </select>
          </div>

          <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
              <label class="block text-xs font-medium text-ink-700">Period Start *</label>
              <input
                v-model="form.period_start"
                type="date"
                required
                class="mt-1 block w-full rounded-md border-border bg-surface text-sm text-ink-900 shadow-sm focus:border-accent focus:ring-accent"
              />
            </div>
            <div>
              <label class="block text-xs font-medium text-ink-700">Period End *</label>
              <input
                v-model="form.period_end"
                type="date"
                required
                class="mt-1 block w-full rounded-md border-border bg-surface text-sm text-ink-900 shadow-sm focus:border-accent focus:ring-accent"
              />
            </div>
          </div>

          <div>
            <label class="block text-xs font-medium text-ink-700">Pay / Disbursement Date *</label>
            <input
              v-model="form.pay_date"
              type="date"
              required
              class="mt-1 block w-full rounded-md border-border bg-surface text-sm text-ink-900 shadow-sm focus:border-accent focus:ring-accent"
            />
          </div>
        </div>
      </Panel>

      <div class="flex items-center justify-end space-x-3">
        <Link :href="route('payroll.runs.index')">
          <SecondaryButton>Cancel</SecondaryButton>
        </Link>
        <PrimaryButton :disabled="form.processing">Create Draft Run</PrimaryButton>
      </div>
    </form>
  </AppLayout>
</template>
