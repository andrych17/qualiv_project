<!-- ponytail: Time & Attendance Index — daily logs, exceptions, clock widget, and corrections. -->
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
import FormSelect from '@/Components/forms/FormSelect.vue'
import DataTable, { type FilterFieldDef, type SortState } from '@/Components/tables/DataTable.vue'
import { debounce } from '@/Composables/debounce'
import { formatDateTime } from '@/Utils/formatters'

interface Log {
  id: number
  employee_id: number
  clock_in_at?: string
  clock_out_at?: string
  source: string
  exception_flag: string
  created_at: string
  employee: { id: number; employee_no: string; full_name: string }
}

interface Correction {
  id: number
  employee: { full_name: string }
  requested_clock_in_at?: string
  requested_clock_out_at?: string
  reason: string
  status: string
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
  logs: PaginatedData<Log>
  corrections: { data: Correction[] }
  shifts: Array<{ id: number; name: string }>
  employees: Array<{ id: number; employee_no: string; full_name: string }>
  filters: {
    search?: string
    exception_flag?: string
    date?: string
    sort?: string
    direction?: string
    per_page?: string
  }
}>()

const search = ref(props.filters.search ?? '')
const filters = ref({
  exception_flag: props.filters.exception_flag ?? '',
  date: props.filters.date ?? '',
})
const sort = ref<SortState>(
  props.filters.sort ? { key: props.filters.sort, direction: props.filters.direction === 'desc' ? 'desc' : 'asc' } : null,
)
const selected = ref<Array<string | number>>([])
const perPage = ref(Number(props.filters.per_page) || props.logs.per_page)

const filterFields: FilterFieldDef[] = [
  {
    key: 'exception_flag',
    label: 'Status / Exception',
    type: 'select',
    options: [
      { label: 'On Time', value: 'on_time' },
      { label: 'Late', value: 'late' },
      { label: 'Early Leave', value: 'early_leave' },
      { label: 'Absent', value: 'absent' },
    ],
  },
  {
    key: 'date',
    label: 'Date',
    type: 'date',
  },
]

const columns = [
  { key: 'employee', label: 'Employee' },
  { key: 'clock_in_at', label: 'Clock In', sortable: true },
  { key: 'clock_out_at', label: 'Clock Out', sortable: true },
  { key: 'source', label: 'Source' },
  { key: 'exception_flag', label: 'Status / Exception', sortable: true },
]

const clockForm = useForm({
  employee_id: null as number | null,
})

const submitClockIn = () => {
  if (!clockForm.employee_id) return
  clockForm.post(route('hcm.attendance.clockIn'))
}

const submitClockOut = () => {
  if (!clockForm.employee_id) return
  clockForm.post(route('hcm.attendance.clockOut'))
}

const reviewCorrection = (id: number, status: 'approved' | 'rejected') => {
  router.patch(route('hcm.attendance.corrections.review', id), { status })
}

watch([search, filters, sort, perPage], debounce(() => {
  selected.value = []
  router.get(
    route('hcm.attendance.index'),
    {
      search: search.value || undefined,
      exception_flag: filters.value.exception_flag || undefined,
      date: filters.value.date || undefined,
      sort: sort.value?.key,
      direction: sort.value?.direction,
      per_page: perPage.value,
    },
    { preserveState: true, replace: true }
  )
}, 400))
</script>

<template>
  <AppLayout title="Time & Attendance">
    <PageHeader title="Time & Attendance" subtitle="Daily clock-in/out logs, shift tracking, and exception management." />

    <div class="mt-4">
      <HcmSubNav active="attendance" />
    </div>

    <!-- Quick Clock In / Out Action Widget -->
    <div class="mt-6">
      <Panel title="Web Clock In / Out">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-end">
          <div class="flex-1">
            <FormSelect
              label="Select Employee"
              name="employee_id"
              v-model="clockForm.employee_id"
              :options="employees.map(e => ({ label: `${e.employee_no} - ${e.full_name}`, value: e.id }))"
              placeholder="Select Employee to Record Time…"
            />
          </div>
          <div class="flex items-center gap-3">
            <PrimaryButton
              type="button"
              :disabled="!clockForm.employee_id || clockForm.processing"
              @click="submitClockIn"
            >
              Clock In
            </PrimaryButton>
            <SecondaryButton
              type="button"
              :disabled="!clockForm.employee_id || clockForm.processing"
              @click="submitClockOut"
            >
              Clock Out
            </SecondaryButton>
          </div>
        </div>
      </Panel>
    </div>

    <!-- Pending Corrections Review -->
    <div v-if="corrections.data.length > 0" class="mt-6">
      <Panel title="Pending Attendance Correction Requests">
        <div class="divide-y divide-border">
          <div
            v-for="c in corrections.data"
            :key="c.id"
            class="flex items-center justify-between p-3"
          >
            <div>
              <div class="font-medium text-ink-900">{{ c.employee.full_name }}</div>
              <div class="text-xs text-ink-500">
                Requested: In {{ c.requested_clock_in_at || '-' }} / Out {{ c.requested_clock_out_at || '-' }} &bull; Reason: {{ c.reason }}
              </div>
            </div>
            <div class="flex items-center gap-2">
              <button
                type="button"
                class="rounded bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700 hover:bg-emerald-100"
                @click="reviewCorrection(c.id, 'approved')"
              >
                Approve
              </button>
              <button
                type="button"
                class="rounded bg-rose-50 px-2.5 py-1 text-xs font-semibold text-rose-700 hover:bg-rose-100"
                @click="reviewCorrection(c.id, 'rejected')"
              >
                Reject
              </button>
            </div>
          </div>
        </div>
      </Panel>
    </div>

    <!-- Logs Table -->
    <div class="mt-6">
      <DataTable
        :columns="columns"
        :items="logs.data"
        v-model:sort="sort"
        v-model:selected="selected"
        v-model:search="search"
        v-model:filters="filters"
        v-model:per-page="perPage"
        sticky-header
        storage-key="hcm.attendance"
        search-placeholder="Search employee…"
        :filter-fields="filterFields"
        export-filename="hcm-attendance-logs"
        status-rail-key="exception_flag"
        :total="logs.total"
        :from="logs.from"
        :to="logs.to"
        :links="logs.links"
        empty-title="No attendance records found"
        empty-description="Clock in/out logs will be recorded here automatically."
      >
        <template #cell-employee="{ item }">
          <span class="font-semibold text-ink-900">{{ (item as Log).employee.full_name }}</span>
          <span class="block font-mono text-[11px] text-ink-400">
            {{ (item as Log).employee.employee_no }}
          </span>
        </template>

        <template #cell-clock_in_at="{ item }">
          <span v-if="(item as Log).clock_in_at" class="font-mono text-xs text-ink-700">
            {{ formatDateTime((item as Log).clock_in_at!) }}
          </span>
          <span v-else class="text-xs text-ink-400">—</span>
        </template>

        <template #cell-clock_out_at="{ item }">
          <span v-if="(item as Log).clock_out_at" class="font-mono text-xs text-ink-700">
            {{ formatDateTime((item as Log).clock_out_at!) }}
          </span>
          <span v-else class="text-xs text-ink-400">—</span>
        </template>

        <template #cell-source="{ item }">
          <span class="text-xs capitalize text-ink-600">{{ (item as Log).source }}</span>
        </template>

        <template #cell-exception_flag="{ item }">
          <StatusBadge :status="(item as Log).exception_flag" />
        </template>
      </DataTable>
    </div>
  </AppLayout>
</template>
