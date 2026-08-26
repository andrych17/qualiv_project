<!-- ponytail: Leave Management Index — leave requests, statutory types, balance tracking, and review workflows. -->
<script setup lang="ts">
import { ref, watch } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import HcmSubNav from '@/Components/hcm/HcmSubNav.vue'
import Modal from '@/Components/Modal.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormTextarea from '@/Components/forms/FormTextarea.vue'
import DataTable, { type FilterFieldDef, type SortState } from '@/Components/tables/DataTable.vue'
import { debounce } from '@/Composables/debounce'
import { useConfirm } from '@/Composables/useConfirmDialog'
import { formatDate } from '@/Utils/formatters'

interface LeaveRequest {
  id: number
  employee_id: number
  leave_type_id: number
  start_date: string
  end_date: string
  reason?: string
  status: string
  days_count: number
  employee: { id: number; employee_no: string; full_name: string }
  leave_type: { name: string }
}

interface LeaveType {
  id: number
  code: string
  name: string
  is_paid: boolean
  requires_attachment: boolean
  policies?: Array<{
    contract_type?: string
    entitlement_days_per_year: string
    carry_over_max_days: string
  }>
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
  requests: PaginatedData<LeaveRequest>
  leaveTypes: LeaveType[]
  employees: Array<{ id: number; employee_no: string; full_name: string }>
  filters: {
    search?: string
    status?: string
    leave_type_id?: string
    sort?: string
    direction?: string
    per_page?: string
  }
}>()

const search = ref(props.filters.search ?? '')
const filters = ref({
  status: props.filters.status ?? '',
  leave_type_id: props.filters.leave_type_id ?? '',
})
const sort = ref<SortState>(
  props.filters.sort ? { key: props.filters.sort, direction: props.filters.direction === 'desc' ? 'desc' : 'asc' } : null,
)
const selected = ref<Array<string | number>>([])
const perPage = ref(Number(props.filters.per_page) || props.requests.per_page)

const filterFields: FilterFieldDef[] = [
  {
    key: 'status',
    label: 'Status',
    type: 'select',
    options: [
      { label: 'Pending', value: 'pending' },
      { label: 'Approved', value: 'approved' },
      { label: 'Rejected', value: 'rejected' },
      { label: 'Cancelled', value: 'cancelled' },
    ],
  },
  {
    key: 'leave_type_id',
    label: 'Leave Type',
    type: 'select',
    options: props.leaveTypes.map((t) => ({ label: `${t.name} (${t.code})`, value: String(t.id) })),
  },
]

const columns = [
  { key: 'employee', label: 'Employee' },
  { key: 'leave_type', label: 'Leave Type' },
  { key: 'dates', label: 'Period' },
  { key: 'days_count', label: 'Days' },
  { key: 'reason', label: 'Reason' },
  { key: 'status', label: 'Status', sortable: true },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

const form = useForm({
  employee_id: null as number | null,
  leave_type_id: null as number | null,
  start_date: new Date().toISOString().split('T')[0],
  end_date: new Date().toISOString().split('T')[0],
  reason: '',
})

const showRequestModal = ref(false)

const submitRequest = () => {
  form.post(route('hcm.leave.requests.store'), {
    onSuccess: () => {
      showRequestModal.value = false
      form.reset()
    },
  })
}

const reviewRequest = (id: number, status: 'approved' | 'rejected') => {
  router.patch(route('hcm.leave.requests.review', id), { status })
}

const { confirm } = useConfirm()

const cancelRequest = (id: number) => {
  confirm({
    title: 'Cancel Leave Request?',
    description: 'Are you sure you want to cancel this leave request?',
    variant: 'destructive',
    confirmText: 'Cancel Request',
    onConfirm: () => router.post(route('hcm.leave.requests.cancel', id)),
  })
}

watch([search, filters, sort, perPage], debounce(() => {
  selected.value = []
  router.get(
    route('hcm.leave.index'),
    {
      search: search.value || undefined,
      status: filters.value.status || undefined,
      leave_type_id: filters.value.leave_type_id || undefined,
      sort: sort.value?.key,
      direction: sort.value?.direction,
      per_page: perPage.value,
    },
    { preserveState: true, replace: true }
  )
}, 400))
</script>

<template>
  <AppLayout title="Leave Management">
    <PageHeader title="Leave Management" subtitle="Manage employee leave requests, statutory leave types, and entitlements.">
      <template #actions>
        <PrimaryButton type="button" @click="showRequestModal = true">+ Submit Leave Request</PrimaryButton>
      </template>
    </PageHeader>

    <div class="mt-4">
      <HcmSubNav active="leave" />
    </div>

    <!-- Statutory Types Overview Cards -->
    <div class="mt-6">
      <Panel title="Indonesian Statutory & Custom Leave Types">
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <div
            v-for="t in leaveTypes"
            :key="t.id"
            class="rounded-lg border border-border p-3 bg-surface-50"
          >
            <div class="font-bold text-sm text-ink-900">{{ t.name }} ({{ t.code }})</div>
            <div class="text-xs text-ink-500 mt-1">
              {{ t.is_paid ? 'Paid Leave' : 'Unpaid Leave' }} &bull;
              {{ t.requires_attachment ? 'Doctor note required' : 'No attachment required' }}
            </div>
            <div v-if="t.policies && t.policies.length > 0" class="text-xs text-ink-700 mt-2 font-medium">
              Entitlement: {{ t.policies[0].entitlement_days_per_year }} days/yr (Carryover max: {{ t.policies[0].carry_over_max_days }}d)
            </div>
          </div>
        </div>
      </Panel>
    </div>

    <!-- Requests Table -->
    <div class="mt-6">
      <DataTable
        :columns="columns"
        :items="requests.data"
        v-model:sort="sort"
        v-model:selected="selected"
        v-model:search="search"
        v-model:filters="filters"
        v-model:per-page="perPage"
        sticky-header
        storage-key="hcm.leave"
        search-placeholder="Search employee…"
        :filter-fields="filterFields"
        export-filename="hcm-leave-requests"
        status-rail-key="status"
        :total="requests.total"
        :from="requests.from"
        :to="requests.to"
        :links="requests.links"
        empty-title="No leave requests found"
        empty-description="Submit a leave request for employee approval."
      >
        <template #cell-employee="{ item }">
          <span class="font-semibold text-ink-900">{{ (item as LeaveRequest).employee.full_name }}</span>
          <span class="block font-mono text-[11px] text-ink-400">
            {{ (item as LeaveRequest).employee.employee_no }}
          </span>
        </template>

        <template #cell-leave_type="{ item }">
          <span class="text-xs font-medium text-ink-800">{{ (item as LeaveRequest).leave_type.name }}</span>
        </template>

        <template #cell-dates="{ item }">
          <span class="font-mono text-xs text-ink-700">
            {{ formatDate((item as LeaveRequest).start_date) }} - {{ formatDate((item as LeaveRequest).end_date) }}
          </span>
        </template>

        <template #cell-days_count="{ item }">
          <span class="font-mono text-xs font-semibold text-ink-900">{{ (item as LeaveRequest).days_count }} days</span>
        </template>

        <template #cell-reason="{ item }">
          <span class="text-xs text-ink-600 truncate max-w-xs block">{{ (item as LeaveRequest).reason || '—' }}</span>
        </template>

        <template #cell-status="{ item }">
          <StatusBadge :status="(item as LeaveRequest).status" />
        </template>

        <template #cell-actions="{ item }">
          <div class="flex items-center justify-end gap-3">
            <template v-if="(item as LeaveRequest).status === 'pending'">
              <button
                type="button"
                class="text-xs font-semibold text-emerald-700 hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-700"
                @click="reviewRequest((item as LeaveRequest).id, 'approved')"
              >
                Approve
              </button>
              <button
                type="button"
                class="text-xs font-medium text-signal-danger hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
                @click="reviewRequest((item as LeaveRequest).id, 'rejected')"
              >
                Reject
              </button>
            </template>
            <button
              v-if="(item as LeaveRequest).status === 'approved'"
              type="button"
              class="text-xs font-medium text-ink-500 hover:text-ink-900 hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
              @click="cancelRequest((item as LeaveRequest).id)"
            >
              Cancel
            </button>
          </div>
        </template>
      </DataTable>
    </div>

    <!-- Request Modal -->
    <Modal :show="showRequestModal" max-width="md" @close="showRequestModal = false">
      <div class="p-6 bg-white rounded-lg">
        <h3 class="text-lg font-bold text-ink-900">Submit Leave Request</h3>
        <form @submit.prevent="submitRequest" class="mt-4 space-y-4">
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
              label="Leave Type"
              name="leave_type_id"
              v-model="form.leave_type_id"
              :options="leaveTypes.map(t => ({ label: `${t.name} (${t.code})`, value: t.id }))"
              placeholder="Select Leave Type…"
              required
            />
          </div>

          <div class="grid grid-cols-2 gap-4">
            <div>
              <FormInput
                label="Start Date"
                name="start_date"
                type="date"
                v-model="form.start_date"
                required
              />
            </div>
            <div>
              <FormInput
                label="End Date"
                name="end_date"
                type="date"
                v-model="form.end_date"
                required
              />
            </div>
          </div>

          <div>
            <FormTextarea
              label="Reason (Optional)"
              name="reason"
              v-model="form.reason"
              placeholder="Provide reason or context for leave…"
            />
          </div>

          <div class="flex justify-end gap-3 pt-2">
            <SecondaryButton type="button" @click="showRequestModal = false">Cancel</SecondaryButton>
            <PrimaryButton type="submit" :disabled="form.processing">Submit Request</PrimaryButton>
          </div>
        </form>
      </div>
    </Modal>
  </AppLayout>
</template>
