<!-- ponytail: Accounting §3M — tenant-entered DJP Faktur Pajak number-allocation blocks. -->
<script setup lang="ts">
import { ref, computed } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import DataTable, { type SortState } from '@/Components/tables/DataTable.vue'
import { useConfirm } from '@/Composables/useConfirmDialog'

interface BlockRow {
  id: number
  prefix: string
  range_start: number
  range_end: number
  last_issued: number | null
  remaining: number
  is_active: boolean
}

const props = defineProps<{
  companies: Array<{ id: number; legal_name: string }>
  selectedCompanyId: number | null
  blocks: BlockRow[]
}>()

const search = ref('')
const sort = ref<SortState>(null)
const selected = ref<Array<string | number>>([])

const columns = [
  { key: 'prefix', label: 'Prefix', sortable: true },
  { key: 'range', label: 'NSFP Range' },
  { key: 'last_issued', label: 'Last Issued' },
  { key: 'remaining', label: 'Remaining', sortable: true, align: 'right' as const },
  { key: 'is_active', label: 'Status' },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

const filteredBlocks = computed(() => {
  const q = search.value.trim().toLowerCase()
  if (!q) return props.blocks
  return props.blocks.filter((b) => b.prefix.toLowerCase().includes(q))
})

const switchCompany = (e: Event) => {
  const companyId = (e.target as HTMLSelectElement).value
  router.get(route('accounting.faktur-blocks.index'), { company_id: companyId }, { preserveState: true })
}

const { confirm } = useConfirm()
const confirmDeactivate = (block: BlockRow) => {
  confirm({
    title: `Deactivate block "${block.prefix}"?`,
    description: 'No more numbers will be drawn from this block. Existing issued Faktur Pajak are unaffected.',
    confirmText: 'Deactivate',
    onConfirm: () => router.post(route('accounting.faktur-blocks.deactivate', block.id)),
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader title="Faktur Pajak Number Blocks" description="DJP-allocated Nomor Seri Faktur Pajak ranges — output Faktur Pajak numbers are drawn from here sequentially, and a block can never wrap or reuse a number.">
      <template #actions>
        <PrimaryButton :href="route('accounting.faktur-blocks.create', { company_id: selectedCompanyId })">
          New Block
        </PrimaryButton>
      </template>
    </PageHeader>

    <div class="mt-6 space-y-4">
      <div class="flex items-center gap-3">
        <label class="text-xs font-semibold text-ink-600">Company:</label>
        <select
          :value="selectedCompanyId"
          class="rounded-md border border-border bg-surface-0 pl-3 pr-8 py-1.5 text-sm font-medium text-ink-900 shadow-xs focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/20 cursor-pointer"
          @change="switchCompany"
        >
          <option v-for="c in companies" :key="c.id" :value="c.id">{{ c.legal_name }}</option>
        </select>
      </div>

      <DataTable
        :columns="columns"
        :items="filteredBlocks"
        v-model:sort="sort"
        v-model:selected="selected"
        v-model:search="search"
        sticky-header
        storage-key="accounting.faktur-blocks"
        search-placeholder="Search prefix…"
        export-filename="faktur-blocks"
        status-rail-key="is_active"
        empty-title="No Faktur Pajak number blocks found"
        empty-description="Register DJP allocated NSFP serial ranges for tax invoice issuance."
      >
        <template #cell-prefix="{ item }">
          <span class="font-mono font-medium text-ink-900">{{ (item as BlockRow).prefix }}</span>
        </template>

        <template #cell-range="{ item }">
          <span class="font-mono text-xs text-ink-700">{{ (item as BlockRow).range_start }} – {{ (item as BlockRow).range_end }}</span>
        </template>

        <template #cell-last_issued="{ item }">
          <span class="font-mono text-xs text-ink-700">{{ (item as BlockRow).last_issued ?? '—' }}</span>
        </template>

        <template #cell-remaining="{ item }">
          <span class="font-mono text-xs font-semibold" :class="(item as BlockRow).remaining < 50 ? 'text-signal-danger' : 'text-ink-900'">
            {{ (item as BlockRow).remaining }}
          </span>
        </template>

        <template #cell-is_active="{ item }">
          <StatusBadge :status="(item as BlockRow).is_active ? 'active' : 'inactive'" />
        </template>

        <template #cell-actions="{ item }">
          <div class="flex items-center justify-end">
            <button
              v-if="(item as BlockRow).is_active"
              type="button"
              class="text-xs font-medium text-signal-danger hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
              @click="confirmDeactivate(item as BlockRow)"
            >
              Deactivate
            </button>
          </div>
        </template>
      </DataTable>
    </div>
  </AppLayout>
</template>
