<!-- ponytail: OrgUnits management — hierarchical org tree and department list. -->
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
import FormSelect from '@/Components/forms/FormSelect.vue'
import FormSwitch from '@/Components/forms/FormSwitch.vue'
import DataTable, { type FilterFieldDef, type SortState } from '@/Components/tables/DataTable.vue'
import { debounce } from '@/Composables/debounce'
import { useConfirm } from '@/Composables/useConfirmDialog'

interface OrgUnit {
  id: number
  name: string
  parent_org_unit_id?: number
  parent?: { name: string }
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
  orgUnits: PaginatedData<OrgUnit>
  allOrgUnits: OrgUnit[]
  filters: {
    search?: string
    is_active?: string
    sort?: string
    direction?: string
    per_page?: string
  }
}>()

const search = ref(props.filters.search ?? '')
const filters = ref({
  is_active: props.filters.is_active ?? '',
})
const sort = ref<SortState>(
  props.filters.sort ? { key: props.filters.sort, direction: props.filters.direction === 'desc' ? 'desc' : 'asc' } : null,
)
const selected = ref<Array<string | number>>([])
const perPage = ref(Number(props.filters.per_page) || props.orgUnits.per_page)

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
  { key: 'name', label: 'Unit Name' },
  { key: 'parent', label: 'Parent Unit' },
  { key: 'status', label: 'Status' },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

const form = useForm({
  id: null as number | null,
  name: '',
  parent_org_unit_id: null as number | null,
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

const openEdit = (unit: OrgUnit) => {
  form.id = unit.id
  form.name = unit.name
  form.parent_org_unit_id = unit.parent_org_unit_id || null
  form.is_active = Boolean(unit.is_active)
  isEditing.value = true
  showModal.value = true
}

const submit = () => {
  if (isEditing.value && form.id) {
    form.put(route('hcm.orgUnits.update', form.id), {
      onSuccess: () => {
        showModal.value = false
      },
    })
  } else {
    form.post(route('hcm.orgUnits.store'), {
      onSuccess: () => {
        showModal.value = false
      },
    })
  }
}

const { confirm } = useConfirm()
const deleteUnit = (unit: OrgUnit) => {
  confirm({
    title: `Delete Organizational Unit "${unit.name}"?`,
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () => router.delete(route('hcm.orgUnits.destroy', unit.id)),
  })
}

watch([search, filters, sort, perPage], debounce(() => {
  selected.value = []
  router.get(
    route('hcm.orgUnits.index'),
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
  <AppLayout title="Organizational Units">
    <PageHeader title="Org Units" subtitle="Manage departments, divisions, and reporting structure.">
      <template #actions>
        <PrimaryButton type="button" @click="openCreate">+ Add Org Unit</PrimaryButton>
      </template>
    </PageHeader>

    <div class="mt-4">
      <HcmSubNav active="org" />
    </div>

    <div class="mt-6">
      <DataTable
        :columns="columns"
        :items="orgUnits.data"
        v-model:sort="sort"
        v-model:selected="selected"
        v-model:search="search"
        v-model:filters="filters"
        v-model:per-page="perPage"
        sticky-header
        storage-key="hcm.org-units"
        search-placeholder="Search org units…"
        :filter-fields="filterFields"
        export-filename="hcm-org-units"
        status-rail-key="is_active"
        :total="orgUnits.total"
        :from="orgUnits.from"
        :to="orgUnits.to"
        :links="orgUnits.links"
        empty-title="No Org Units found"
        empty-description="Create an organizational unit, division, or department."
      >
        <template #cell-name="{ item }">
          <span class="font-semibold text-ink-900">{{ (item as OrgUnit).name }}</span>
        </template>

        <template #cell-parent="{ item }">
          <span class="text-xs text-ink-600">{{ (item as OrgUnit).parent?.name ?? '—' }}</span>
        </template>

        <template #cell-status="{ item }">
          <StatusBadge :status="(item as OrgUnit).is_active ? 'active' : 'inactive'" />
        </template>

        <template #cell-actions="{ item }">
          <div class="flex items-center justify-end gap-3">
            <button
              type="button"
              class="text-xs font-semibold text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
              @click="openEdit(item as OrgUnit)"
            >
              Edit
            </button>
            <button
              type="button"
              class="text-xs font-medium text-signal-danger hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
              @click="deleteUnit(item as OrgUnit)"
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
        <h3 class="text-lg font-bold text-ink-900">{{ isEditing ? 'Edit Org Unit' : 'New Org Unit' }}</h3>

        <form @submit.prevent="submit" class="mt-4 space-y-4">
          <div>
            <FormInput
              label="Unit Name"
              name="name"
              v-model="form.name"
              :error="form.errors.name"
              placeholder="e.g. Legal Operations, Human Resources"
              required
            />
          </div>

          <div>
            <FormSelect
              label="Parent Org Unit (Optional)"
              name="parent_org_unit_id"
              v-model="form.parent_org_unit_id"
              :error="form.errors.parent_org_unit_id"
              :options="allOrgUnits.filter(u => !form.id || u.id !== form.id).map(u => ({ label: u.name, value: u.id }))"
              placeholder="None (Top Level)"
            />
          </div>

          <div>
            <FormSwitch
              v-model="form.is_active"
              name="is_active"
              label="Unit is Active"
              description="Allow positions and teams to belong to this org unit."
            />
          </div>

          <div class="flex justify-end gap-3 pt-2">
            <SecondaryButton type="button" @click="showModal = false">Cancel</SecondaryButton>
            <PrimaryButton type="submit" :disabled="form.processing">
              {{ isEditing ? 'Save Changes' : 'Create Unit' }}
            </PrimaryButton>
          </div>
        </form>
      </div>
    </Modal>
  </AppLayout>
</template>
