<!-- Sales Opportunities List & Kanban (§3C) -->
<script setup lang="ts">
import { ref, computed } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import SalesSubNav from '@/Components/sales/SalesSubNav.vue'

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

const formatCurrency = (val: number | null, curr = 'IDR') => {
  if (val === null || val === undefined) return '-'
  return new Intl.NumberFormat('id-ID', { style: 'currency', currency: curr, maximumFractionDigits: 0 }).format(val)
}

const stageTitle = (stage: string) => {
  return stage.charAt(0).toUpperCase() + stage.slice(1)
}

const filteredOpportunities = computed(() => {
  if (!search.value) return props.opportunities
  const q = search.value.toLowerCase()
  return props.opportunities.filter((o) =>
    o.name.toLowerCase().includes(q) ||
    (o.customer?.name ?? '').toLowerCase().includes(q) ||
    (o.owner?.name ?? '').toLowerCase().includes(q)
  )
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
      <div class="flex items-center gap-3">
        <input
          v-model="search"
          type="text"
          placeholder="Search opportunities or customers…"
          class="rounded-md border border-border bg-surface-0 px-3 py-1.5 text-sm text-ink-900 focus:border-accent focus:outline-none"
        />
      </div>

      <div class="flex items-center gap-1 rounded-md border border-border bg-surface-0 p-1">
        <button
          type="button"
          @click="viewMode = 'board'"
          class="px-3 py-1 text-xs font-medium rounded transition"
          :class="viewMode === 'board' ? 'bg-ink-900 text-white font-semibold' : 'text-ink-600 hover:text-ink-900'"
        >
          Kanban Board
        </button>
        <button
          type="button"
          @click="viewMode = 'list'"
          class="px-3 py-1 text-xs font-medium rounded transition"
          :class="viewMode === 'list' ? 'bg-ink-900 text-white font-semibold' : 'text-ink-600 hover:text-ink-900'"
        >
          List View
        </button>
      </div>
    </div>

    <!-- Kanban Board View -->
    <div v-if="viewMode === 'board'" class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-5">
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

    <!-- Table List View -->
    <div v-else class="mt-6 rounded-lg border border-border bg-surface-0 overflow-x-auto">
      <table class="w-full text-left text-sm">
        <thead class="bg-surface-50 text-xs text-ink-500 uppercase border-b border-border">
          <tr>
            <th class="py-3 px-4">Opportunity</th>
            <th class="py-3 px-4">Customer</th>
            <th class="py-3 px-4">Stage</th>
            <th class="py-3 px-4">Est. Value</th>
            <th class="py-3 px-4">Expected Close</th>
            <th class="py-3 px-4">Owner</th>
            <th class="py-3 px-4 text-right">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-border">
          <tr v-for="opp in filteredOpportunities" :key="opp.id" class="hover:bg-surface-50">
            <td class="py-3 px-4 font-semibold text-ink-900">
              <Link :href="route('sales.opportunities.edit', opp.id)" class="hover:text-accent">
                {{ opp.name }}
              </Link>
            </td>
            <td class="py-3 px-4 text-ink-700">{{ opp.customer?.name ?? 'Prospect' }}</td>
            <td class="py-3 px-4"><StatusBadge :status="opp.stage" /></td>
            <td class="py-3 px-4 font-mono font-medium">{{ formatCurrency(opp.estimated_value) }}</td>
            <td class="py-3 px-4 text-ink-600">{{ opp.expected_close_date ?? '-' }}</td>
            <td class="py-3 px-4 text-ink-600">{{ opp.owner?.name ?? '-' }}</td>
            <td class="py-3 px-4 text-right space-x-2">
              <Link :href="route('sales.quotations.create', { customer_id: opp.customer?.id, opportunity_id: opp.id })" class="text-xs font-medium text-accent hover:underline">
                Create Quote
              </Link>
              <Link :href="route('sales.opportunities.edit', opp.id)" class="text-xs font-medium text-ink-600 hover:underline">
                Edit
              </Link>
            </td>
          </tr>
          <tr v-if="filteredOpportunities.length === 0">
            <td colspan="7" class="py-8 text-center text-ink-500">No opportunities found.</td>
          </tr>
        </tbody>
      </table>
    </div>
  </AppLayout>
</template>
