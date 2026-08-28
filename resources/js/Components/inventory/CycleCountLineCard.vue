<!-- ponytail: §3Q mobile-friendly scan-to-count — scan location, scan product, confirm counted
     qty, same one-gate-at-a-time shape as PickListLineCard so a mis-scan can't silently count
     the wrong line. Variance (counted - system) shown live once counted. -->
<script setup lang="ts">
import { ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { CheckCircle2 } from 'lucide-vue-next'
import BarcodeScanInput, { type ProductScanResult, type LocationScanResult } from '@/Components/inventory/BarcodeScanInput.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'

export interface CycleCountLineData {
  id: number
  product_id: number
  product_sku: string | null
  product_name: string | null
  location_id: number
  location_code: string | null
  batch_number: string | null
  system_qty: number | null
  counted_qty: number | null
  status: 'pending' | 'counted'
  counted_at_formatted: string | null
}

const props = defineProps<{
  line: CycleCountLineData
  cycleCountId: number
  warehouseId: number
  disabled?: boolean
}>()

const locationVerified = ref(false)
const productVerified = ref(false)
const countedQty = ref(props.line.system_qty ?? 0)
const errorMessage = ref<string | null>(null)
const submitting = ref(false)

const variance = computed(() => (props.line.counted_qty !== null && props.line.system_qty !== null ? props.line.counted_qty - props.line.system_qty : null))

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

const confirmCount = () => {
  submitting.value = true
  router.patch(
    route('inventory.cycleCounts.countLine', { cycleCount: props.cycleCountId, line: props.line.id }),
    { counted_qty: countedQty.value },
    { onFinish: () => { submitting.value = false } },
  )
}
</script>

<template>
  <div class="rounded-md border border-border p-4" :class="line.status === 'counted' ? 'bg-surface-50' : 'bg-surface-0'">
    <div class="flex items-start justify-between gap-3">
      <div>
        <p class="font-mono text-xs text-ink-600">{{ line.location_code }}</p>
        <p class="text-sm font-semibold text-ink-900">{{ line.product_sku }} — {{ line.product_name }}</p>
        <p v-if="line.batch_number" class="text-xs text-ink-600">Lot {{ line.batch_number }}</p>
        <p class="text-xs text-ink-600">System qty: {{ line.system_qty }}</p>
      </div>
    </div>

    <div v-if="line.status === 'counted'" class="mt-2 space-y-0.5 text-xs">
      <div class="flex items-center gap-1.5 text-signal-success">
        <CheckCircle2 class="h-3.5 w-3.5" />
        Counted {{ line.counted_qty }} — {{ line.counted_at_formatted }}
      </div>
      <p v-if="variance !== null" :class="variance === 0 ? 'text-ink-600' : 'text-signal-warning'">
        Variance: {{ variance > 0 ? '+' : '' }}{{ variance }}
      </p>
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
          v-model.number="countedQty"
          type="number"
          step="0.0001"
          min="0"
          class="w-28 rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm text-ink-900 shadow-sm focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/20"
        />
        <PrimaryButton :disabled="submitting || disabled" @click="confirmCount">Confirm count</PrimaryButton>
      </div>
    </div>
  </div>
</template>
