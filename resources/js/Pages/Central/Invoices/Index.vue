<!-- ponytail: Invoice listing -->
<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3'
import CentralAdminLayout from '@/Layouts/CentralAdminLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import DataTable, { type SortState } from '@/Components/tables/DataTable.vue'
import { ref, watch } from 'vue'
import { debounce } from '@/Composables/debounce'
import { useConfirm } from '@/Composables/useConfirmDialog'

interface InvoiceRow {
  id: number
  tenant: { id: string; name: string } | null
  plan_code: string
  status: string
  amount_total: string | number
  currency: string
  due_date: string
}

const props = defineProps<{
  invoices: { data: InvoiceRow[]; links: Array<{ url: string | null; label: string; active: boolean }>; total: number; from: number | null; to: number | null; per_page: number }
  filters: { status?: string; sort?: string; direction?: string; per_page?: string }
}>()

const sort = ref<SortState>(props.filters.sort ? { key: props.filters.sort, direction: props.filters.direction === 'desc' ? 'desc' : 'asc' } : null)
const perPage = ref(Number(props.filters.per_page) || props.invoices.per_page)

const columns = [
  { key: 'id', label: 'Invoice #', sortable: true },
  { key: 'tenant', label: 'Tenant' },
  { key: 'plan_code', label: 'Plan' },
  { key: 'status', label: 'Status', sortable: true },
  { key: 'amount_total', label: 'Amount', align: 'right' as const, sortable: true },
  { key: 'due_date', label: 'Due', sortable: true },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

watch([sort, perPage], debounce(() => {
  router.get(route('central.invoices.index'), {
    sort: sort.value?.key,
    direction: sort.value?.direction,
    per_page: perPage.value,
  }, { preserveState: true, replace: true })
}, 400))

const { confirm } = useConfirm()

const confirmVoid = (item: InvoiceRow | Record<string, unknown>) => {
  const row = item as InvoiceRow
  confirm({
    title: `Void invoice #${row.id}?`,
    variant: 'destructive',
    confirmText: 'Void',
    onConfirm: () => router.delete(route('central.invoices.destroy', row.id)),
  })
}
</script>

<template>
  <CentralAdminLayout>
    <PageHeader title="Invoices" description="Subscription invoices issued to tenants.">
      <template #actions>
        <Link :href="route('central.invoices.create')" class="inline-flex items-center rounded-md bg-gray-900 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-gray-800">
          Generate Invoice
        </Link>
      </template>
    </PageHeader>

    <div class="mt-6">
      <DataTable
        :columns="columns"
        :items="invoices.data"
        v-model:sort="sort"
        v-model:per-page="perPage"
        storage-key="central.invoices"
        :total="invoices.total"
        :from="invoices.from"
        :to="invoices.to"
        :links="invoices.links"
        empty-title="No invoices"
        empty-description="Generate the first invoice for a tenant."
      >
        <template #cell-tenant="{ item }">{{ item.tenant?.name ?? '—' }}</template>
        <template #cell-status="{ item }">
          <span :class="{ 'text-green-700': item.status === 'paid', 'text-amber-700': item.status === 'issued', 'text-gray-400': item.status === 'void' }">
            {{ item.status }}
          </span>
        </template>
        <template #cell-actions="{ item }">
          <div class="flex items-center justify-end gap-2">
            <Link :href="route('central.invoices.show', item.id)" class="text-sm font-medium text-gray-700 hover:text-gray-900">View</Link>
            <button v-if="item.status !== 'void' && item.status !== 'paid'" type="button" class="text-sm font-medium text-red-600 hover:text-red-950" @click="confirmVoid(item)">
              Void
            </button>
          </div>
        </template>
      </DataTable>
    </div>
  </CentralAdminLayout>
</template>
