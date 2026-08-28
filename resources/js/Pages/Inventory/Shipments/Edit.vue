<!-- ponytail: Shipment edit/view (§3P) — editable while pending; ship-confirm is the dedicated
     irreversible action (triggers the real Goods Issue, §3E); shipped/delivered is read-only. -->
<script setup lang="ts">
import { useForm, Link, router } from '@inertiajs/vue3'
import { computed } from 'vue'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import InventorySubNav from '@/Components/inventory/InventorySubNav.vue'
import ShipmentPackageSelector, { type EligiblePackList } from '@/Components/inventory/ShipmentPackageSelector.vue'
import { useConfirm } from '@/Composables/useConfirmDialog'

const props = defineProps<{
  shipment: {
    id: number
    warehouse_id: number
    carrier: string | null
    tracking_number: string | null
    ship_date: string | null
    status: 'pending' | 'shipped' | 'delivered'
    goods_issue_id: number | null
    pack_list_ids: number[]
  }
  warehouses: Array<{ id: number; name: string }>
  eligiblePackLists: EligiblePackList[]
}>()

const isPending = computed(() => props.shipment.status === 'pending')
const warehouseName = computed(() => props.warehouses.find((w) => w.id === props.shipment.warehouse_id)?.name ?? '')

const form = useForm({
  carrier: props.shipment.carrier ?? '',
  tracking_number: props.shipment.tracking_number ?? '',
  ship_date: props.shipment.ship_date ?? '',
  pack_list_ids: [...props.shipment.pack_list_ids],
})

const submit = () => form.put(route('inventory.shipments.update', props.shipment.id))

const { confirm } = useConfirm()

const confirmShipConfirm = () => {
  confirm({
    title: 'Ship-confirm this shipment?',
    description: 'This posts a Goods Issue and deducts stock for every packaged line — it can no longer be edited afterward.',
    confirmText: 'Ship-confirm',
    onConfirm: () => router.patch(route('inventory.shipments.shipConfirm', props.shipment.id)),
  })
}

const confirmDeliver = () => {
  confirm({
    title: 'Mark this shipment delivered?',
    confirmText: 'Mark delivered',
    onConfirm: () => router.patch(route('inventory.shipments.deliver', props.shipment.id)),
  })
}

const confirmDelete = () => {
  confirm({
    title: `Delete shipment #${props.shipment.id}?`,
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () => router.delete(route('inventory.shipments.destroy', props.shipment.id)),
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader :title="`Shipment #${shipment.id}`" :description="warehouseName">
      <template #actions>
        <StatusBadge :status="shipment.status" />
        <Link :href="route('inventory.shipments.index')" class="text-sm font-medium text-accent hover:underline">Back</Link>
      </template>
    </PageHeader>

    <InventorySubNav active="shipments" class="mt-6" />

    <Panel class="mt-6 max-w-3xl">
      <p v-if="shipment.goods_issue_id" class="mb-4 text-sm text-ink-600">
        Goods Issue #{{ shipment.goods_issue_id }} was posted on ship-confirm.
      </p>

      <form class="space-y-4" @submit.prevent="submit">
        <div class="grid grid-cols-2 gap-4">
          <FormInput v-model="form.carrier" name="carrier" label="Carrier" :error="form.errors.carrier" :disabled="!isPending" />
          <FormInput v-model="form.tracking_number" name="tracking_number" label="Tracking number" :error="form.errors.tracking_number" :disabled="!isPending" />
        </div>
        <FormInput v-model="form.ship_date" name="ship_date" type="date" label="Ship date" :error="form.errors.ship_date" :disabled="!isPending" />

        <div>
          <p class="mb-2 text-sm font-medium text-ink-900">Packages</p>
          <ShipmentPackageSelector v-if="isPending" v-model="form.pack_list_ids" :eligible-pack-lists="eligiblePackLists" />
          <ul v-else class="space-y-1 text-sm text-ink-600">
            <li v-for="id in shipment.pack_list_ids" :key="id">Package #{{ id }}</li>
          </ul>
          <p v-if="form.errors.pack_list_ids" class="mt-2 text-sm text-signal-danger">{{ form.errors.pack_list_ids }}</p>
        </div>

        <div class="flex items-center justify-between border-t border-border pt-4">
          <button v-if="isPending" type="button" class="text-sm font-medium text-signal-danger hover:underline" @click="confirmDelete">
            Delete shipment
          </button>
          <div v-else />
          <div class="flex items-center gap-3">
            <template v-if="isPending">
              <PrimaryButton type="submit" :disabled="form.processing || form.pack_list_ids.length === 0">Save</PrimaryButton>
              <button
                type="button"
                class="inline-flex items-center justify-center rounded-sm bg-signal-success px-3 py-2 text-sm font-semibold text-white shadow-sm transition hover:opacity-90 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
                @click="confirmShipConfirm"
              >
                Ship-confirm
              </button>
            </template>
            <button
              v-else-if="shipment.status === 'shipped'"
              type="button"
              class="inline-flex items-center justify-center rounded-sm bg-signal-success px-3 py-2 text-sm font-semibold text-white shadow-sm transition hover:opacity-90 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
              @click="confirmDeliver"
            >
              Mark delivered
            </button>
          </div>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
