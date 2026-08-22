<!-- ponytail: PPAT deeds (§3G) — AJB, Hibah & other statutory land-transfer acts -->
<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import DataTable, { type FilterFieldDef, type SortState } from '@/Components/tables/DataTable.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import LegalSubNav from '@/Components/legal/LegalSubNav.vue'
import { ref, watch } from 'vue'
import { debounce } from '@/Composables/debounce'
import { useConfirm } from '@/Composables/useConfirmDialog'

interface PpatDeedRow {
  id: number
  deed_number: string | null
  deed_type_name: string | null
  matter_code: string | null
  land_object_certificate: string | null
  transaction_value: string | null
  status: string
  signing_date_formatted: string | null
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
  deeds: PaginatedData<PpatDeedRow>
  filters: { search?: string; status?: string; sort?: string; direction?: string; per_page?: string }
}>()

const search = ref(props.filters.search ?? '')
const filters = ref({ status: props.filters.status ?? '' })
const sort = ref<SortState>(
  props.filters.sort ? { key: props.filters.sort, direction: props.filters.direction === 'desc' ? 'desc' : 'asc' } : null,
)
const selected = ref<Array<string | number>>([])
const perPage = ref(Number(props.filters.per_page) || props.deeds.per_page)

const filterFields: FilterFieldDef[] = [
  {
    key: 'status',
    label: 'Status',
    type: 'select',
    options: [
      { label: 'Draft', value: 'draft' },
      { label: 'Ready for signing', value: 'ready_for_signing' },
      { label: 'Signed', value: 'signed' },
      { label: 'Archived', value: 'archived' },
    ],
  },
]

const columns = [
  { key: 'deed_number', label: 'Deed no.', sortable: true },
  { key: 'deed_type_name', label: 'Type' },
  { key: 'land_object_certificate', label: 'Land object' },
  { key: 'transaction_value', label: 'Value' },
  { key: 'status', label: 'Status', sortable: true },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

watch([search, filters, sort, perPage], debounce(() => {
  selected.value = []
  router.get(route('legal.ppatDeeds.index'), {
    search: search.value,
    status: filters.value.status,
    sort: sort.value?.key,
    direction: sort.value?.direction,
    per_page: perPage.value,
  }, { preserveState: true, replace: true })
}, 400))

const { confirm } = useConfirm()

const confirmDelete = (item: PpatDeedRow | Record<string, unknown>) => {
  const row = item as PpatDeedRow
  confirm({
    title: 'Delete this draft PPAT deed?',
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () => router.delete(route('legal.ppatDeeds.destroy', row.id)),
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader title="PPAT Deeds" description="AJB, Hibah, and other statutory land-transfer acts — gated on due diligence and tax clearance.">
      <template #actions>
        <PrimaryButton :href="route('legal.ppatDeeds.create')">Draft PPAT deed</PrimaryButton>
      </template>
    </PageHeader>

    <LegalSubNav active="ppat-deeds" class="mt-6" />

    <div class="mt-6 space-y-4">
      <DataTable
        :columns="columns"
        :items="deeds.data"
        v-model:sort="sort"
        v-model:selected="selected"
        v-model:search="search"
        v-model:filters="filters"
        v-model:per-page="perPage"
        sticky-header
        storage-key="legal.ppat_deeds"
        status-rail-key="status"
        search-placeholder="Search deed no. or minuta reference…"
        :filter-fields="filterFields"
        export-filename="legal-ppat-deeds"
        :total="deeds.total"
        :from="deeds.from"
        :to="deeds.to"
        :links="deeds.links"
        empty-title="No PPAT deeds yet"
        empty-description="Draft your first PPAT deed."
      >
        <template #cell-deed_number="{ item }">
          <span class="font-mono text-sm text-ink-900">{{ item.deed_number || '—' }}</span>
        </template>
        <template #cell-status="{ item }">
          <StatusBadge :status="item.status" />
        </template>
        <template #cell-actions="{ item }">
          <div class="flex items-center justify-end gap-3">
            <Link
              :href="route('legal.ppatDeeds.edit', item.id)"
              class="text-sm font-medium text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
            >
              Open
            </Link>
            <button
              v-if="item.status === 'draft'"
              type="button"
              class="text-sm font-medium text-signal-danger hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
              @click="confirmDelete(item)"
            >
              Delete
            </button>
          </div>
        </template>
      </DataTable>
    </div>
  </AppLayout>
</template>
