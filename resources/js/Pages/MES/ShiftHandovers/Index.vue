<!-- ponytail: Shift Reference & Handover (MES_SPECS.md §3P) — create-only, no edit/delete
     (a handover note is a point-in-time record). No MES-owned shift model; shift_assignment_id
     picks from HCM's own shifts/shift_assignments. -->
<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3'
import { ref, watch } from 'vue'
import { debounce } from '@/Composables/debounce'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import DataTable, { type SortState } from '@/Components/tables/DataTable.vue'
import Modal from '@/Components/Modal.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import FormTextarea from '@/Components/forms/FormTextarea.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'

interface OrderSummary {
  active_orders: Array<{ order_number: string; status: string }>
  open_qc_hold_count: number
  open_downtime_count: number
}

interface HandoverRow {
  id: number
  employee_name: string | null
  shift_name: string | null
  work_date: string | null
  notes: string | null
  order_summary: OrderSummary | null
  created_by_name: string | null
  created_at: string | null
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
  notes: PaginatedData<HandoverRow>
  filters: { work_date?: string; sort?: string; direction?: string; per_page?: string }
  shiftAssignments: Array<{ value: number; label: string }>
}>()

const sort = ref<SortState>(
  props.filters.sort ? { key: props.filters.sort, direction: props.filters.direction === 'desc' ? 'desc' : 'asc' } : null,
)

const columns = [
  { key: 'created_at', label: 'Recorded At', sortable: true },
  { key: 'work_date', label: 'Shift Date' },
  { key: 'shift_name', label: 'Shift' },
  { key: 'employee_name', label: 'Employee' },
  { key: 'order_summary', label: 'Snapshot' },
  { key: 'notes', label: 'Notes' },
  { key: 'created_by_name', label: 'Recorded By' },
]

watch(sort, debounce(() => {
  router.get(route('mes.shiftHandovers.index'), { sort: sort.value?.key, direction: sort.value?.direction }, { preserveState: true, replace: true })
}, 400))

const showCreateModal = ref(false)
const form = useForm({
  shift_assignment_id: null as number | null,
  notes: '',
})

const submit = () => {
  form.post(route('mes.shiftHandovers.store'), {
    onSuccess: () => {
      showCreateModal.value = false
      form.reset()
    },
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader title="Shift Handover" description="Point-in-time notes at shift change — machine issues, last QC result, what's running (MES_SPECS.md §3P). No MES-owned shift model; scoped to an existing HCM shift assignment.">
      <template #actions>
        <PrimaryButton @click="showCreateModal = true">Record Handover</PrimaryButton>
      </template>
    </PageHeader>

    <div class="mt-6 space-y-4">
      <DataTable
        :columns="columns"
        :items="notes.data"
        v-model:sort="sort"
        sticky-header
        storage-key="mes.shiftHandovers"
        export-filename="mes-shift-handovers"
        :total="notes.total"
        :from="notes.from"
        :to="notes.to"
        :links="notes.links"
        empty-title="No handover notes yet"
        empty-description="Record a handover note when a shift ends to hand context to the next one."
      >
        <template #cell-order_summary="{ item }">
          <div class="text-xs text-ink-600">
            <span>{{ (item as HandoverRow).order_summary?.active_orders.length ?? 0 }} active order(s)</span>
            <span class="mx-1">·</span>
            <span>{{ (item as HandoverRow).order_summary?.open_qc_hold_count ?? 0 }} QC hold(s)</span>
            <span class="mx-1">·</span>
            <span>{{ (item as HandoverRow).order_summary?.open_downtime_count ?? 0 }} downtime</span>
          </div>
        </template>
        <template #cell-notes="{ item }">
          <span class="text-xs text-ink-700">{{ (item as HandoverRow).notes || '—' }}</span>
        </template>
      </DataTable>
    </div>

    <Modal :show="showCreateModal" max-width="lg" @close="showCreateModal = false">
      <div class="p-6">
        <h3 class="text-lg font-bold text-ink-900">Record Handover</h3>
        <p class="mt-1 text-sm text-ink-600">Order/batch summary (active orders, open QC holds, open downtime) is captured automatically at the moment you submit.</p>

        <form class="mt-4 space-y-4" @submit.prevent="submit">
          <FormSelect v-model="form.shift_assignment_id" name="shift_assignment_id" label="Shift Assignment" :options="shiftAssignments" :error="form.errors.shift_assignment_id" required />
          <FormTextarea v-model="form.notes" name="notes" label="Notes" placeholder="e.g. machine issue, last QC result, anything the next shift should know" :error="form.errors.notes" />

          <div class="mt-6 flex justify-end gap-3">
            <SecondaryButton @click="showCreateModal = false">Cancel</SecondaryButton>
            <PrimaryButton type="submit" :disabled="form.processing">Record</PrimaryButton>
          </div>
        </form>
      </div>
    </Modal>
  </AppLayout>
</template>
