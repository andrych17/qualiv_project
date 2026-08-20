<!-- ponytail: Partner Merge / Dedup review queue (§3G) — detection surfaces candidates,
     admin always picks the survivor explicitly; nothing merges automatically. -->
<script setup lang="ts">
import { ref } from 'vue'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormAsyncSearchableSelect from '@/Components/forms/FormAsyncSearchableSelect.vue'
import CrmSubNav from '@/Components/crm/CrmSubNav.vue'
import MergeConfirmModal from '@/Components/crm/MergeConfirmModal.vue'

interface CandidatePartner {
  id: number
  name: string
  type: string
  trade_name: string | null
  registration_tax_id: string | null
}

interface CandidateGroup {
  reason: string
  partners: CandidatePartner[]
}

const props = defineProps<{
  groups: CandidateGroup[]
}>()

// One chosen survivor id per group, defaulting to the first (usually oldest) row.
const survivorByGroup = ref<Record<number, number>>(
  Object.fromEntries(props.groups.map((g, i) => [i, g.partners[0]?.id])),
)

const showConfirm = ref(false)
const pendingSurvivor = ref<{ id: number; name: string } | null>(null)
const pendingLoser = ref<{ id: number; name: string } | null>(null)

const requestMerge = (survivor: CandidatePartner, loser: CandidatePartner) => {
  pendingSurvivor.value = { id: survivor.id, name: survivor.name }
  pendingLoser.value = { id: loser.id, name: loser.name }
  showConfirm.value = true
}

// Manual picker, for a duplicate the automatic scan didn't catch.
const manualSurvivorId = ref<number | null>(null)
const manualSurvivorLabel = ref<string>('')
const manualLoserId = ref<number | null>(null)
const manualLoserLabel = ref<string>('')

const requestManualMerge = () => {
  if (!manualSurvivorId.value || !manualLoserId.value) return
  requestMerge(
    { id: manualSurvivorId.value, name: manualSurvivorLabel.value, type: '', trade_name: null, registration_tax_id: null },
    { id: manualLoserId.value, name: manualLoserLabel.value, type: '', trade_name: null, registration_tax_id: null },
  )
}
</script>

<template>
  <AppLayout>
    <PageHeader
      title="Merge / Deduplication"
      description="Keep the partner registry clean without ever silently losing data."
    />

    <CrmSubNav active="merge" class="mt-6" />

    <div class="mt-6 max-w-3xl space-y-6">
      <Panel v-if="groups.length === 0" title="Review queue">
        <p class="text-sm text-ink-600">No likely duplicates detected right now.</p>
      </Panel>

      <Panel
        v-for="(group, gi) in groups"
        :key="gi"
        :title="`${group.reason} (${group.partners.length})`"
      >
        <div class="space-y-2">
          <div
            v-for="p in group.partners"
            :key="p.id"
            class="flex items-center justify-between rounded-md border border-border px-3 py-2"
          >
            <label class="flex items-center gap-2 text-sm">
              <input
                type="radio"
                :name="`survivor-${gi}`"
                :value="p.id"
                v-model="survivorByGroup[gi]"
                class="text-accent focus:ring-accent"
              />
              <span class="font-medium text-ink-900">{{ p.name }}</span>
              <span class="text-xs text-ink-600">{{ p.type }}{{ p.registration_tax_id ? ' · ' + p.registration_tax_id : '' }}</span>
            </label>
            <button
              v-if="p.id !== survivorByGroup[gi]"
              type="button"
              class="text-sm font-medium text-accent hover:underline"
              @click="requestMerge(
                group.partners.find((x) => x.id === survivorByGroup[gi])!,
                p,
              )"
            >
              Merge into selected
            </button>
          </div>
          <p class="text-xs text-ink-600">Pick which record survives, then merge each other one into it.</p>
        </div>
      </Panel>

      <Panel title="Merge two specific partners">
        <p class="mb-3 text-xs text-ink-600">For a duplicate the automatic scan above didn't catch.</p>
        <div class="space-y-3">
          <FormAsyncSearchableSelect
            v-model="manualSurvivorId"
            name="manual_survivor"
            label="Keep (survivor)"
            api-entity="crm_partner"
            placeholder="Search…"
            @select="(opt) => (manualSurvivorLabel = opt?.label ?? '')"
          />
          <FormAsyncSearchableSelect
            v-model="manualLoserId"
            name="manual_loser"
            label="Merge away (loser)"
            api-entity="crm_partner"
            placeholder="Search…"
            @select="(opt) => (manualLoserLabel = opt?.label ?? '')"
          />
          <button
            type="button"
            class="rounded-md border border-accent/40 bg-accent/5 px-3 py-2 text-sm font-semibold text-accent hover:bg-accent/10 disabled:cursor-not-allowed disabled:opacity-50"
            :disabled="!manualSurvivorId || !manualLoserId || manualSurvivorId === manualLoserId"
            @click="requestManualMerge"
          >
            Review merge
          </button>
        </div>
      </Panel>
    </div>

    <MergeConfirmModal
      :show="showConfirm"
      :survivor="pendingSurvivor"
      :loser="pendingLoser"
      @close="showConfirm = false"
    />
  </AppLayout>
</template>
