<!-- ponytail: Edit Transfer (§3F) — draft is freely editable; in_transit/completed are
     read-only (the ledger already reflects the movement once posted). -->
<script setup lang="ts">
import { useForm, Link, router } from '@inertiajs/vue3'
import { computed } from 'vue'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import TransferLineListInput, { type TransferLineRow } from '@/Components/inventory/TransferLineListInput.vue'
import InventorySubNav from '@/Components/inventory/InventorySubNav.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import { useConfirm } from '@/Composables/useConfirmDialog'

const props = defineProps<{
  transfer: {
    id: number
    source_warehouse_id: number
    source_location_id: number
    destination_warehouse_id: number
    destination_location_id: number
    transfer_date: string
    status: string
    lines: TransferLineRow[]
  }
  warehouses: Array<{ id: number; name: string }>
  uoms: Array<{ id: number; code: string; name: string }>
  locations: Array<{ id: number; warehouse_id: number; code: string }>
  productTracking: Record<number, string>
}>()

const isDraft = computed(() => props.transfer.status === 'draft')
const isInTransit = computed(() => props.transfer.status === 'in_transit')

const form = useForm({
  source_warehouse_id: props.transfer.source_warehouse_id,
  source_location_id: props.transfer.source_location_id,
  destination_warehouse_id: props.transfer.destination_warehouse_id,
  destination_location_id: props.transfer.destination_location_id,
  transfer_date: props.transfer.transfer_date,
  lines: props.transfer.lines.map((l) => ({ ...l })),
})

const sourceLocationOptions = computed(() =>
  props.locations.filter((l) => Number(l.warehouse_id) === Number(form.source_warehouse_id)).map((l) => ({ label: l.code, value: l.id })),
)
const destinationLocationOptions = computed(() =>
  props.locations.filter((l) => Number(l.warehouse_id) === Number(form.destination_warehouse_id)).map((l) => ({ label: l.code, value: l.id })),
)

const submit = () => form.put(route('inventory.transfers.update', props.transfer.id))

const { confirm } = useConfirm()
const confirmPost = () => {
  confirm({
    title: 'Post this transfer?',
    description: 'This moves the stock and cost basis now — same-warehouse transfers complete immediately, cross-warehouse ones go in-transit.',
    confirmText: 'Post',
    onConfirm: () => router.patch(route('inventory.transfers.post', props.transfer.id)),
  })
}
const confirmComplete = () => {
  confirm({
    title: 'Mark this transfer completed?',
    description: 'Confirms physical receipt at the destination — the stock already moved when this was posted.',
    confirmText: 'Mark completed',
    onConfirm: () => router.patch(route('inventory.transfers.complete', props.transfer.id)),
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader :title="isDraft ? 'Edit Transfer' : 'Transfer'">
      <template #actions>
        <StatusBadge :status="transfer.status" />
      </template>
    </PageHeader>

    <InventorySubNav active="transfers" class="mt-6" />

    <Panel class="mt-6 max-w-4xl">
      <form class="space-y-4" @submit.prevent="submit">
        <FormInput v-model="form.transfer_date" name="transfer_date" type="date" label="Transfer date" :error="form.errors.transfer_date" :disabled="!isDraft" required />

        <div class="grid grid-cols-2 gap-4 rounded-md border border-border p-3">
          <div class="space-y-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-ink-600">Source</p>
            <FormSelect
              v-model="form.source_warehouse_id"
              name="source_warehouse_id"
              label="Warehouse"
              :options="warehouses.map((w) => ({ label: w.name, value: w.id }))"
              :error="form.errors.source_warehouse_id"
              :disabled="!isDraft"
              required
            />
            <FormSelect
              v-model="form.source_location_id"
              name="source_location_id"
              label="Location"
              placeholder="Choose a bin…"
              :options="sourceLocationOptions"
              :error="form.errors.source_location_id"
              :disabled="!isDraft"
              required
            />
          </div>
          <div class="space-y-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-ink-600">Destination</p>
            <FormSelect
              v-model="form.destination_warehouse_id"
              name="destination_warehouse_id"
              label="Warehouse"
              :options="warehouses.map((w) => ({ label: w.name, value: w.id }))"
              :error="form.errors.destination_warehouse_id"
              :disabled="!isDraft"
              required
            />
            <FormSelect
              v-model="form.destination_location_id"
              name="destination_location_id"
              label="Location"
              placeholder="Choose a bin…"
              :options="destinationLocationOptions"
              :error="form.errors.destination_location_id"
              :disabled="!isDraft"
              required
            />
          </div>
        </div>

        <TransferLineListInput v-model="form.lines" :uoms="uoms" :product-tracking="productTracking" :disabled="!isDraft" />
        <p v-if="form.errors.lines" class="text-sm text-signal-danger">{{ form.errors.lines }}</p>

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <Link
            :href="route('inventory.transfers.index')"
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
          <button
            v-else-if="isInTransit"
            type="button"
            class="inline-flex items-center justify-center rounded-sm bg-signal-success px-3 py-2 text-sm font-semibold text-white shadow-sm transition hover:opacity-90 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
            @click="confirmComplete"
          >
            Mark completed
          </button>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
