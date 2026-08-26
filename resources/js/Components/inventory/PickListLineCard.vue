<!-- ponytail: §3O mobile-friendly scan-to-pick — "scan location barcode → scan product
     barcode → confirm quantity → line marked picked", one gate at a time so a mis-scan can't
     silently pick the wrong line. -->
<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { CheckCircle2 } from 'lucide-vue-next'
import BarcodeScanInput, { type ProductScanResult, type LocationScanResult } from '@/Components/inventory/BarcodeScanInput.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'

export interface PickListLineData {
  id: number
  product_id: number
  product_sku: string | null
  product_name: string | null
  location_id: number
  location_code: string | null
  batch_number: string | null
  serial_number: string | null
  qty: number
  confirmed_qty: number | null
  status: 'pending' | 'picked'
  picked_at_formatted: string | null
  picked_by_name: string | null
}

const props = defineProps<{
  line: PickListLineData
  pickListId: number
  warehouseId: number
  disabled?: boolean
}>()

const locationVerified = ref(false)
const productVerified = ref(false)
const confirmedQty = ref(props.line.qty)
const errorMessage = ref<string | null>(null)
const submitting = ref(false)

const onLocationScanned = (result: ProductScanResult | LocationScanResult) => {
  if (!('location_id' in result)) return
  if (result.location_id === props.line.location_id) {
    errorMessage.value = null
    locationVerified.value = true
  } else {
    errorMessage.value = `Scanned "${result.code}" — this line is at ${props.line.location_code}.`
  }
}

const onProductScanned = (result: ProductScanResult | LocationScanResult) => {
  if (!('product_id' in result)) return
  if (result.product_id === props.line.product_id) {
    errorMessage.value = null
    productVerified.value = true
  } else {
    errorMessage.value = `Scanned "${result.sku}" — this line is for ${props.line.product_sku}.`
  }
}

const onNotFound = (code: string) => {
  errorMessage.value = `No match for "${code}".`
}

const confirmPick = () => {
  submitting.value = true
  router.patch(
    route('inventory.pickLists.pickLine', { pickList: props.pickListId, line: props.line.id }),
    { confirmed_qty: confirmedQty.value },
    { onFinish: () => { submitting.value = false } },
  )
}
</script>

<template>
  <div class="rounded-md border border-border p-4" :class="line.status === 'picked' ? 'bg-surface-50' : 'bg-surface-0'">
    <div class="flex items-start justify-between gap-3">
      <div>
        <p class="font-mono text-xs text-ink-600">{{ line.location_code }}</p>
        <p class="text-sm font-semibold text-ink-900">{{ line.product_sku }} — {{ line.product_name }}</p>
        <p v-if="line.batch_number" class="text-xs text-ink-600">Lot {{ line.batch_number }}</p>
        <p v-if="line.serial_number" class="text-xs text-ink-600">Serial {{ line.serial_number }}</p>
        <p class="text-xs text-ink-600">Qty: {{ line.qty }}</p>
      </div>
      <StatusBadge v-if="line.status === 'picked'" status="picked" />
    </div>

    <div v-if="line.status === 'picked'" class="mt-2 flex items-center gap-1.5 text-xs text-signal-success">
      <CheckCircle2 class="h-3.5 w-3.5" />
      Picked {{ line.confirmed_qty }} — {{ line.picked_at_formatted }} by {{ line.picked_by_name ?? '—' }}
    </div>

    <div v-else class="mt-3 space-y-2">
      <p v-if="errorMessage" class="text-sm text-signal-danger">{{ errorMessage }}</p>

      <BarcodeScanInput
        v-if="!locationVerified"
        context="location"
        :warehouse-id="warehouseId"
        placeholder="1. Scan this line's location…"
        :disabled="disabled"
        @resolved="onLocationScanned"
        @not-found="onNotFound"
      />
      <BarcodeScanInput
        v-else-if="!productVerified"
        context="product"
        placeholder="2. Scan the product…"
        :disabled="disabled"
        @resolved="onProductScanned"
        @not-found="onNotFound"
      />
      <div v-else class="flex items-center gap-2">
        <input
          v-model.number="confirmedQty"
          type="number"
          step="0.0001"
          min="0.0001"
          :max="line.qty"
          class="w-28 rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm text-ink-900 shadow-sm focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/20"
        />
        <PrimaryButton :disabled="submitting || disabled" @click="confirmPick">Confirm pick</PrimaryButton>
      </div>
    </div>
  </div>
</template>
