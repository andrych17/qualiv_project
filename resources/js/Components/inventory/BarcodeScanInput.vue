<!-- ponytail: shared scan input (§3K) — a plain text field an HID scanner or a mobile camera
     app can type into, resolved on Enter via BarcodeScanController. Auto-clears after every
     scan so the operator can keep scanning without touching the mouse. -->
<script setup lang="ts">
import { ref } from 'vue'
import axios from 'axios'
import { ScanLine, Loader2 } from 'lucide-vue-next'

export interface ProductScanResult {
  found: true
  product_id: number
  sku: string
  name: string
  uom_id: number
  unit_multiplier: number
}

export interface LocationScanResult {
  found: true
  location_id: number
  code: string
  warehouse_id: number
}

const props = withDefaults(
  defineProps<{
    context: 'product' | 'location'
    warehouseId?: number | null
    disabled?: boolean
    placeholder?: string
  }>(),
  { placeholder: 'Scan or type a barcode, then press Enter…' },
)

const emit = defineEmits<{
  resolved: [result: ProductScanResult | LocationScanResult]
  notFound: [code: string]
}>()

const code = ref('')
const loading = ref(false)
const inputRef = ref<HTMLInputElement | null>(null)

const scan = async () => {
  const value = code.value.trim()
  if (!value || loading.value) return

  loading.value = true
  try {
    const { data } = await axios.get(route('inventory.barcodeScan.resolve'), {
      params: { code: value, context: props.context, warehouse_id: props.warehouseId ?? undefined },
    })
    emit('resolved', data)
  } catch (err: any) {
    if (err?.response?.status === 404) {
      emit('notFound', value)
    }
  } finally {
    loading.value = false
    code.value = ''
    inputRef.value?.focus()
  }
}
</script>

<template>
  <div class="flex items-center gap-2 rounded-md border border-dashed border-border bg-surface-50 px-3 py-2">
    <ScanLine class="h-4 w-4 shrink-0 text-ink-600" />
    <input
      ref="inputRef"
      v-model="code"
      type="text"
      :placeholder="placeholder"
      :disabled="disabled"
      class="w-full bg-transparent text-sm text-ink-900 outline-none placeholder:text-ink-600 disabled:text-ink-600"
      @keydown.enter.prevent="scan"
    />
    <Loader2 v-if="loading" class="h-4 w-4 shrink-0 animate-spin text-accent" />
  </div>
</template>
