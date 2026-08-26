<!-- ponytail: Shift Schedule Master — shift hours, break times, and active configuration. -->
<script setup lang="ts">
import { ref, watch } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import HcmSubNav from '@/Components/hcm/HcmSubNav.vue'
import Modal from '@/Components/Modal.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormNumberInput from '@/Components/forms/FormNumberInput.vue'
import FormSwitch from '@/Components/forms/FormSwitch.vue'
import DataTable, { type FilterFieldDef, type SortState } from '@/Components/tables/DataTable.vue'
import { debounce } from '@/Composables/debounce'
import { useConfirm } from '@/Composables/useConfirmDialog'

interface Shift {
  id: number
  name: string
  start_time: string
  end_time: string
  break_minutes: number
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
  shifts: PaginatedData<Shift>
  filters?: {
    search?: string
    is_active?: string
    sort?: string
    direction?: string
    per_page?: string
  }
}>()

const search = ref(props.filters?.search ?? '')
const filters = ref({
  is_active: props.filters?.is_active ?? '',
})
const sort = ref<SortState>(
  props.filters?.sort ? { key: props.filters.sort, direction: props.filters.direction === 'desc' ? 'desc' : 'asc' } : null,
)
const selected = ref<Array<string | number>>([])
const perPage = ref(Number(props.filters?.per_page) || props.shifts.per_page)

const filterFields: FilterFieldDef[] = [
  {
    key: 'is_active',
    label: 'Status',
    type: 'select',
    options: [
      { label: 'Active', value: '1' },
      { label: 'Inactive', value: '0' },
    ],
  },
]

const columns = [
  { key: 'name', label: 'Shift Name' },
  { key: 'start_time', label: 'Start Time' },
  { key: 'end_time', label: 'End Time' },
  { key: 'break_minutes', label: 'Break (Mins)' },
  { key: 'status', label: 'Status' },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

const form = useForm({
  id: null as number | null,
  name: '',
  start_time: '09:00',
  end_time: '17:00',
  break_minutes: 60,
  is_active: true,
})

const showModal = ref(false)
const isEditing = ref(false)

const openCreate = () => {
  form.reset()
  form.id = null
  isEditing.value = false
  showModal.value = true
}

const openEdit = (shift: Shift) => {
  form.id = shift.id
  form.name = shift.name
  form.start_time = shift.start_time.substring(0, 5)
  form.end_time = shift.end_time.substring(0, 5)
  form.break_minutes = shift.break_minutes
  form.is_active = Boolean(shift.is_active)
  isEditing.value = true
  showModal.value = true
}

const submit = () => {
  if (isEditing.value && form.id) {
    form.put(route('hcm.shifts.update', form.id), {
      onSuccess: () => {
        showModal.value = false
      },
    })
  } else {
    form.post(route('hcm.shifts.store'), {
      onSuccess: () => {
        showModal.value = false
      },
    })
  }
}

const { confirm } = useConfirm()
const deleteShift = (shift: Shift) => {
  confirm({
    title: `Delete Shift "${shift.name}"?`,
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () => router.delete(route('hcm.shifts.destroy', shift.id)),
  })
}

watch([search, filters, sort, perPage], debounce(() => {
  selected.value = []
  router.get(
    route('hcm.shifts.index'),
    {
      search: search.value || undefined,
      is_active: filters.value.is_active || undefined,
      sort: sort.value?.key,
      direction: sort.value?.direction,
      per_page: perPage.value,
    },
    { preserveState: true, replace: true }
  )
}, 400))
</script>

<template>
  <AppLayout title="Shifts Master">
    <PageHeader title="Shifts" subtitle="Configure work schedules, start/end hours, and break rules.">
      <template #actions>
        <PrimaryButton type="button" @click="openCreate">+ Add Shift</PrimaryButton>
      </template>
    </PageHeader>

    <div class="mt-4">
      <HcmSubNav active="shifts" />
    </div>

    <div class="mt-6">
      <DataTable
        :columns="columns"
        :items="shifts.data"
        v-model:sort="sort"
        v-model:selected="selected"
        v-model:search="search"
        v-model:filters="filters"
        v-model:per-page="perPage"
        sticky-header
        storage-key="hcm.shifts"
        search-placeholder="Search shifts…"
        :filter-fields="filterFields"
        export-filename="hcm-shifts"
        status-rail-key="is_active"
        :total="shifts.total"
        :from="shifts.from"
        :to="shifts.to"
        :links="shifts.links"
        empty-title="No shifts configured"
        empty-description="Define shift work schedules and break durations."
      >
        <template #cell-name="{ item }">
          <span class="font-semibold text-ink-900">{{ (item as Shift).name }}</span>
        </template>

        <template #cell-start_time="{ item }">
          <span class="font-mono text-xs text-ink-700">{{ (item as Shift).start_time }}</span>
        </template>

        <template #cell-end_time="{ item }">
          <span class="font-mono text-xs text-ink-700">{{ (item as Shift).end_time }}</span>
        </template>

        <template #cell-break_minutes="{ item }">
          <span class="font-mono text-xs text-ink-600">{{ (item as Shift).break_minutes }} min</span>
        </template>

        <template #cell-status="{ item }">
          <StatusBadge :status="(item as Shift).is_active ? 'active' : 'inactive'" />
        </template>

        <template #cell-actions="{ item }">
          <div class="flex items-center justify-end gap-3">
            <button
              type="button"
              class="text-xs font-semibold text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
              @click="openEdit(item as Shift)"
            >
              Edit
            </button>
            <button
              type="button"
              class="text-xs font-medium text-signal-danger hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
              @click="deleteShift(item as Shift)"
            >
              Delete
            </button>
          </div>
        </template>
      </DataTable>
    </div>

    <!-- Create/Edit Modal -->
    <Modal :show="showModal" max-width="md" @close="showModal = false">
      <div class="p-6 bg-white rounded-lg">
        <h3 class="text-lg font-bold text-ink-900">{{ isEditing ? 'Edit Shift' : 'New Shift Schedule' }}</h3>

        <form @submit.prevent="submit" class="mt-4 space-y-4">
          <div>
            <FormInput
              label="Shift Name"
              name="name"
              v-model="form.name"
              :error="form.errors.name"
              placeholder="e.g. Regular Day Shift, Evening Shift"
              required
            />
          </div>

          <div class="grid grid-cols-2 gap-4">
            <div>
              <FormInput
                label="Start Time"
                name="start_time"
                type="time"
                v-model="form.start_time"
                :error="form.errors.start_time"
                required
              />
            </div>
            <div>
              <FormInput
                label="End Time"
                name="end_time"
                type="time"
                v-model="form.end_time"
                :error="form.errors.end_time"
                required
              />
            </div>
          </div>

          <div>
            <FormNumberInput
              label="Break Duration (Minutes)"
              name="break_minutes"
              v-model="form.break_minutes"
              :error="form.errors.break_minutes"
              :min="0"
              suffix="mins"
              required
            />
          </div>

          <div>
            <FormSwitch
              v-model="form.is_active"
              name="is_active"
              label="Shift is Active"
              description="Allow employees to be scheduled in this shift."
            />
          </div>

          <div class="flex justify-end gap-3 pt-2">
            <SecondaryButton type="button" @click="showModal = false">Cancel</SecondaryButton>
            <PrimaryButton type="submit" :disabled="form.processing">
              {{ isEditing ? 'Save Changes' : 'Create Shift' }}
            </PrimaryButton>
          </div>
        </form>
      </div>
    </Modal>
  </AppLayout>
</template>
