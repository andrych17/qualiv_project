<!-- ponytail: Employment Contracts Index — list contracts, renewal workflows, and compliance expiry monitoring. -->
<script setup lang="ts">
import { ref, watch } from 'vue'
import { router, useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import HcmSubNav from '@/Components/hcm/HcmSubNav.vue'
import Modal from '@/Components/Modal.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormCurrencyInput from '@/Components/forms/FormCurrencyInput.vue'
import DataTable, { type FilterFieldDef, type SortState } from '@/Components/tables/DataTable.vue'
import { debounce } from '@/Composables/debounce'
import { useConfirm } from '@/Composables/useConfirmDialog'
import { formatCurrency, formatDate } from '@/Utils/formatters'

interface Contract {
  id: number
  employee_id: number
  contract_type: string
  start_date: string
  end_date?: string
  base_salary: string
  work_location?: string
  probation_end_date?: string
  status: string
  employee: {
    id: number
    employee_no: string
    full_name: string
    position?: { job?: { title: string }; org_unit?: { name: string } }
  }
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
  contracts: PaginatedData<Contract>
  expiringContracts: Contract[]
  filters: {
    search?: string
    contract_type?: string
    status?: string
    sort?: string
    direction?: string
    per_page?: string
  }
}>()

const search = ref(props.filters.search ?? '')
const filters = ref({
  contract_type: props.filters.contract_type ?? '',
  status: props.filters.status ?? '',
})
const sort = ref<SortState>(
  props.filters.sort ? { key: props.filters.sort, direction: props.filters.direction === 'desc' ? 'desc' : 'asc' } : null,
)
const selected = ref<Array<string | number>>([])
const perPage = ref(Number(props.filters.per_page) || props.contracts.per_page)

const filterFields: FilterFieldDef[] = [
  {
    key: 'contract_type',
    label: 'Contract Type',
    type: 'select',
    options: [
      { label: 'PKWT (Fixed Term)', value: 'PKWT' },
      { label: 'PKWTT (Permanent)', value: 'PKWTT' },
    ],
  },
  {
    key: 'status',
    label: 'Status',
    type: 'select',
    options: [
      { label: 'Active', value: 'active' },
      { label: 'Expired', value: 'expired' },
      { label: 'Terminated', value: 'terminated' },
    ],
  },
]

const columns = [
  { key: 'employee', label: 'Employee' },
  { key: 'contract_type', label: 'Contract Type', sortable: true },
  { key: 'start_date', label: 'Start Date', sortable: true },
  { key: 'end_date', label: 'End Date', sortable: true },
  { key: 'base_salary', label: 'Base Salary', sortable: true, align: 'right' as const },
  { key: 'status', label: 'Status', sortable: true },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

const renewForm = useForm({
  contract_type: 'PKWT',
  start_date: new Date().toISOString().split('T')[0],
  end_date: '',
  base_salary: 0,
  work_location: '',
  probation_end_date: '',
})

const selectedContract = ref<Contract | null>(null)
const showRenewModal = ref(false)

const openRenew = (c: Contract) => {
  selectedContract.value = c
  renewForm.contract_type = c.contract_type
  renewForm.start_date = c.end_date || new Date().toISOString().split('T')[0]
  renewForm.end_date = ''
  renewForm.base_salary = Number(c.base_salary)
  renewForm.work_location = c.work_location || ''
  renewForm.probation_end_date = ''
  showRenewModal.value = true
}

const submitRenew = () => {
  if (!selectedContract.value) return
  renewForm.post(route('hcm.contracts.renew', selectedContract.value.id), {
    onSuccess: () => {
      showRenewModal.value = false
    },
  })
}

const { confirm } = useConfirm()

const terminateContract = (c: Contract) => {
  confirm({
    title: 'Terminate Contract?',
    description: `Terminate contract for ${c.employee.full_name}?`,
    variant: 'destructive',
    confirmText: 'Terminate',
    onConfirm: () => router.post(route('hcm.contracts.terminate', c.id)),
  })
}

watch([search, filters, sort, perPage], debounce(() => {
  selected.value = []
  router.get(
    route('hcm.contracts.index'),
    {
      search: search.value || undefined,
      contract_type: filters.value.contract_type || undefined,
      status: filters.value.status || undefined,
      sort: sort.value?.key,
      direction: sort.value?.direction,
      per_page: perPage.value,
    },
    { preserveState: true, replace: true }
  )
}, 400))
</script>

<template>
  <AppLayout title="Employment Contracts">
    <PageHeader title="Employment Contracts" subtitle="Track PKWT/PKWTT contracts, compliance durations, and renewals." />

    <div class="mt-4">
      <HcmSubNav active="contracts" />
    </div>

    <!-- Expiring warning alert -->
    <div v-if="expiringContracts.length > 0" class="mt-4 rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-ink-900">
      <div class="font-bold flex items-center gap-2 text-amber-900">
        <span>⚠️</span> {{ expiringContracts.length }} Contract(s) Expiring Soon (Next 60 Days)
      </div>
      <div class="mt-2 flex flex-wrap gap-2">
        <span
          v-for="c in expiringContracts"
          :key="c.id"
          class="rounded bg-surface-0 px-2 py-1 text-xs border border-amber-200 text-ink-800"
        >
          {{ c.employee.full_name }} (Ends {{ c.end_date }})
        </span>
      </div>
    </div>

    <div class="mt-6">
      <DataTable
        :columns="columns"
        :items="contracts.data"
        v-model:sort="sort"
        v-model:selected="selected"
        v-model:search="search"
        v-model:filters="filters"
        v-model:per-page="perPage"
        sticky-header
        storage-key="hcm.contracts"
        search-placeholder="Search employee name, number…"
        :filter-fields="filterFields"
        export-filename="hcm-contracts"
        status-rail-key="status"
        :total="contracts.total"
        :from="contracts.from"
        :to="contracts.to"
        :links="contracts.links"
        empty-title="No contracts found"
        empty-description="Create an employment contract for onboarding employees."
      >
        <template #cell-employee="{ item }">
          <div>
            <Link
              :href="route('hcm.employees.show', (item as Contract).employee.id)"
              class="font-semibold text-ink-900 hover:text-accent"
            >
              {{ (item as Contract).employee.full_name }}
            </Link>
            <span class="block font-mono text-[11px] text-ink-400">
              {{ (item as Contract).employee.employee_no }}
            </span>
          </div>
        </template>

        <template #cell-contract_type="{ item }">
          <span class="font-medium text-xs text-ink-800">{{ (item as Contract).contract_type }}</span>
        </template>

        <template #cell-start_date="{ item }">
          <span class="font-mono text-xs text-ink-600">{{ formatDate((item as Contract).start_date) }}</span>
        </template>

        <template #cell-end_date="{ item }">
          <span v-if="(item as Contract).end_date" class="font-mono text-xs text-ink-600">
            {{ formatDate((item as Contract).end_date!) }}
          </span>
          <span v-else class="text-xs text-emerald-700 font-medium">Permanent (PKWTT)</span>
        </template>

        <template #cell-base_salary="{ item }">
          <span class="font-mono text-xs font-semibold text-ink-900">
            {{ formatCurrency(Number((item as Contract).base_salary)) }}
          </span>
        </template>

        <template #cell-status="{ item }">
          <StatusBadge :status="(item as Contract).status" />
        </template>

        <template #cell-actions="{ item }">
          <div class="flex items-center justify-end gap-3">
            <button
              v-if="(item as Contract).status === 'active'"
              type="button"
              class="text-xs font-semibold text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
              @click="openRenew(item as Contract)"
            >
              Renew
            </button>
            <button
              v-if="(item as Contract).status === 'active'"
              type="button"
              class="text-xs font-medium text-signal-danger hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
              @click="terminateContract(item as Contract)"
            >
              Terminate
            </button>
          </div>
        </template>
      </DataTable>
    </div>

    <!-- Renew Modal -->
    <Modal :show="showRenewModal" max-width="md" @close="showRenewModal = false">
      <div class="p-6 bg-white rounded-lg">
        <h3 class="text-lg font-bold text-ink-900">Renew Employment Contract</h3>
        <p class="mt-1 text-sm text-ink-600">Employee: {{ selectedContract?.employee.full_name }}</p>

        <form @submit.prevent="submitRenew" class="mt-4 space-y-4">
          <div>
            <FormSelect
              label="Contract Type"
              name="contract_type"
              v-model="renewForm.contract_type"
              :options="[
                { label: 'PKWT (Fixed Term)', value: 'PKWT' },
                { label: 'PKWTT (Permanent Conversion)', value: 'PKWTT' },
              ]"
              required
            />
          </div>
          <div>
            <FormInput
              label="Start Date"
              name="start_date"
              type="date"
              v-model="renewForm.start_date"
              :error="renewForm.errors.start_date"
              required
            />
          </div>
          <div v-if="renewForm.contract_type === 'PKWT'">
            <FormInput
              label="End Date"
              name="end_date"
              type="date"
              v-model="renewForm.end_date"
              :error="renewForm.errors.end_date"
              required
            />
          </div>
          <div>
            <FormCurrencyInput
              label="Base Salary"
              name="base_salary"
              v-model="renewForm.base_salary"
              :error="renewForm.errors.base_salary"
              required
            />
          </div>

          <div class="flex justify-end gap-3 pt-2">
            <SecondaryButton type="button" @click="showRenewModal = false">Cancel</SecondaryButton>
            <PrimaryButton type="submit" :disabled="renewForm.processing">Renew Contract</PrimaryButton>
          </div>
        </form>
      </div>
    </Modal>
  </AppLayout>
</template>
