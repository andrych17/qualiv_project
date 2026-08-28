<!-- ponytail: Pick List working view (§3O) — the mobile-friendly scan-to-pick workspace. -->
<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import InventorySubNav from '@/Components/inventory/InventorySubNav.vue'
import PickListLineCard, { type PickListLineData } from '@/Components/inventory/PickListLineCard.vue'

const props = defineProps<{
  pickList: {
    id: number
    warehouse_id: number
    warehouse_name: string | null
    status: 'pending' | 'in_progress' | 'ready_for_packing'
    assigned_to: number | null
    created_at_formatted: string | null
    completed_at_formatted: string | null
  }
  lines: PickListLineData[]
  assignees: Array<{ id: number; name: string }>
}>()

const isComplete = props.pickList.status === 'ready_for_packing'

const updateAssignee = (value: string | number) => {
  router.patch(route('inventory.pickLists.assign', props.pickList.id), {
    assigned_to: value === '' ? null : Number(value),
  }, { preserveScroll: true })
}
</script>

<template>
  <AppLayout>
    <PageHeader :title="`Pick List #${pickList.id}`" :description="`${pickList.warehouse_name} — created ${pickList.created_at_formatted}`">
      <template #actions>
        <Link
          v-if="lines.some((l) => l.status === 'picked')"
          :href="route('inventory.packLists.create', { pick_list_id: pickList.id })"
          class="text-sm font-medium text-accent hover:underline"
        >
          Create package
        </Link>
        <Link :href="route('inventory.pickLists.index')" class="text-sm font-medium text-accent hover:underline">Back</Link>
      </template>
    </PageHeader>

    <InventorySubNav active="pickLists" class="mt-6" />

    <Panel class="mt-6 max-w-3xl">
      <div class="flex items-center justify-between gap-4">
        <div class="flex items-center gap-2">
          <StatusBadge :status="pickList.status" />
          <span v-if="pickList.completed_at_formatted" class="text-xs text-ink-600">Completed {{ pickList.completed_at_formatted }}</span>
        </div>
        <div class="w-56">
          <FormSelect
            :model-value="pickList.assigned_to"
            name="assigned_to"
            placeholder="Unassigned"
            :options="assignees.map((a) => ({ label: a.name, value: a.id }))"
            :disabled="isComplete"
            @update:model-value="updateAssignee"
          />
        </div>
      </div>
    </Panel>

    <div class="mt-6 max-w-3xl space-y-3">
      <PickListLineCard
        v-for="line in lines"
        :key="line.id"
        :line="line"
        :pick-list-id="pickList.id"
        :warehouse-id="pickList.warehouse_id"
      />
    </div>
  </AppLayout>
</template>
