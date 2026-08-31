<!-- ponytail: Designations (job title catalog, HCM.jobs) — flat CRUD list, same modal pattern
     as HCM/OrgUnits/Index.vue. Independent of Positions (the org-chart seats that reference
     these titles) — a job title can exist in the catalog with zero positions filled against it. -->
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
import FormSwitch from '@/Components/forms/FormSwitch.vue'
import DataTable, { type FilterFieldDef, type SortState } from '@/Components/tables/DataTable.vue'
import { debounce } from '@/Composables/debounce'
import { useConfirm } from '@/Composables/useConfirmDialog'

interface JobRow {
  id: number
  code: string
  title: string
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
  jobs: PaginatedData<JobRow>
  filters: { search?: string; is_active?: string; sort?: string; direction?: string; per_page?: string }
}>()

const search = ref(props.filters.search ?? '')
const filters = ref({ is_active: props.filters.is_active ?? '' })
const sort = ref<SortState>(
  props.filters.sort ? { key: props.filters.sort, direction: props.filters.direction === 'desc' ? 'desc' : 'asc' } : null,
)
const selected = ref<Array<string | number>>([])
const perPage = ref(Number(props.filters.per_page) || props.jobs.per_page)

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
  { key: 'code', label: 'Code', sortable: true },
  { key: 'title', label: 'Designation' },
  { key: 'status', label: 'Status' },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

const form = useForm({
  id: null as number | null,
  code: '',
  title: '',
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

const openEdit = (job: JobRow) => {
  form.id = job.id
  form.code = job.code
  form.title = job.title
  form.is_active = Boolean(job.is_active)
  isEditing.value = true
  showModal.value = true
}

const submit = () => {
  if (isEditing.value && form.id) {
    form.put(route('hcm.jobs.update', form.id), { onSuccess: () => { showModal.value = false } })
  } else {
    form.post(route('hcm.jobs.store'), { onSuccess: () => { showModal.value = false } })
  }
}

const { confirm } = useConfirm()
const deleteJob = (job: JobRow) => {
  confirm({
    title: `Delete designation "${job.title}"?`,
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () => router.delete(route('hcm.jobs.destroy', job.id)),
  })
}

watch([search, filters, sort, perPage], debounce(() => {
  selected.value = []
  router.get(route('hcm.jobs.index'), {
    search: search.value || undefined,
    is_active: filters.value.is_active || undefined,
    sort: sort.value?.key,
    direction: sort.value?.direction,
    per_page: perPage.value,
  }, { preserveState: true, replace: true })
}, 400))
</script>

<template>
  <AppLayout title="Designations">
    <PageHeader title="Designations" subtitle="Job title catalog — independent of who currently fills any of them.">
      <template #actions>
        <PrimaryButton type="button" @click="openCreate">+ Add Designation</PrimaryButton>
      </template>
    </PageHeader>

    <div class="mt-4">
      <HcmSubNav active="jobs" />
    </div>

    <div class="mt-6">
      <DataTable
        :columns="columns"
        :items="jobs.data"
        v-model:sort="sort"
        v-model:selected="selected"
        v-model:search="search"
        v-model:filters="filters"
        v-model:per-page="perPage"
        sticky-header
        storage-key="hcm.jobs"
        search-placeholder="Search by code or title…"
        :filter-fields="filterFields"
        export-filename="hcm-designations"
        status-rail-key="is_active"
        :total="jobs.total"
        :from="jobs.from"
        :to="jobs.to"
        :links="jobs.links"
        empty-title="No designations found"
        empty-description="Add a job title to the catalog."
      >
        <template #cell-code="{ item }">
          <span class="font-mono text-xs font-medium text-ink-900">{{ (item as JobRow).code }}</span>
        </template>

        <template #cell-title="{ item }">
          <span class="font-semibold text-ink-900">{{ (item as JobRow).title }}</span>
        </template>

        <template #cell-status="{ item }">
          <StatusBadge :status="(item as JobRow).is_active ? 'active' : 'inactive'" />
        </template>

        <template #cell-actions="{ item }">
          <div class="flex items-center justify-end gap-3">
            <button
              type="button"
              class="text-xs font-semibold text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
              @click="openEdit(item as JobRow)"
            >
              Edit
            </button>
            <button
              type="button"
              class="text-xs font-medium text-signal-danger hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
              @click="deleteJob(item as JobRow)"
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
        <h3 class="text-lg font-bold text-ink-900">{{ isEditing ? 'Edit Designation' : 'New Designation' }}</h3>

        <form @submit.prevent="submit" class="mt-4 space-y-4">
          <FormInput
            label="Code"
            name="code"
            v-model="form.code"
            :error="form.errors.code"
            placeholder="e.g. MGR-01"
            required
          />

          <FormInput
            label="Title"
            name="title"
            v-model="form.title"
            :error="form.errors.title"
            placeholder="e.g. Operations Manager"
            required
          />

          <FormSwitch
            v-model="form.is_active"
            name="is_active"
            label="Designation is Active"
            description="Allow positions to be created against this designation."
          />

          <div class="flex justify-end gap-3 pt-2">
            <SecondaryButton type="button" @click="showModal = false">Cancel</SecondaryButton>
            <PrimaryButton type="submit" :disabled="form.processing">
              {{ isEditing ? 'Save Changes' : 'Create Designation' }}
            </PrimaryButton>
          </div>
        </form>
      </div>
    </Modal>
  </AppLayout>
</template>
