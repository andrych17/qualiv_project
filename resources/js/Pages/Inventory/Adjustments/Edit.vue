<!-- ponytail: Edit Adjustment (§3G) — draft is freely editable; posted is read-only and
     immutable (the ledger already reflects the variance). -->
<script setup lang="ts">
import { useForm, Link, router } from '@inertiajs/vue3'
import { computed } from 'vue'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import AdjustmentLineListInput, { type AdjustmentLineRow } from '@/Components/inventory/AdjustmentLineListInput.vue'
import InventorySubNav from '@/Components/inventory/InventorySubNav.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import { useConfirm } from '@/Composables/useConfirmDialog'

const props = defineProps<{
  adjustment: {
    id: number
    warehouse_id: number
    location_id: number
    adjustment_date: string
    reason_id: number
    reference: string | null
    status: string
    lines: AdjustmentLineRow[]
  }
  warehouses: Array<{ id: number; name: string }>
  locations: Array<{ id: number; warehouse_id: number; code: string }>
  reasons: Array<{ id: number; name: string }>
  productTracking: Record<number, string>
}>()

const isDraft = computed(() => props.adjustment.status === 'draft')

const form = useForm({
  warehouse_id: props.adjustment.warehouse_id,
  location_id: props.adjustment.location_id,
  adjustment_date: props.adjustment.adjustment_date,
  reason_id: props.adjustment.reason_id,
  reference: props.adjustment.reference ?? '',
  lines: props.adjustment.lines.map((l) => ({ ...l })),
})

const locationOptions = computed(() =>
  props.locations.filter((l) => Number(l.warehouse_id) === Number(form.warehouse_id)).map((l) => ({ label: l.code, value: l.id })),
)

const submit = () => form.put(route('inventory.adjustments.update', props.adjustment.id))

const { confirm } = useConfirm()
const confirmPost = () => {
  confirm({
    title: 'Post this adjustment?',
    description: 'This corrects on-hand quantity against each line\'s counted quantity — it can no longer be edited afterward.',
    confirmText: 'Post',
    onConfirm: () => router.patch(route('inventory.adjustments.post', props.adjustment.id)),
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader :title="isDraft ? 'Edit Adjustment' : 'Adjustment'">
      <template #actions>
        <StatusBadge :status="adjustment.status" />
      </template>
    </PageHeader>

    <InventorySubNav active="adjustments" class="mt-6" />

    <Panel class="mt-6 max-w-4xl">
      <form class="space-y-4" @submit.prevent="submit">
        <div class="grid grid-cols-2 gap-4">
          <FormSelect
            v-model="form.warehouse_id"
            name="warehouse_id"
            label="Warehouse"
            :options="warehouses.map((w) => ({ label: w.name, value: w.id }))"
            :error="form.errors.warehouse_id"
            :disabled="!isDraft"
            required
          />
          <FormSelect
            v-model="form.location_id"
            name="location_id"
            label="Location"
            placeholder="Choose a bin…"
            :options="locationOptions"
            :error="form.errors.location_id"
            :disabled="!isDraft"
            required
          />
        </div>
        <div class="grid grid-cols-2 gap-4">
          <FormInput v-model="form.adjustment_date" name="adjustment_date" type="date" label="Adjustment date" :error="form.errors.adjustment_date" :disabled="!isDraft" required />
          <FormSelect
            v-model="form.reason_id"
            name="reason_id"
            label="Reason"
            :options="reasons.map((r) => ({ label: r.name, value: r.id }))"
            :error="form.errors.reason_id"
            :disabled="!isDraft"
            required
          />
        </div>
        <FormInput v-model="form.reference" name="reference" label="Reference" :error="form.errors.reference" :disabled="!isDraft" />

        <AdjustmentLineListInput v-model="form.lines" :warehouse-id="form.warehouse_id" :location-id="form.location_id" :product-tracking="productTracking" :disabled="!isDraft" />
        <p v-if="form.errors.lines" class="text-sm text-signal-danger">{{ form.errors.lines }}</p>

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <Link
            :href="route('inventory.adjustments.index')"
            class="inline-flex items-center justify-center rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm font-semibold text-ink-900 shadow-sm transition hover:bg-surface-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            {{ isDraft ? 'Cancel' : 'Back' }}
          </Link>
          <template v-if="isDraft">
            <PrimaryButton type="submit" :disabled="form.processing">Save</PrimaryButton>
            <button
              type="button"
              class="inline-flex items-center justify-center rounded-sm bg-signal-success px-3 py-2 text-sm font-semibold text-white shadow-sm transition hover:opacity-90 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
              @click="confirmPost"
            >
              Post
            </button>
          </template>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
