<!-- ponytail: Lead pipeline (§3D) — Board (Kanban, drag-to-advance) + List, same
     flat-array-with-client-side-filter architecture as Projects' issue board. -->
<script setup lang="ts">
import { ref, computed } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Tabs from '@/Components/navigation/Tabs.vue'
import DataTable, { type SortState } from '@/Components/tables/DataTable.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import CrmSubNav from '@/Components/crm/CrmSubNav.vue'
import LeadKanbanBoard, { type LeadItem } from '@/Components/crm/LeadKanbanBoard.vue'
import ConvertLeadModal from '@/Components/crm/ConvertLeadModal.vue'
import DisqualifyLeadModal from '@/Components/crm/DisqualifyLeadModal.vue'

const props = defineProps<{
  leads: LeadItem[]
  roleTypes: Array<{ id: number; name: string }>
}>()

const view = ref<'board' | 'list'>('board')
const search = ref('')
const stageFilter = ref('')
const sort = ref<SortState>(null)

const leadHref = (item: LeadItem) => route('crm.leads.edit', item.id)

const onStageChange = (leadId: number, stage: string) => {
  router.patch(route('crm.leads.updateStage', leadId), { stage }, { preserveScroll: true })
}

// --- Convert / Disqualify modals (shared by board-drop and list-row actions) ---
const convertLead = ref<LeadItem | null>(null)
const disqualifyLead = ref<LeadItem | null>(null)

const openConvert = (leadId: number) => {
  convertLead.value = props.leads.find((l) => l.id === leadId) ?? null
}
const openDisqualify = (leadId: number) => {
  disqualifyLead.value = props.leads.find((l) => l.id === leadId) ?? null
}

// --- List view ---
const columns = [
  { key: 'name', label: 'Name', sortable: true },
  { key: 'company_name', label: 'Company' },
  { key: 'stage', label: 'Stage', sortable: true },
  { key: 'source_name', label: 'Source' },
  { key: 'owner_name', label: 'Owner' },
  { key: 'next_action_formatted', label: 'Next action', sortKey: 'next_action_at' },
  { key: 'estimated_value_formatted', label: 'Est. value' },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

const filteredLeads = computed(() => {
  let list = props.leads
  if (search.value) {
    const q = search.value.toLowerCase()
    list = list.filter((l) => l.name.toLowerCase().includes(q) || (l.company_name ?? '').toLowerCase().includes(q))
  }
  if (stageFilter.value) {
    list = list.filter((l) => l.stage === stageFilter.value)
  }
  if (sort.value) {
    const { key, direction } = sort.value
    list = [...list].sort((a, b) => {
      const av = String((a as unknown as Record<string, unknown>)[key] ?? '')
      const bv = String((b as unknown as Record<string, unknown>)[key] ?? '')
      return direction === 'asc' ? av.localeCompare(bv) : bv.localeCompare(av)
    })
  }
  return list
})

const isOpen = (lead: LeadItem) => lead.stage !== 'converted' && lead.stage !== 'disqualified'
</script>

<template>
  <AppLayout>
    <PageHeader
      title="Leads"
      description="Pre-partner interest, qualified through a pipeline before becoming a Partner."
    >
      <template #actions>
        <PrimaryButton :href="route('crm.leads.create')">Add lead</PrimaryButton>
      </template>
    </PageHeader>

    <CrmSubNav active="leads" class="mt-6" />

    <div class="mt-6">
      <Tabs v-model="view" :tabs="[{ key: 'board', label: 'Board' }, { key: 'list', label: 'List' }]" />
    </div>

    <div v-if="view === 'board'" class="mt-4">
      <LeadKanbanBoard
        :items="leads"
        :item-href="leadHref"
        @stage-change="onStageChange"
        @request-convert="openConvert"
        @request-disqualify="openDisqualify"
      />
    </div>

    <div v-else class="mt-4">
      <DataTable
        :columns="columns"
        :items="filteredLeads"
        v-model:sort="sort"
        v-model:search="search"
        search-placeholder="Search name or company…"
        status-rail-key="stage"
        empty-title="No leads yet"
        empty-description="Add your first lead to start the pipeline."
      >
        <template #cell-name="{ item }">
          <Link :href="route('crm.leads.edit', item.id)" class="text-sm font-medium text-accent hover:underline">
            {{ item.name }}
          </Link>
        </template>
        <template #cell-stage="{ item }">
          <StatusBadge :status="(item as LeadItem).stage" />
        </template>
        <template #cell-next_action_formatted="{ item }">
          <span :class="(item as LeadItem).is_overdue ? 'font-semibold text-signal-danger' : 'text-ink-900'">
            {{ item.next_action_formatted ?? '—' }}
          </span>
        </template>
        <template #cell-actions="{ item }">
          <div class="flex items-center justify-end gap-3">
            <Link
              :href="route('crm.leads.edit', item.id)"
              class="text-sm font-medium text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
            >
              Edit
            </Link>
            <template v-if="isOpen(item as LeadItem)">
              <button
                type="button"
                class="text-sm font-medium text-signal-success hover:underline"
                @click="openConvert((item as LeadItem).id)"
              >
                Convert
              </button>
              <button
                type="button"
                class="text-sm font-medium text-signal-danger hover:underline"
                @click="openDisqualify((item as LeadItem).id)"
              >
                Disqualify
              </button>
            </template>
          </div>
        </template>
      </DataTable>
    </div>

    <ConvertLeadModal
      :show="convertLead !== null"
      :lead-id="convertLead?.id ?? null"
      :lead-name="convertLead?.name ?? ''"
      :has-company-name="!!convertLead?.company_name"
      :role-types="roleTypes"
      @close="convertLead = null"
    />
    <DisqualifyLeadModal
      :show="disqualifyLead !== null"
      :lead-id="disqualifyLead?.id ?? null"
      :lead-name="disqualifyLead?.name ?? ''"
      @close="disqualifyLead = null"
    />
  </AppLayout>
</template>
