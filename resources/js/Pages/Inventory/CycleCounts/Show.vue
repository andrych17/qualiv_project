<!-- ponytail: Cycle Count working view (§3Q) — the mobile-friendly scan-to-count workspace.
     "Complete count" only appears once every line is counted, and drafts Adjustment(s) for
     review — it never posts stock on its own. -->
<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3'
import { computed } from 'vue'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import InventorySubNav from '@/Components/inventory/InventorySubNav.vue'
import CycleCountLineCard, { type CycleCountLineData } from '@/Components/inventory/CycleCountLineCard.vue'
import { useConfirm } from '@/Composables/useConfirmDialog'

const props = defineProps<{
  count: {
    id: number
    warehouse_id: number
    warehouse_name: string | null
    scope: string
    status: 'pending' | 'in_progress' | 'completed'
    assigned_to: number | null
    scheduled_date_formatted: string | null
    completed_at_formatted: string | null
  }
  lines: CycleCountLineData[]
  assignees: Array<{ id: number; name: string }>
}>()

const isCompleted = computed(() => props.count.status === 'completed')
const allCounted = computed(() => props.lines.every((l) => l.status === 'counted'))

const updateAssignee = (value: string | number) => {
  router.patch(route('inventory.cycleCounts.assign', props.count.id), {
    assigned_to: value === '' ? null : Number(value),
  }, { preserveScroll: true })
}

const { confirm } = useConfirm()

const confirmComplete = () => {
  confirm({
    title: 'Complete this cycle count?',
    description: 'Drafts an Adjustment per counted location for any variance found — nothing posts to stock yet.',
    confirmText: 'Complete',
    onConfirm: () => router.patch(route('inventory.cycleCounts.complete', props.count.id)),
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader :title="`Cycle Count #${count.id}`" :description="`${count.warehouse_name} — ${count.scope}`">
      <template #actions>
        <Link :href="route('inventory.cycleCounts.index')" class="text-sm font-medium text-accent hover:underline">Back</Link>
      </template>
    </PageHeader>

    <InventorySubNav active="cycleCounts" class="mt-6" />

    <Panel class="mt-6 max-w-3xl">
      <div class="flex items-center justify-between gap-4">
        <div class="flex items-center gap-2">
          <StatusBadge :status="count.status" />
          <span v-if="count.scheduled_date_formatted" class="text-xs text-ink-600">Scheduled {{ count.scheduled_date_formatted }}</span>
          <span v-if="count.completed_at_formatted" class="text-xs text-ink-600">Completed {{ count.completed_at_formatted }}</span>
        </div>
        <div class="flex items-center gap-3">
          <div class="w-56">
            <FormSelect
              :model-value="count.assigned_to"
              name="assigned_to"
              placeholder="Unassigned"
              :options="assignees.map((a) => ({ label: a.name, value: a.id }))"
              :disabled="isCompleted"
              @update:model-value="updateAssignee"
            />
          </div>
          <PrimaryButton v-if="!isCompleted && allCounted" @click="confirmComplete">Complete count</PrimaryButton>
        </div>
      </div>
    </Panel>

    <div class="mt-6 max-w-3xl space-y-3">
      <CycleCountLineCard
        v-for="line in lines"
        :key="line.id"
        :line="line"
        :cycle-count-id="count.id"
        :warehouse-id="count.warehouse_id"
        :disabled="isCompleted"
      />
    </div>
  </AppLayout>
</template>
