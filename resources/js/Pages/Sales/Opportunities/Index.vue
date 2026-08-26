<!-- Sales Opportunities List & Kanban (§3C) -->
<script setup lang="ts">
import { ref, computed } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import SalesSubNav from '@/Components/sales/SalesSubNav.vue'
import DataTable, { type FilterFieldDef, type SortState } from '@/Components/tables/DataTable.vue'
import { formatCurrency, formatDate } from '@/Utils/formatters'

interface OpportunityItem {
  id: number
  uuid: string
  name: string
  stage: string
  estimated_value: number | null
  expected_close_date: string | null
  loss_reason: string | null
  customer: { id: number; name: string } | null
  lead: { id: number; title: string } | null
  owner: { id: number; name: string } | null
  sales_team: { id: number; name: string } | null
}

const props = defineProps<{
  opportunities: OpportunityItem[]
  stages: string[]
  filters: { search?: string; stage?: string; owner_id?: string }
  users: Array<{ id: number; name: string }>
  teams: Array<{ id: number; name: string }>
}>()

const viewMode = ref<'board' | 'list'>('board')
const search = ref(props.filters.search ?? '')
const filters = ref({
  stage: props.filters.stage ?? '',
  owner_id: props.filters.owner_id ?? '',
})
const sort = ref<SortState>(null)
const selected = ref<Array<string | number>>([])

const stageTitle = (stage: string) => {
  return stage.charAt(0).toUpperCase() + stage.slice(1)
}

const filterFields: FilterFieldDef[] = [
  {
    key: 'stage',
    label: 'Stage',
    type: 'select',
    options: props.stages.map((s) => ({ label: stageTitle(s), value: s })),
  },
  {
    key: 'owner_id',
    label: 'Owner',
    type: 'select',
    options: props.users.map((u) => ({ label: u.name, value: String(u.id) })),
  },
]

const columns = [
  { key: 'name', label: 'Opportunity', sortable: true },
  { key: 'customer', label: 'Customer' },
  { key: 'stage', label: 'Stage', sortable: true },
  { key: 'estimated_value', label: 'Est. Value', align: 'right' as const, sortable: true },
  { key: 'expected_close_date', label: 'Expected Close', sortable: true },
  { key: 'owner', label: 'Owner' },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

const filteredOpportunities = computed(() => {
  let list = props.opportunities
  if (search.value) {
    const q = search.value.toLowerCase()
    list = list.filter((o) =>
      o.name.toLowerCase().includes(q) ||
      (o.customer?.name ?? '').toLowerCase().includes(q) ||
      (o.owner?.name ?? '').toLowerCase().includes(q)
    )
  }
  if (filters.value.stage) {
    list = list.filter((o) => o.stage === filters.value.stage)
  }
  if (filters.value.owner_id) {
    list = list.filter((o) => String(o.owner?.id) === filters.value.owner_id)
  }
  return list
})

const getByStage = (stage: string) => {
  return filteredOpportunities.value.filter((o) => o.stage === stage)
}

const updateStage = (oppId: number, newStage: string) => {
  router.patch(route('sales.opportunities.stage', oppId), {
    stage: newStage,
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader
      title="Opportunities"
      description="Sales pipeline tracking from initial qualification to won/lost deals (§3C)."
    >
      <template #actions>
        <PrimaryButton :href="route('sales.opportunities.create')">New Opportunity</PrimaryButton>
      </template>
    </PageHeader>

    <div class="mt-4">
      <SalesSubNav active="opportunities" />
    </div>

    <!-- Filters & View Switcher -->
    <div class="mt-6 flex flex-wrap items-center justify-between gap-4">
      <div class="flex items-center gap-1 rounded-md border border-border bg-surface-0 p-1">
        <button
          type="button"
          @click="viewMode = 'board'"
          class="px-3 py-1.5 text-xs font-medium rounded transition"
          :class="viewMode === 'board' ? 'bg-ink-900 text-white font-semibold' : 'text-ink-600 hover:text-ink-900'"
        >
          Kanban Board
        </button>
        <button
          type="button"
          @click="viewMode = 'list'"
          class="px-3 py-1.5 text-xs font-medium rounded transition"
          :class="viewMode === 'list' ? 'bg-ink-900 text-white font-semibold' : 'text-ink-600 hover:text-ink-900'"
        >
          List View
        </button>
      </div>
    </div>

    <!-- Kanban Board View -->
    <div v-if="viewMode === 'board'" class="mt-6">
      <div class="mb-4 max-w-md">
        <input
          v-model="search"
          type="text"
          placeholder="Filter opportunities or customers…"
          class="w-full rounded-md border border-border bg-surface-0 px-3 py-1.5 text-sm text-ink-900 focus:border-accent focus:outline-none"
        />
      </div>

      <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-5">
        <div
          v-for="stage in props.stages"
          :key="stage"
          class="flex flex-col rounded-lg border border-border bg-surface-50 p-3 min-h-[450px]"
        >
          <div class="flex items-center justify-between pb-2 border-b border-border mb-3">
            <span class="text-xs font-semibold uppercase tracking-wider text-ink-700">
              {{ stageTitle(stage) }}
            </span>
            <span class="rounded-full bg-surface-200 px-2 py-0.5 text-xs font-bold text-ink-700">
              {{ getByStage(stage).length }}
            </span>
          </div>

          <div class="flex-1 space-y-3 overflow-y-auto">
            <div
              v-for="opp in getByStage(stage)"
              :key="opp.id"
              class="rounded-md border border-border bg-surface-0 p-3 shadow-xs hover:border-ink-400 transition"
            >
              <div class="flex items-start justify-between gap-2">
                <Link :href="route('sales.opportunities.edit', opp.id)" class="text-sm font-semibold text-ink-900 hover:text-accent">
                  {{ opp.name }}
                </Link>
              </div>

              <p class="mt-1 text-xs text-ink-600 truncate">
                {{ opp.customer?.name ?? 'Prospect' }}
              </p>

              <div class="mt-2.5 flex items-center justify-between text-xs">
                <span class="font-mono font-semibold text-ink-900">{{ formatCurrency(opp.estimated_value) }}</span>
                <span v-if="opp.owner" class="text-ink-500">{{ opp.owner.name }}</span>
              </div>

              <div v-if="opp.loss_reason" class="mt-2 text-xs text-rose-600 bg-rose-50 p-1.5 rounded">
                Reason: {{ opp.loss_reason }}
              </div>

              <!-- Quick Stage Move -->
              <div class="mt-3 pt-2 border-t border-border flex items-center justify-between text-xs text-ink-400">
                <Link :href="route('sales.quotations.create', { customer_id: opp.customer?.id, opportunity_id: opp.id })" class="text-accent hover:underline">
                  + Quote
                </Link>
                <select
                  :value="opp.stage"
                  @change="updateStage(opp.id, ($event.target as HTMLSelectElement).value)"
                  class="rounded border border-border text-[11px] py-0.5 px-1 bg-surface-50 text-ink-700 focus:outline-none"
                >
                  <option v-for="s in props.stages" :key="s" :value="s">{{ stageTitle(s) }}</option>
                </select>
              </div>
            </div>

            <div v-if="getByStage(stage).length === 0" class="py-8 text-center text-xs text-ink-400">
              Empty
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Table List View -->
    <div v-else class="mt-6">
      <DataTable
        :columns="columns"
        :items="filteredOpportunities"
        v-model:sort="sort"
        v-model:selected="selected"
        v-model:search="search"
        v-model:filters="filters"
        sticky-header
        storage-key="sales.opportunities"
        search-placeholder="Search opportunities or customers…"
        :filter-fields="filterFields"
        export-filename="sales-opportunities"
        status-rail-key="stage"
        empty-title="No opportunities found"
        empty-description="Create an opportunity to track sales pipeline deals."
      >
        <template #cell-name="{ item }">
          <Link
            :href="route('sales.opportunities.edit', item.id)"
            class="font-semibold text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            {{ (item as OpportunityItem).name }}
          </Link>
        </template>

        <template #cell-customer="{ item }">
          <span class="text-ink-900">{{ (item as OpportunityItem).customer?.name ?? 'Prospect' }}</span>
        </template>

        <template #cell-stage="{ item }">
          <StatusBadge :status="(item as OpportunityItem).stage" />
        </template>

        <template #cell-estimated_value="{ item }">
          <span class="font-mono font-semibold text-ink-900">
            {{ formatCurrency((item as OpportunityItem).estimated_value) }}
          </span>
        </template>

        <template #cell-expected_close_date="{ item }">
          <span class="text-ink-600">
            {{ (item as OpportunityItem).expected_close_date ? formatDate((item as OpportunityItem).expected_close_date) : '-' }}
          </span>
        </template>

        <template #cell-owner="{ item }">
          <span class="text-ink-600">{{ (item as OpportunityItem).owner?.name ?? '-' }}</span>
        </template>

        <template #cell-actions="{ item }">
          <div class="flex items-center justify-end gap-2">
            <Link
              :href="route('sales.quotations.create', { customer_id: (item as OpportunityItem).customer?.id, opportunity_id: (item as OpportunityItem).id })"
              class="text-xs font-semibold text-accent hover:underline"
            >
              + Quote
            </Link>
            <Link
              :href="route('sales.opportunities.edit', item.id)"
              class="text-xs font-medium text-ink-600 hover:underline"
            >
              Edit
            </Link>
          </div>
        </template>
      </DataTable>
    </div>
  </AppLayout>
</template>
