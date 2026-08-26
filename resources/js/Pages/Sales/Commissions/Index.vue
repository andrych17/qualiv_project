<!-- Commissions Settlement & Plans (§3M) -->
<script setup lang="ts">
import { ref } from 'vue'
import { Link, useForm, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import SalesSubNav from '@/Components/sales/SalesSubNav.vue'

interface SettlementItem {
  id: number
  period_start: string
  period_end: string
  total_commission: number
  status: string
  rep: { id: number; name: string } | null
  approver: { id: number; name: string } | null
}

interface CommissionPlanItem {
  id: number
  name: string
  calc_type: string
  flat_rate: number | null
  tier_threshold: number | null
  tier_base_rate: number | null
  tier_excess_rate: number | null
  is_active: boolean
}

const props = defineProps<{
  settlements: {
    data: SettlementItem[]
    links: Array<{ url: string | null; label: string; active: boolean }>
  }
  plans: CommissionPlanItem[]
  statuses: string[]
  reps: Array<{ id: number; name: string }>
  filters: { rep_id?: string; status?: string }
}>()

const showBatchModal = ref(false)

const batchForm = useForm({
  rep_id: null as number | null,
  period_start: '',
  period_end: '',
})

const formatCurrency = (val: number, curr = 'IDR') => {
  return new Intl.NumberFormat('id-ID', { style: 'currency', currency: curr, maximumFractionDigits: 0 }).format(val)
}

const submitBatch = () => {
  batchForm.post(route('sales.commissions.store'), {
    onSuccess: () => {
      showBatchModal.value = false
    },
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader
      title="Sales Commissions & Settlements"
      description="Representative commission plans, tiered payout calculations on paid invoices, and monthly settlement batches (§3M)."
    >
      <template #actions>
        <PrimaryButton @click="showBatchModal = true">Generate Settlement Batch</PrimaryButton>
      </template>
    </PageHeader>

    <div class="mt-4">
      <SalesSubNav active="commissions" />
    </div>

    <!-- Settlements Table -->
    <div class="mt-6 rounded-lg border border-border bg-surface-0 overflow-x-auto">
      <table class="w-full text-left text-sm">
        <thead class="bg-surface-50 text-xs text-ink-500 uppercase border-b border-border">
          <tr>
            <th class="py-3 px-4">Settlement Batch #</th>
            <th class="py-3 px-4">Sales Rep</th>
            <th class="py-3 px-4">Period</th>
            <th class="py-3 px-4">Commission Amount</th>
            <th class="py-3 px-4">Approver</th>
            <th class="py-3 px-4">Status</th>
            <th class="py-3 px-4 text-right">Action</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-border">
          <tr v-for="st in props.settlements.data" :key="st.id" class="hover:bg-surface-50">
            <td class="py-3 px-4 font-mono font-medium text-accent">
              <Link :href="route('sales.commissions.show', st.id)" class="hover:underline">
                COMM-{{ st.id.toString().padStart(5, '0') }}
              </Link>
            </td>
            <td class="py-3 px-4 font-medium text-ink-900">{{ st.rep?.name ?? 'Rep' }}</td>
            <td class="py-3 px-4 font-mono text-xs text-ink-600">{{ st.period_start }} &rarr; {{ st.period_end }}</td>
            <td class="py-3 px-4 font-mono font-bold text-ink-900">{{ formatCurrency(Number(st.total_commission)) }}</td>
            <td class="py-3 px-4 text-ink-600">{{ st.approver?.name ?? 'Pending Approval' }}</td>
            <td class="py-3 px-4"><StatusBadge :status="st.status" /></td>
            <td class="py-3 px-4 text-right">
              <Link :href="route('sales.commissions.show', st.id)" class="text-xs font-semibold text-accent hover:underline">
                View &rarr;
              </Link>
            </td>
          </tr>
          <tr v-if="props.settlements.data.length === 0">
            <td colspan="7" class="py-8 text-center text-ink-500">No commission settlements recorded yet.</td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Active Plans Summary -->
    <div class="mt-8">
      <h3 class="text-base font-semibold text-ink-900 mb-3">Configured Commission Plans</h3>
      <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <div v-for="p in props.plans" :key="p.id" class="border border-border rounded-lg p-4 bg-surface-0 shadow-xs">
          <div class="flex items-center justify-between">
            <h4 class="font-semibold text-ink-900">{{ p.name }}</h4>
            <span class="text-xs font-bold uppercase rounded px-2 py-0.5" :class="p.is_active ? 'bg-emerald-100 text-emerald-800' : 'bg-gray-100 text-gray-600'">
              {{ p.is_active ? 'Active' : 'Inactive' }}
            </span>
          </div>
          <div class="mt-2 text-xs text-ink-600 space-y-1">
            <p>Type: <strong class="capitalize">{{ p.calc_type }}</strong></p>
            <p v-if="p.calc_type === 'flat'">Rate: <strong>{{ p.flat_rate }}%</strong></p>
            <p v-else-if="p.calc_type === 'tiered'">
              Base: <strong>{{ p.tier_base_rate }}%</strong>, Above {{ formatCurrency(p.tier_threshold || 0) }}: <strong>{{ p.tier_excess_rate }}%</strong>
            </p>
          </div>
        </div>
      </div>
    </div>

    <!-- Batch Generation Modal -->
    <div v-if="showBatchModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
      <div class="w-full max-w-md rounded-lg bg-surface-0 p-6 shadow-xl border border-border">
        <h3 class="text-lg font-semibold text-ink-900">Generate Commission Settlement</h3>
        <p class="mt-1 text-sm text-ink-600">Calculates earned commissions for a sales rep based on settled payments.</p>

        <form @submit.prevent="submitBatch" class="mt-4 space-y-4">
          <div>
            <label class="block text-xs font-medium text-ink-700 mb-1">Sales Representative *</label>
            <select
              v-model="batchForm.rep_id"
              class="w-full rounded border border-border bg-surface-0 py-2 px-3 text-sm text-ink-900 focus:outline-none"
              required
            >
              <option :value="null">-- Select sales rep --</option>
              <option v-for="r in props.reps" :key="r.id" :value="r.id">{{ r.name }}</option>
            </select>
          </div>

          <div>
            <label class="block text-xs font-medium text-ink-700 mb-1">Period Start Date *</label>
            <input
              v-model="batchForm.period_start"
              type="date"
              class="w-full rounded border border-border bg-surface-0 py-2 px-3 text-sm text-ink-900 focus:outline-none"
              required
            />
          </div>

          <div>
            <label class="block text-xs font-medium text-ink-700 mb-1">Period End Date *</label>
            <input
              v-model="batchForm.period_end"
              type="date"
              class="w-full rounded border border-border bg-surface-0 py-2 px-3 text-sm text-ink-900 focus:outline-none"
              required
            />
          </div>

          <div class="flex items-center justify-end gap-2 pt-2">
            <SecondaryButton @click="showBatchModal = false">Cancel</SecondaryButton>
            <PrimaryButton type="submit" :disabled="batchForm.processing">Calculate Settlement</PrimaryButton>
          </div>
        </form>
      </div>
    </div>
  </AppLayout>
</template>
