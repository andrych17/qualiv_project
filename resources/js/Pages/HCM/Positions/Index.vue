<!-- ponytail: Positions management — seat definitions, reporting lines, and org mapping. -->
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
import FormSelect from '@/Components/forms/FormSelect.vue'
import FormNumberInput from '@/Components/forms/FormNumberInput.vue'
import FormSwitch from '@/Components/forms/FormSwitch.vue'
import DataTable, { type FilterFieldDef, type SortState } from '@/Components/tables/DataTable.vue'
import { debounce } from '@/Composables/debounce'
import { useConfirm } from '@/Composables/useConfirmDialog'

interface Position {
  id: number
  job_id: number
  org_unit_id: number
  reports_to_position_id?: number
  headcount_cap?: number
  is_active: boolean
  job?: { code: string; title: string }
  org_unit?: { name: string }
  reports_to?: { job?: { title: string } }
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
  positions: PaginatedData<Position>
  jobs: Array<{ id: number; title: string }>
  orgUnits: Array<{ id: number; name: string }>
  allPositions: Position[]
  filters: {
    search?: string
    org_unit_id?: string
    is_active?: string
    sort?: string
    direction?: string
    per_page?: string
  }
}>()

const search = ref(props.filters.search ?? '')
const filters = ref({
  org_unit_id: props.filters.org_unit_id ?? '',
  is_active: props.filters.is_active ?? '',
})
const sort = ref<SortState>(
  props.filters.sort ? { key: props.filters.sort, direction: props.filters.direction === 'desc' ? 'desc' : 'asc' } : null,
)
const selected = ref<Array<string | number>>([])
const perPage = ref(Number(props.filters.per_page) || props.positions.per_page)

const filterFields: FilterFieldDef[] = [
  {
    key: 'org_unit_id',
    label: 'Org Unit',
    type: 'select',
    options: props.orgUnits.map((u) => ({ label: u.name, value: String(u.id) })),
  },
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
  { key: 'job_role', label: 'Job Role' },
  { key: 'org_unit', label: 'Org Unit' },
  { key: 'reports_to', label: 'Reports To' },
  { key: 'headcount_cap', label: 'Headcount Cap', sortable: true },
  { key: 'status', label: 'Status', sortable: true },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

const form = useForm({
  id: null as number | null,
  job_id: null as number | null,
  org_unit_id: null as number | null,
  reports_to_position_id: null as number | null,
  headcount_cap: 1,
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

const openEdit = (pos: Position) => {
  form.id = pos.id
  form.job_id = pos.job_id
  form.org_unit_id = pos.org_unit_id
  form.reports_to_position_id = pos.reports_to_position_id || null
  form.headcount_cap = pos.headcount_cap || 1
  form.is_active = Boolean(pos.is_active)
  isEditing.value = true
  showModal.value = true
}

const submit = () => {
  if (isEditing.value && form.id) {
    form.put(route('hcm.positions.update', form.id), {
      onSuccess: () => {
        showModal.value = false
      },
    })
  } else {
    form.post(route('hcm.positions.store'), {
      onSuccess: () => {
        showModal.value = false
      },
    })
  }
}

const { confirm } = useConfirm()
const deletePos = (pos: Position) => {
  confirm({
    title: `Delete Position "${pos.job?.title}" in ${pos.org_unit?.name}?`,
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () => router.delete(route('hcm.positions.destroy', pos.id)),
  })
}

watch([search, filters, sort, perPage], debounce(() => {
  selected.value = []
  router.get(
    route('hcm.positions.index'),
    {
      search: search.value || undefined,
      org_unit_id: filters.value.org_unit_id || undefined,
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
  <AppLayout title="Positions">
    <PageHeader title="Positions" subtitle="Manage role seats, reporting hierarchy, and department mapping.">
      <template #actions>
        <PrimaryButton type="button" @click="openCreate">+ Add Position</PrimaryButton>
      </template>
    </PageHeader>

    <div class="mt-4">
      <HcmSubNav active="positions" />
    </div>

    <div class="mt-6">
      <DataTable
        :columns="columns"
        :items="positions.data"
        v-model:sort="sort"
        v-model:selected="selected"
        v-model:search="search"
        v-model:filters="filters"
        v-model:per-page="perPage"
        sticky-header
        storage-key="hcm.positions"
        search-placeholder="Search positions…"
        :filter-fields="filterFields"
        export-filename="hcm-positions"
        status-rail-key="is_active"
        :total="positions.total"
        :from="positions.from"
        :to="positions.to"
        :links="positions.links"
        empty-title="No positions found"
        empty-description="Define job positions and assign reporting seats."
      >
        <template #cell-job_role="{ item }">
          <span class="font-semibold text-ink-900">{{ (item as Position).job?.title }}</span>
          <span v-if="(item as Position).job?.code" class="block font-mono text-[11px] text-ink-400">
            {{ (item as Position).job?.code }}
          </span>
        </template>

        <template #cell-org_unit="{ item }">
          <span class="text-xs text-ink-700">{{ (item as Position).org_unit?.name ?? '—' }}</span>
        </template>

        <template #cell-reports_to="{ item }">
          <span class="text-xs text-ink-600">{{ (item as Position).reports_to?.job?.title ?? '—' }}</span>
        </template>

        <template #cell-headcount_cap="{ item }">
          <span class="font-mono text-xs text-ink-700">{{ (item as Position).headcount_cap ?? '—' }}</span>
        </template>

        <template #cell-status="{ item }">
          <StatusBadge :status="(item as Position).is_active ? 'active' : 'inactive'" />
        </template>

        <template #cell-actions="{ item }">
          <div class="flex items-center justify-end gap-3">
            <button
              type="button"
              class="text-xs font-semibold text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
              @click="openEdit(item as Position)"
            >
              Edit
            </button>
            <button
              type="button"
              class="text-xs font-medium text-signal-danger hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
              @click="deletePos(item as Position)"
            >
              Delete
            </button>
          </div>
        </template>
      </DataTable>
    </div>

    <!-- Create/Edit Modal -->
    <Modal :show="showModal" max-width="md" @close="showModal = false">
      <div class="p-6 bg-surface-0 border border-border text-ink-900 rounded-lg">
        <h3 class="text-lg font-bold text-ink-900">{{ isEditing ? 'Edit Position' : 'New Position' }}</h3>

        <form @submit.prevent="submit" class="mt-4 space-y-4">
          <div>
            <FormSelect
              label="Job Role"
              name="job_id"
              v-model="form.job_id"
              :error="form.errors.job_id"
              :options="jobs.map(j => ({ label: j.title, value: j.id }))"
              placeholder="Select job title…"
              required
            />
          </div>

          <div>
            <FormSelect
              label="Org Unit / Department"
              name="org_unit_id"
              v-model="form.org_unit_id"
              :error="form.errors.org_unit_id"
              :options="orgUnits.map(u => ({ label: u.name, value: u.id }))"
              placeholder="Select org unit…"
              required
            />
          </div>

          <div>
            <FormSelect
              label="Reports To Position (Optional)"
              name="reports_to_position_id"
              v-model="form.reports_to_position_id"
              :error="form.errors.reports_to_position_id"
              :options="allPositions.filter(p => !form.id || p.id !== form.id).map(p => ({ label: `${p.job?.title} (${p.org_unit?.name})`, value: p.id }))"
              placeholder="None (Top Level)"
            />
          </div>

          <div>
            <FormNumberInput
              label="Headcount Cap"
              name="headcount_cap"
              v-model="form.headcount_cap"
              :error="form.errors.headcount_cap"
              :min="1"
              required
            />
          </div>

          <div>
            <FormSwitch
              v-model="form.is_active"
              name="is_active"
              label="Position is Active"
              description="Allow employee assignments to this position."
            />
          </div>

          <div class="flex justify-end gap-3 pt-2">
            <SecondaryButton type="button" @click="showModal = false">Cancel</SecondaryButton>
            <PrimaryButton type="submit" :disabled="form.processing">
              {{ isEditing ? 'Save Changes' : 'Create Position' }}
            </PrimaryButton>
          </div>
        </form>
      </div>
    </Modal>
  </AppLayout>
</template>
