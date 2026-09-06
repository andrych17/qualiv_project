<!-- ponytail: PPAT deeds (§3G) — AJB, Hibah & other statutory land-transfer acts -->
<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import DataTable, { type FilterFieldDef, type SortState } from '@/Components/tables/DataTable.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import LegalSubNav from '@/Components/legal/LegalSubNav.vue'
import { ref, watch, computed } from 'vue'
import { debounce } from '@/Composables/debounce'
import { useConfirm } from '@/Composables/useConfirmDialog'
import { useI18n } from '@/Composables/useI18n'

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

const { t } = useI18n()
const search = ref(props.filters.search ?? '')
const filters = ref({ status: props.filters.status ?? '' })
const sort = ref<SortState>(
  props.filters.sort ? { key: props.filters.sort, direction: props.filters.direction === 'desc' ? 'desc' : 'asc' } : null,
)
const selected = ref<Array<string | number>>([])
const perPage = ref(Number(props.filters.per_page) || props.deeds.per_page)

const filterFields = computed<FilterFieldDef[]>(() => [
  {
    key: 'status',
    label: t('common.status'),
    type: 'select',
    options: [
      { label: t('legal.status_draft'), value: 'draft' },
      { label: t('legal.status_ready_for_signing'), value: 'ready_for_signing' },
      { label: t('legal.status_signed'), value: 'signed' },
      { label: t('legal.status_archived'), value: 'archived' },
    ],
  },
])

const columns = computed(() => [
  { key: 'deed_number', label: t('legal.deed_no'), sortable: true },
  { key: 'deed_type_name', label: t('legal.deed_type') },
  { key: 'land_object_certificate', label: t('legal.land_objects') },
  { key: 'transaction_value', label: t('common.details') },
  { key: 'status', label: t('common.status'), sortable: true },
  { key: 'actions', label: t('common.actions'), align: 'right' as const },
])

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
    title: t('legal.confirm_delete_deed'),
    variant: 'destructive',
    confirmText: t('common.delete'),
    onConfirm: () => router.delete(route('legal.ppatDeeds.destroy', row.id)),
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader :title="t('legal.ppat_deeds')" :description="t('legal.ppat_deeds_subtitle')">
      <template #actions>
        <PrimaryButton :href="route('legal.ppatDeeds.create')">{{ t('legal.new_ppat_deed') }}</PrimaryButton>
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
        :search-placeholder="t('legal.search_placeholder')"
        :filter-fields="filterFields"
        export-filename="legal-ppat-deeds"
        :total="deeds.total"
        :from="deeds.from"
        :to="deeds.to"
        :links="deeds.links"
        :empty-title="t('legal.empty_ppat_deeds_title')"
        :empty-description="t('legal.empty_ppat_deeds_desc')"
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
              {{ t('common.open') }}
            </Link>
            <button
              v-if="item.status === 'draft'"
              type="button"
              class="text-sm font-medium text-signal-danger hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
              @click="confirmDelete(item)"
            >
              {{ t('common.delete') }}
            </button>
          </div>
        </template>
      </DataTable>
    </div>
  </AppLayout>
</template>
