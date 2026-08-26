<!-- ponytail: Payroll Runs Index — batch execution list and filterable table. -->
<script setup lang="ts">
import { ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import PayrollSubNav from '@/Components/payroll/PayrollSubNav.vue'

interface Run {
  id: number
  uuid: string
  run_number: string
  period_start: string
  period_end: string
  pay_date: string
  run_type: string
  status: string
  total_gross: string
  total_net: string
  total_tax_pph21: string
  total_bpjs_employer: string
  total_bpjs_employee: string
  payroll_group?: { name: string }
}

const props = defineProps<{
  runs: {
    data: Run[]
    links: Array<{ url: string | null; label: string; active: boolean }>
    total: number
  }
  filters: {
    status?: string
    run_type?: string
  }
}>()

const status = ref(props.filters.status || '')
const runType = ref(props.filters.run_type || '')

const applyFilters = () => {
  router.get(
    route('payroll.runs.index'),
    {
      status: status.value || undefined,
      run_type: runType.value || undefined,
    },
    { preserveState: true }
  )
}

const statusVariant = (st: string) => {
  switch (st) {
    case 'paid':
    case 'locked':
      return 'success'
    case 'approved':
      return 'info'
    case 'calculated':
      return 'warning'
    case 'draft':
      return 'neutral'
    default:
      return 'neutral'
  }
}
</script>

<template>
  <AppLayout title="Payroll Runs">
    <PageHeader title="Payroll Runs" subtitle="Manage and execute monthly and off-cycle payroll batches.">
      <template #actions>
        <Link :href="route('payroll.runs.create')">
          <PrimaryButton>+ New Payroll Run</PrimaryButton>
        </Link>
      </template>
    </PageHeader>

    <div class="space-y-6">
      <PayrollSubNav active="runs" />

      <!-- Filters -->
      <Panel>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div>
            <label class="block text-xs font-medium text-ink-700">Status</label>
            <select
              v-model="status"
              class="mt-1 block w-full rounded-md border-border bg-surface text-sm text-ink-900 shadow-sm focus:border-accent focus:ring-accent"
              @change="applyFilters"
            >
              <option value="">All Statuses</option>
              <option value="draft">Draft</option>
              <option value="calculated">Calculated</option>
              <option value="approved">Approved</option>
              <option value="paid">Paid</option>
              <option value="locked">Locked</option>
            </select>
          </div>
          <div>
            <label class="block text-xs font-medium text-ink-700">Run Type</label>
            <select
              v-model="runType"
              class="mt-1 block w-full rounded-md border-border bg-surface text-sm text-ink-900 shadow-sm focus:border-accent focus:ring-accent"
              @change="applyFilters"
            >
              <option value="">All Run Types</option>
              <option value="regular">Regular</option>
              <option value="off_cycle">Off Cycle</option>
              <option value="thr">THR (Holiday Bonus)</option>
              <option value="bonus">Bonus</option>
              <option value="severance">Severance</option>
            </select>
          </div>
        </div>
      </Panel>

      <!-- Table List -->
      <Panel>
        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-border text-left text-sm">
            <thead class="bg-surface-sunken text-xs font-medium text-ink-500 uppercase">
              <tr>
                <th class="px-4 py-3">Run Number</th>
                <th class="px-4 py-3">Type</th>
                <th class="px-4 py-3">Period</th>
                <th class="px-4 py-3">Pay Date</th>
                <th class="px-4 py-3">Total Net Pay</th>
                <th class="px-4 py-3">PPh 21</th>
                <th class="px-4 py-3">Status</th>
                <th class="px-4 py-3 text-right">Actions</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-border">
              <tr v-if="runs.data.length === 0">
                <td colspan="8" class="p-4 text-center text-ink-500">No payroll runs found.</td>
              </tr>
              <tr v-for="r in runs.data" :key="r.id" class="hover:bg-surface-raised transition">
                <td class="px-4 py-3">
                  <Link :href="route('payroll.runs.show', r.id)" class="font-medium text-ink-900 hover:text-accent">
                    {{ r.run_number }}
                  </Link>
                  <div class="text-xs text-ink-500">{{ r.payroll_group?.name ?? 'All Groups' }}</div>
                </td>
                <td class="px-4 py-3 uppercase text-xs font-medium text-ink-700">{{ r.run_type }}</td>
                <td class="px-4 py-3 text-xs">{{ r.period_start }} to {{ r.period_end }}</td>
                <td class="px-4 py-3 text-xs">{{ r.pay_date }}</td>
                <td class="px-4 py-3 font-semibold text-ink-900">
                  Rp {{ Number(r.total_net).toLocaleString('id-ID') }}
                </td>
                <td class="px-4 py-3 text-ink-700">
                  Rp {{ Number(r.total_tax_pph21).toLocaleString('id-ID') }}
                </td>
                <td class="px-4 py-3">
                  <StatusBadge :status="r.status" :variant="statusVariant(r.status)">
                    {{ r.status }}
                  </StatusBadge>
                </td>
                <td class="px-4 py-3 text-right">
                  <Link :href="route('payroll.runs.show', r.id)" class="text-xs font-medium text-accent hover:underline">
                    View Run &rarr;
                  </Link>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </Panel>
    </div>
  </AppLayout>
</template>
