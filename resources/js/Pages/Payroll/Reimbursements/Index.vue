<!-- ponytail: Employee Reimbursements Index — claims list, submission, and review workflow. -->
<script setup lang="ts">
import { ref, watch } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import PayrollSubNav from '@/Components/payroll/PayrollSubNav.vue'
import Modal from '@/Components/Modal.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormCurrencyInput from '@/Components/forms/FormCurrencyInput.vue'
import FormTextarea from '@/Components/forms/FormTextarea.vue'
import DataTable, { type FilterFieldDef, type SortState } from '@/Components/tables/DataTable.vue'
import { debounce } from '@/Composables/debounce'
import { formatCurrency, formatDate } from '@/Utils/formatters'

interface Claim {
  id: number
  claim_date: string
  amount: string
  description?: string
  status: string
  employee: { id: number; employee_no: string; full_name: string }
  category: { name: string }
  reviewer?: { name: string }
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
  claims: PaginatedData<Claim>
  categories: Array<{ id: number; name: string }>
  employees: Array<{ id: number; employee_no: string; full_name: string }>
  filters: {
    search?: string
    status?: string
    reimbursement_category_id?: string
    sort?: string
    direction?: string
    per_page?: string
  }
}>()

const search = ref(props.filters.search ?? '')
const filters = ref({
  status: props.filters.status ?? '',
  reimbursement_category_id: props.filters.reimbursement_category_id ?? '',
})
const sort = ref<SortState>(
  props.filters.sort ? { key: props.filters.sort, direction: props.filters.direction === 'desc' ? 'desc' : 'asc' } : null,
)
const selected = ref<Array<string | number>>([])
const perPage = ref(Number(props.filters.per_page) || props.claims.per_page)

const filterFields: FilterFieldDef[] = [
  {
    key: 'status',
    label: 'Status',
    type: 'select',
    options: [
      { label: 'Pending', value: 'pending' },
      { label: 'Approved', value: 'approved' },
      { label: 'Rejected', value: 'rejected' },
      { label: 'Paid', value: 'paid' },
    ],
  },
  {
    key: 'reimbursement_category_id',
    label: 'Category',
    type: 'select',
    options: props.categories.map((c) => ({ label: c.name, value: String(c.id) })),
  },
]

const columns = [
  { key: 'employee', label: 'Employee' },
  { key: 'category', label: 'Category' },
  { key: 'claim_date', label: 'Claim Date', sortable: true },
  { key: 'amount', label: 'Amount', sortable: true, align: 'right' as const },
  { key: 'description', label: 'Description' },
  { key: 'status', label: 'Status', sortable: true },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

const form = useForm({
  employee_id: null as number | null,
  reimbursement_category_id: null as number | null,
  claim_date: new Date().toISOString().split('T')[0],
  amount: 0,
  description: '',
})

const showModal = ref(false)

const submit = () => {
  form.post(route('payroll.reimbursements.store'), {
    onSuccess: () => {
      showModal.value = false
      form.reset()
    },
  })
}

const reviewClaim = (id: number, status: 'approved' | 'rejected') => {
  router.patch(route('payroll.reimbursements.review', id), { status })
}

watch([search, filters, sort, perPage], debounce(() => {
  selected.value = []
  router.get(
    route('payroll.reimbursements.index'),
    {
      search: search.value || undefined,
      status: filters.value.status || undefined,
      reimbursement_category_id: filters.value.reimbursement_category_id || undefined,
      sort: sort.value?.key,
      direction: sort.value?.direction,
      per_page: perPage.value,
    },
    { preserveState: true, replace: true }
  )
}, 400))
</script>

<template>
  <AppLayout title="Reimbursements">
    <PageHeader title="Reimbursements" subtitle="Employee expense claims, approvals, and payroll inclusion.">
      <template #actions>
        <PrimaryButton type="button" @click="showModal = true">+ Submit Claim</PrimaryButton>
      </template>
    </PageHeader>

    <div class="mt-4">
      <PayrollSubNav active="reimbursements" />
    </div>

    <div class="mt-6">
      <DataTable
        :columns="columns"
        :items="claims.data"
        v-model:sort="sort"
        v-model:selected="selected"
        v-model:search="search"
        v-model:filters="filters"
        v-model:per-page="perPage"
        sticky-header
        storage-key="payroll.reimbursements"
        search-placeholder="Search employee, description…"
        :filter-fields="filterFields"
        export-filename="payroll-reimbursements"
        status-rail-key="status"
        :total="claims.total"
        :from="claims.from"
        :to="claims.to"
        :links="claims.links"
        empty-title="No reimbursement claims found"
        empty-description="Submit an expense reimbursement request."
      >
        <template #cell-employee="{ item }">
          <span class="font-semibold text-ink-900">{{ (item as Claim).employee.full_name }}</span>
          <span class="block font-mono text-[11px] text-ink-400">
            {{ (item as Claim).employee.employee_no }}
          </span>
        </template>

        <template #cell-category="{ item }">
          <span class="text-xs font-medium text-ink-800">{{ (item as Claim).category.name }}</span>
        </template>

        <template #cell-claim_date="{ item }">
          <span class="font-mono text-xs text-ink-700">{{ formatDate((item as Claim).claim_date) }}</span>
        </template>

        <template #cell-amount="{ item }">
          <span class="font-mono text-xs font-semibold text-ink-900">
            {{ formatCurrency(Number((item as Claim).amount)) }}
          </span>
        </template>

        <template #cell-description="{ item }">
          <span class="text-xs text-ink-600 truncate max-w-xs block">{{ (item as Claim).description || '—' }}</span>
        </template>

        <template #cell-status="{ item }">
          <StatusBadge :status="(item as Claim).status" />
        </template>

        <template #cell-actions="{ item }">
          <div class="flex items-center justify-end gap-3">
            <template v-if="(item as Claim).status === 'pending'">
              <button
                type="button"
                class="text-xs font-semibold text-emerald-700 hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-700"
                @click="reviewClaim((item as Claim).id, 'approved')"
              >
                Approve
              </button>
              <button
                type="button"
                class="text-xs font-medium text-signal-danger hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
                @click="reviewClaim((item as Claim).id, 'rejected')"
              >
                Reject
              </button>
            </template>
            <span v-else class="text-xs text-ink-400">Reviewed</span>
          </div>
        </template>
      </DataTable>
    </div>

    <!-- Submit Claim Modal -->
    <Modal :show="showModal" max-width="md" @close="showModal = false">
      <div class="p-6 bg-surface-0 border border-border text-ink-900 rounded-lg">
        <h3 class="text-lg font-bold text-ink-900">Submit Reimbursement Claim</h3>
        <form @submit.prevent="submit" class="mt-4 space-y-4">
          <div>
            <FormSelect
              label="Employee"
              name="employee_id"
              v-model="form.employee_id"
              :options="employees.map(e => ({ label: `${e.employee_no} - ${e.full_name}`, value: e.id }))"
              placeholder="Select Employee…"
              required
            />
          </div>

          <div>
            <FormSelect
              label="Reimbursement Category"
              name="reimbursement_category_id"
              v-model="form.reimbursement_category_id"
              :options="categories.map(c => ({ label: c.name, value: c.id }))"
              placeholder="Select Category…"
              required
            />
          </div>

          <div>
            <FormInput
              label="Claim Date"
              name="claim_date"
              type="date"
              v-model="form.claim_date"
              required
            />
          </div>

          <div>
            <FormCurrencyInput
              label="Claim Amount"
              name="amount"
              v-model="form.amount"
              required
            />
          </div>

          <div>
            <FormTextarea
              label="Description (Optional)"
              name="description"
              v-model="form.description"
              placeholder="e.g. Client lunch, Travel transport receipt"
            />
          </div>

          <div class="flex justify-end gap-3 pt-2">
            <SecondaryButton type="button" @click="showModal = false">Cancel</SecondaryButton>
            <PrimaryButton type="submit" :disabled="form.processing">Submit Claim</PrimaryButton>
          </div>
        </form>
      </div>
    </Modal>
  </AppLayout>
</template>
