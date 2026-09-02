<!-- ponytail: Equipment Status & Downtime (MES_SPECS.md §3M) — start/end only, no edit/delete
     (append-only, same posture as the Production Event Ledger this feeds). -->
<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3'
import { ref, watch } from 'vue'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import StatCard from '@/Components/cards/StatCard.vue'
import DataTable, { type FilterFieldDef, type SortState } from '@/Components/tables/DataTable.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import Modal from '@/Components/Modal.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import { debounce } from '@/Composables/debounce'

interface DowntimeRow {
  id: number
  machine_code: string | null
  work_center_code: string | null
  order_number: string | null
  category: string
  reason_code: string
  started_at: string | null
  ended_at: string | null
  duration_minutes: number | null
  is_open: boolean
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
  events: PaginatedData<DowntimeRow>
  filters: { status?: string; category?: string; machine_id?: string; work_center_id?: string; sort?: string; direction?: string; per_page?: string }
  openCount: number
  machines: Array<{ value: number; label: string }>
  workCenters: Array<{ value: number; label: string }>
}>()

const filters = ref({
  status: props.filters.status ?? '',
  category: props.filters.category ?? '',
  machine_id: props.filters.machine_id ?? '',
  work_center_id: props.filters.work_center_id ?? '',
})
const sort = ref<SortState>(
  props.filters.sort ? { key: props.filters.sort, direction: props.filters.direction === 'desc' ? 'desc' : 'asc' } : null,
)
const perPage = ref(Number(props.filters.per_page) || props.events.per_page)

const filterFields: FilterFieldDef[] = [
  { key: 'status', label: 'Status', type: 'select', options: [{ label: 'Open', value: 'open' }, { label: 'Closed', value: 'closed' }] },
  { key: 'category', label: 'Category', type: 'select', options: [{ label: 'Planned', value: 'planned' }, { label: 'Unplanned', value: 'unplanned' }] },
  { key: 'machine_id', label: 'Machine', type: 'select', options: props.machines.map((m) => ({ label: m.label, value: String(m.value) })) },
  { key: 'work_center_id', label: 'Work Center', type: 'select', options: props.workCenters.map((w) => ({ label: w.label, value: String(w.value) })) },
]

const columns = [
  { key: 'started_at', label: 'Started', sortable: true },
  { key: 'ended_at', label: 'Ended', sortable: true },
  { key: 'machine_code', label: 'Machine / Work Center' },
  { key: 'order_number', label: 'Order' },
  { key: 'category', label: 'Category', sortable: true },
  { key: 'reason_code', label: 'Reason' },
  { key: 'duration_minutes', label: 'Duration (min)' },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

watch([filters, sort, perPage], debounce(() => {
  router.get(route('mes.downtimeEvents.index'), {
    status: filters.value.status,
    category: filters.value.category,
    machine_id: filters.value.machine_id,
    work_center_id: filters.value.work_center_id,
    sort: sort.value?.key,
    direction: sort.value?.direction,
    per_page: perPage.value,
  }, { preserveState: true, replace: true })
}, 400))

const plannedReasons = [
  { value: 'maintenance', label: 'Maintenance' },
  { value: 'setup', label: 'Setup' },
]
const unplannedReasons = [
  { value: 'mechanical', label: 'Mechanical' },
  { value: 'electrical', label: 'Electrical' },
  { value: 'material_shortage', label: 'Material Shortage' },
  { value: 'quality', label: 'Quality' },
  { value: 'operator', label: 'Operator' },
]

const showStartModal = ref(false)
const startForm = useForm({
  machine_id: null as number | null,
  work_center_id: null as number | null,
  order_id: null as number | null,
  category: 'unplanned',
  reason_code: '',
})

watch(() => startForm.category, () => { startForm.reason_code = '' })

const submitStart = () => {
  startForm.post(route('mes.downtimeEvents.store'), {
    onSuccess: () => {
      showStartModal.value = false
      startForm.reset()
    },
  })
}

const endDowntime = (row: DowntimeRow) => {
  router.post(route('mes.downtimeEvents.end', row.id))
}

const formatReason = (code: string) => code.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase())
</script>

<template>
  <AppLayout>
    <PageHeader title="Equipment Downtime" description="Planned/unplanned downtime logged against a machine or work center (MES_SPECS.md §3M). Unplanned downtime past the configured threshold auto-notifies the maintenance contact role.">
      <template #actions>
        <PrimaryButton @click="showStartModal = true">Log Downtime</PrimaryButton>
      </template>
    </PageHeader>

    <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
      <StatCard title="Open Downtime" :value="String(openCount)" icon="AlertOctagon" />
      <StatCard title="This Page" :value="String(events.total)" icon="ListFilter" />
      <StatCard title="Threshold Sweep" value="Every 5 min" description="mes:check-downtime-thresholds" icon="Clock" />
    </div>

    <div class="mt-6 space-y-4">
      <DataTable
        :columns="columns"
        :items="events.data"
        v-model:sort="sort"
        v-model:filters="filters"
        v-model:per-page="perPage"
        sticky-header
        storage-key="mes.downtimeEvents"
        :filter-fields="filterFields"
        export-filename="mes-downtime-events"
        :total="events.total"
        :from="events.from"
        :to="events.to"
        :links="events.links"
        empty-title="No downtime logged yet"
        empty-description="Log downtime against a machine or work center to start tracking equipment availability."
      >
        <template #cell-machine_code="{ item }">
          <span class="text-xs text-ink-700">{{ (item as DowntimeRow).machine_code ?? (item as DowntimeRow).work_center_code ?? '—' }}</span>
        </template>
        <template #cell-category="{ item }">
          <StatusBadge :status="(item as DowntimeRow).category" />
        </template>
        <template #cell-reason_code="{ item }">
          <span class="text-xs text-ink-700">{{ formatReason((item as DowntimeRow).reason_code) }}</span>
        </template>
        <template #cell-duration_minutes="{ item }">
          <span class="tabular-nums text-xs" :class="(item as DowntimeRow).is_open ? 'font-semibold text-signal-warning' : 'text-ink-700'">
            {{ (item as DowntimeRow).duration_minutes ?? '—' }}{{ (item as DowntimeRow).is_open ? ' (open)' : '' }}
          </span>
        </template>
        <template #cell-actions="{ item }">
          <button
            v-if="(item as DowntimeRow).is_open"
            type="button"
            class="text-sm font-medium text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
            @click="endDowntime(item as DowntimeRow)"
          >
            End
          </button>
        </template>
      </DataTable>
    </div>

    <Modal :show="showStartModal" max-width="lg" @close="showStartModal = false">
      <div class="p-6">
        <h3 class="text-lg font-bold text-ink-900">Log Downtime</h3>
        <p class="mt-1 text-sm text-ink-600">Scope to a machine or a work center — at least one is required.</p>

        <form class="mt-4 space-y-4" @submit.prevent="submitStart">
          <FormSelect v-model="startForm.machine_id" name="machine_id" label="Machine" :options="machines" :error="startForm.errors.machine_id" />
          <FormSelect v-model="startForm.work_center_id" name="work_center_id" label="Work Center (if no single machine is the cause)" :options="workCenters" :error="startForm.errors.work_center_id" />

          <FormSelect
            v-model="startForm.category"
            name="category"
            label="Category"
            :options="[{ value: 'planned', label: 'Planned' }, { value: 'unplanned', label: 'Unplanned' }]"
            :error="startForm.errors.category"
            required
          />

          <FormSelect
            v-model="startForm.reason_code"
            name="reason_code"
            label="Reason"
            :options="startForm.category === 'planned' ? plannedReasons : unplannedReasons"
            :error="startForm.errors.reason_code"
            required
          />

          <div class="mt-6 flex justify-end gap-3">
            <SecondaryButton @click="showStartModal = false">Cancel</SecondaryButton>
            <PrimaryButton type="submit" :disabled="startForm.processing">Start</PrimaryButton>
          </div>
        </form>
      </div>
    </Modal>
  </AppLayout>
</template>
