<!-- ponytail: Accounting §3G asset groups — Indonesian fiscal tax classification, tenant-editable. -->
<script setup lang="ts">
import { ref, computed } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import DataTable, { type SortState } from '@/Components/tables/DataTable.vue'
import { useConfirm } from '@/Composables/useConfirmDialog'

interface AssetGroupRow {
  id: number
  code: string
  name: string
  is_building: boolean
  fiscal_useful_life_months: number
  fiscal_straight_line_rate: string
  fiscal_declining_rate: string | null
  is_active: boolean
}

const props = defineProps<{
  companies: Array<{ id: number; legal_name: string }>
  selectedCompanyId: number | null
  assetGroups: AssetGroupRow[]
}>()

const search = ref('')
const sort = ref<SortState>(null)
const selected = ref<Array<string | number>>([])

const columns = [
  { key: 'code', label: 'Code', sortable: true },
  { key: 'name', label: 'Group Name', sortable: true },
  { key: 'is_building', label: 'Building' },
  { key: 'fiscal_useful_life_months', label: 'Useful Life (mo)', sortable: true, align: 'right' as const },
  { key: 'fiscal_straight_line_rate', label: 'Straight-Line Rate', align: 'right' as const },
  { key: 'fiscal_declining_rate', label: 'Declining Rate', align: 'right' as const },
  { key: 'is_active', label: 'Status' },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

const filteredGroups = computed(() => {
  const q = search.value.trim().toLowerCase()
  if (!q) return props.assetGroups
  return props.assetGroups.filter((g) => g.code.toLowerCase().includes(q) || g.name.toLowerCase().includes(q))
})

const pct = (rate: string | null) => (rate === null ? '—' : `${(Number(rate) * 100).toFixed(2)}%`)

const switchCompany = (e: Event) => {
  const companyId = (e.target as HTMLSelectElement).value
  router.get(route('accounting.asset-groups.index'), { company_id: companyId }, { preserveState: true })
}

const seedStarter = () => {
  if (!props.selectedCompanyId) return
  router.post(route('accounting.asset-groups.seed-starter', props.selectedCompanyId))
}

const { confirm } = useConfirm()
const confirmDelete = (group: AssetGroupRow) => {
  confirm({
    title: `Delete asset group "${group.code}"?`,
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () => router.delete(route('accounting.asset-groups.destroy', group.id)),
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader title="Asset Groups" description="Indonesian fiscal tax classification (Kelompok 1-4, Bangunan) — rates are data, editable per PMK updates, never hardcoded.">
      <template #actions>
        <div class="flex items-center gap-2">
          <button
            v-if="!assetGroups.length"
            type="button"
            class="text-xs font-semibold text-accent hover:underline mr-2"
            @click="seedStarter"
          >
            + Seed starter groups
          </button>
          <PrimaryButton :href="route('accounting.asset-groups.create', { company_id: selectedCompanyId })">
            New Group
          </PrimaryButton>
        </div>
      </template>
    </PageHeader>

    <div class="mt-6 space-y-4">
      <div class="flex items-center gap-3">
        <label class="text-xs font-semibold text-ink-600">Company:</label>
        <select
          :value="selectedCompanyId"
          class="rounded-md border border-border bg-surface-0 px-3 py-1.5 text-sm font-medium text-ink-900 shadow-xs focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/20"
          @change="switchCompany"
        >
          <option v-for="c in companies" :key="c.id" :value="c.id">{{ c.legal_name }}</option>
        </select>
      </div>

      <DataTable
        :columns="columns"
        :items="filteredGroups"
        v-model:sort="sort"
        v-model:selected="selected"
        v-model:search="search"
        sticky-header
        storage-key="accounting.asset-groups"
        search-placeholder="Search code or group name…"
        export-filename="asset-groups"
        status-rail-key="is_active"
        empty-title="No asset groups found"
        empty-description="Seed the standard fiscal tax groups (Kelompok 1-4) or add a custom group."
      >
        <template #cell-code="{ item }">
          <span class="font-mono font-medium text-ink-900">{{ (item as AssetGroupRow).code }}</span>
        </template>

        <template #cell-name="{ item }">
          <span class="font-medium text-ink-900">{{ (item as AssetGroupRow).name }}</span>
        </template>

        <template #cell-is_building="{ item }">
          <span class="text-xs text-ink-700">{{ (item as AssetGroupRow).is_building ? 'Yes' : 'No' }}</span>
        </template>

        <template #cell-fiscal_useful_life_months="{ item }">
          <span class="font-mono text-xs text-ink-700">{{ (item as AssetGroupRow).fiscal_useful_life_months }} mo</span>
        </template>

        <template #cell-fiscal_straight_line_rate="{ item }">
          <span class="font-mono text-xs text-ink-700">{{ pct((item as AssetGroupRow).fiscal_straight_line_rate) }}</span>
        </template>

        <template #cell-fiscal_declining_rate="{ item }">
          <span class="font-mono text-xs text-ink-700">{{ pct((item as AssetGroupRow).fiscal_declining_rate) }}</span>
        </template>

        <template #cell-is_active="{ item }">
          <StatusBadge :status="(item as AssetGroupRow).is_active ? 'active' : 'inactive'" />
        </template>

        <template #cell-actions="{ item }">
          <div class="flex items-center justify-end gap-3">
            <Link
              :href="route('accounting.asset-groups.edit', (item as AssetGroupRow).id)"
              class="text-xs font-semibold text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
            >
              Edit
            </Link>
            <button
              type="button"
              class="text-xs font-medium text-signal-danger hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
              @click="confirmDelete(item as AssetGroupRow)"
            >
              Delete
            </button>
          </div>
        </template>
      </DataTable>
    </div>
  </AppLayout>
</template>
