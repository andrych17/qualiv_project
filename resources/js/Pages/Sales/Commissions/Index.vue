<!-- Commissions Settlement & Plans (§3M) -->
<script setup lang="ts">
import { ref, watch } from 'vue'
import { Link, useForm, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import SalesSubNav from '@/Components/sales/SalesSubNav.vue'
import Modal from '@/Components/Modal.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import DataTable, { type FilterFieldDef, type SortState } from '@/Components/tables/DataTable.vue'
import { debounce } from '@/Composables/debounce'
import { formatCurrency, formatDate } from '@/Utils/formatters'

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

interface PaginatedData<T> {
  data: T[]
  links: Array<{ url: string | null; label: string; active: boolean }>
  total: number
  from: number | null
  to: number | null
  per_page: number
}

const props = defineProps<{
  settlements: PaginatedData<SettlementItem>
  plans: CommissionPlanItem[]
  statuses: string[]
  reps: Array<{ id: number; name: string }>
  filters: { rep_id?: string; status?: string; sort?: string; direction?: string; per_page?: string }
}>()

const search = ref('')
const filters = ref({
  status: props.filters.status ?? '',
  rep_id: props.filters.rep_id ?? '',
})
const sort = ref<SortState>(
  props.filters.sort ? { key: props.filters.sort, direction: props.filters.direction === 'desc' ? 'desc' : 'asc' } : null,
)
const selected = ref<Array<string | number>>([])
const perPage = ref(Number(props.filters.per_page) || props.settlements.per_page)

const filterFields: FilterFieldDef[] = [
  {
    key: 'status',
    label: 'Status',
    type: 'select',
    options: props.statuses.map((st) => ({ label: st.toUpperCase(), value: st })),
  },
  {
    key: 'rep_id',
    label: 'Sales Rep',
    type: 'select',
    options: props.reps.map((r) => ({ label: r.name, value: String(r.id) })),
  },
]

const columns = [
  { key: 'batch_no', label: 'Settlement Batch #' },
  { key: 'rep', label: 'Sales Rep' },
  { key: 'period', label: 'Period' },
  { key: 'total_commission', label: 'Commission Amount', align: 'right' as const, sortable: true },
  { key: 'approver', label: 'Approver' },
  { key: 'status', label: 'Status', sortable: true },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

const showBatchModal = ref(false)

const batchForm = useForm({
  rep_id: null as number | null,
  period_start: '',
  period_end: '',
})

const submitBatch = () => {
  batchForm.post(route('sales.commissions.store'), {
    onSuccess: () => {
      showBatchModal.value = false
      batchForm.reset()
    },
  })
}

watch([search, filters, sort, perPage], debounce(() => {
  selected.value = []
  router.get(route('sales.commissions.index'), {
    rep_id: filters.value.rep_id || undefined,
    status: filters.value.status || undefined,
    sort: sort.value?.key,
    direction: sort.value?.direction,
    per_page: perPage.value,
  }, { preserveState: true, replace: true })
}, 400))
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

    <div class="mt-6">
      <DataTable
        :columns="columns"
        :items="settlements.data"
        v-model:sort="sort"
        v-model:selected="selected"
        v-model:search="search"
        v-model:filters="filters"
        v-model:per-page="perPage"
        sticky-header
        storage-key="sales.commissions"
        :filter-fields="filterFields"
        export-filename="sales-commissions"
        status-rail-key="status"
        :total="settlements.total"
        :from="settlements.from"
        :to="settlements.to"
        :links="settlements.links"
        empty-title="No commission settlements found"
        empty-description="Generate a settlement batch for your sales team across closed/paid sales."
      >
        <template #cell-batch_no="{ item }">
          <Link
            :href="route('sales.commissions.show', item.id)"
            class="font-mono font-medium text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            COMM-{{ (item as SettlementItem).id.toString().padStart(5, '0') }}
          </Link>
        </template>

        <template #cell-rep="{ item }">
          <span class="font-medium text-ink-900">{{ (item as SettlementItem).rep?.name ?? 'Rep' }}</span>
        </template>

        <template #cell-period="{ item }">
          <span class="font-mono text-xs text-ink-600">
            {{ formatDate((item as SettlementItem).period_start) }} &rarr; {{ formatDate((item as SettlementItem).period_end) }}
          </span>
        </template>

        <template #cell-total_commission="{ item }">
          <span class="font-mono font-bold text-ink-900">
            {{ formatCurrency(Number((item as SettlementItem).total_commission)) }}
          </span>
        </template>

        <template #cell-approver="{ item }">
          <span class="text-xs text-ink-600">{{ (item as SettlementItem).approver?.name ?? 'Pending Approval' }}</span>
        </template>

        <template #cell-status="{ item }">
          <StatusBadge :status="(item as SettlementItem).status" />
        </template>

        <template #cell-actions="{ item }">
          <Link
            :href="route('sales.commissions.show', item.id)"
            class="text-sm font-semibold text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            View &rarr;
          </Link>
        </template>
      </DataTable>
    </div>

    <!-- Active Plans Summary -->
    <div class="mt-8">
      <h3 class="text-base font-semibold text-ink-900 mb-3">Configured Commission Plans</h3>
      <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <Panel v-for="p in props.plans" :key="p.id">
          <template #header>
            <div class="flex items-center justify-between w-full">
              <h4 class="font-semibold text-ink-900">{{ p.name }}</h4>
              <StatusBadge :status="p.is_active ? 'active' : 'inactive'" />
            </div>
          </template>
          <div class="text-xs text-ink-600 space-y-1">
            <p>Type: <strong class="capitalize">{{ p.calc_type }}</strong></p>
            <p v-if="p.calc_type === 'flat'">Rate: <strong>{{ p.flat_rate }}%</strong></p>
            <p v-else-if="p.calc_type === 'tiered'">
              Base: <strong>{{ p.tier_base_rate }}%</strong>, Above {{ formatCurrency(p.tier_threshold || 0) }}: <strong>{{ p.tier_excess_rate }}%</strong>
            </p>
          </div>
        </Panel>
      </div>
    </div>

    <!-- Batch Generation Modal -->
    <Modal :show="showBatchModal" max-width="md" @close="showBatchModal = false">
      <div class="p-6 bg-surface-0 border border-border text-ink-900 rounded-lg">
        <h3 class="text-lg font-semibold text-ink-900">Generate Commission Settlement</h3>
        <p class="mt-1 text-sm text-ink-600">Calculates earned commissions for a sales rep based on settled payments.</p>

        <form @submit.prevent="submitBatch" class="mt-4 space-y-4">
          <FormSelect
            label="Sales Representative"
            name="rep_id"
            v-model="batchForm.rep_id"
            :options="props.reps.map(r => ({ label: r.name, value: r.id }))"
            placeholder="Select sales rep…"
            :error="batchForm.errors.rep_id"
            required
          />

          <FormInput
            type="date"
            label="Period Start Date"
            name="period_start"
            v-model="batchForm.period_start"
            :error="batchForm.errors.period_start"
            required
          />

          <FormInput
            type="date"
            label="Period End Date"
            name="period_end"
            v-model="batchForm.period_end"
            :error="batchForm.errors.period_end"
            required
          />

          <div class="flex items-center justify-end gap-2 pt-2">
            <SecondaryButton @click="showBatchModal = false">Cancel</SecondaryButton>
            <PrimaryButton type="submit" :disabled="batchForm.processing">Calculate Settlement</PrimaryButton>
          </div>
        </form>
      </div>
    </Modal>
  </AppLayout>
</template>
