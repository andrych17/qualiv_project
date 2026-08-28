<!-- Territories Index (§3B) -->
<script setup lang="ts">
import { ref } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSwitch from '@/Components/forms/FormSwitch.vue'
import SalesSubNav from '@/Components/sales/SalesSubNav.vue'
import SalesMasterSubNav from '@/Components/sales/SalesMasterSubNav.vue'
import DataTable, { type SortState } from '@/Components/tables/DataTable.vue'
import Modal from '@/Components/Modal.vue'
import { useConfirm } from '@/Composables/useConfirmDialog'

interface TerritoryItem {
  id: number
  code: string
  name: string
  parent_id: number | null
  is_active: boolean
  teams_count?: number
}

const props = defineProps<{
  territories: TerritoryItem[]
}>()

const search = ref('')
const sort = ref<SortState>(null)
const selected = ref<Array<string | number>>([])

const showModal = ref(false)
const editingTerritory = ref<TerritoryItem | null>(null)

const form = useForm({
  code: '',
  name: '',
  parent_id: null as number | null,
  is_active: true,
})

const columns = [
  { key: 'code', label: 'Code', sortable: true },
  { key: 'name', label: 'Territory Name', sortable: true },
  { key: 'teams_count', label: 'Sales Teams', align: 'center' as const },
  { key: 'is_active', label: 'Status', sortable: true },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

const openCreate = () => {
  editingTerritory.value = null
  form.reset()
  form.is_active = true
  showModal.value = true
}

const openEdit = (t: TerritoryItem) => {
  editingTerritory.value = t
  form.code = t.code
  form.name = t.name
  form.parent_id = t.parent_id
  form.is_active = t.is_active
  showModal.value = true
}

const submit = () => {
  if (editingTerritory.value) {
    form.put(route('sales.master.territories.update', editingTerritory.value.id), {
      onSuccess: () => { showModal.value = false },
    })
  } else {
    form.post(route('sales.master.territories.store'), {
      onSuccess: () => { showModal.value = false },
    })
  }
}

const { confirm } = useConfirm()

const deleteTerritory = (t: TerritoryItem) => {
  confirm({
    title: `Delete Territory "${t.name}"?`,
    description: 'Are you sure you want to delete this sales territory?',
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () => router.delete(route('sales.master.territories.destroy', t.id)),
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader
      title="Sales Territories"
      description="Manage geographical and operational sales regions (§3B)."
    >
      <template #actions>
        <PrimaryButton @click="openCreate">New Territory</PrimaryButton>
      </template>
    </PageHeader>

    <div class="mt-4">
      <SalesSubNav active="master" />
    </div>

    <div class="mt-4">
      <SalesMasterSubNav active="territories" />
    </div>

    <div class="mt-6">
      <DataTable
        :columns="columns"
        :items="props.territories"
        v-model:sort="sort"
        v-model:selected="selected"
        v-model:search="search"
        sticky-header
        storage-key="sales.master.territories"
        search-placeholder="Search territories…"
        export-filename="sales-territories"
        status-rail-key="is_active"
        empty-title="No territories found"
        empty-description="Create your first regional sales territory."
      >
        <template #cell-code="{ item }">
          <span class="font-mono font-medium text-accent">{{ (item as TerritoryItem).code }}</span>
        </template>

        <template #cell-name="{ item }">
          <span class="font-semibold text-ink-900">{{ (item as TerritoryItem).name }}</span>
        </template>

        <template #cell-teams_count="{ item }">
          <span class="font-mono text-xs text-ink-600">{{ (item as TerritoryItem).teams_count ?? 0 }} team(s)</span>
        </template>

        <template #cell-is_active="{ item }">
          <StatusBadge :status="(item as TerritoryItem).is_active ? 'active' : 'inactive'" />
        </template>

        <template #cell-actions="{ item }">
          <div class="flex items-center justify-end gap-3">
            <button
              type="button"
              @click="openEdit(item as TerritoryItem)"
              class="text-xs font-semibold text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
            >
              Edit
            </button>
            <button
              type="button"
              @click="deleteTerritory(item as TerritoryItem)"
              class="text-xs font-medium text-signal-danger hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
            >
              Delete
            </button>
          </div>
        </template>
      </DataTable>
    </div>

    <!-- Territory Modal -->
    <Modal :show="showModal" max-width="md" @close="showModal = false">
      <div class="p-6 bg-surface-0 border border-border text-ink-900 rounded-lg">
        <h3 class="text-lg font-semibold text-ink-900">{{ editingTerritory ? 'Edit Territory' : 'New Territory' }}</h3>

        <form @submit.prevent="submit" class="mt-4 space-y-4">
          <FormInput
            label="Territory Code"
            name="code"
            v-model="form.code"
            :error="form.errors.code"
            placeholder="e.g. ID-JKT"
            required
          />

          <FormInput
            label="Territory Name"
            name="name"
            v-model="form.name"
            :error="form.errors.name"
            placeholder="e.g. Greater Jakarta Region"
            required
          />

          <FormSwitch
            v-model="form.is_active"
            name="is_active"
            label="Active Status"
            description="Enable this territory for pricing and team assignments."
          />

          <div class="flex items-center justify-end gap-2 pt-2">
            <SecondaryButton @click="showModal = false">Cancel</SecondaryButton>
            <PrimaryButton type="submit" :disabled="form.processing">Save Territory</PrimaryButton>
          </div>
        </form>
      </div>
    </Modal>
  </AppLayout>
</template>
