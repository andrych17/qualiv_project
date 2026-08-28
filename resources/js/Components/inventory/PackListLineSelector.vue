<!-- ponytail: §3P pack-list contents picker — checkbox + qty per still-unpacked picked line,
     shared by Create/Edit since both need the exact same "select from what's left to pack" UI. -->
<script setup lang="ts">
import { computed } from 'vue'

export interface AvailablePackLine {
  id: number
  product_sku: string | null
  product_name: string | null
  batch_number: string | null
  serial_number: string | null
  confirmed_qty: number
  remaining_qty: number
}

export interface PackLineRow {
  pick_list_line_id: number
  qty: number
}

const props = defineProps<{
  modelValue: PackLineRow[]
  availableLines: AvailablePackLine[]
}>()

const emit = defineEmits<{ 'update:modelValue': [PackLineRow[]] }>()

const isChecked = (lineId: number) => props.modelValue.some((r) => r.pick_list_line_id === lineId)

const qtyFor = (lineId: number) => props.modelValue.find((r) => r.pick_list_line_id === lineId)?.qty ?? 0

const toggle = (line: AvailablePackLine, checked: boolean) => {
  if (checked) {
    emit('update:modelValue', [...props.modelValue, { pick_list_line_id: line.id, qty: line.remaining_qty }])
  } else {
    emit('update:modelValue', props.modelValue.filter((r) => r.pick_list_line_id !== line.id))
  }
}

const setQty = (lineId: number, qty: number) => {
  emit('update:modelValue', props.modelValue.map((r) => (r.pick_list_line_id === lineId ? { ...r, qty } : r)))
}

const hasLines = computed(() => props.availableLines.length > 0)
</script>

<template>
  <div class="space-y-2">
    <p v-if="!hasLines" class="text-sm text-ink-600">No unpacked picked lines remain on this pick list.</p>
    <div
      v-for="line in availableLines"
      :key="line.id"
      class="flex items-center gap-3 rounded-md border border-border p-3"
    >
      <input
        type="checkbox"
        :checked="isChecked(line.id)"
        class="h-4 w-4 rounded border-border text-accent focus:ring-accent"
        @change="toggle(line, ($event.target as HTMLInputElement).checked)"
      />
      <div class="flex-1">
        <p class="text-sm font-semibold text-ink-900">{{ line.product_sku }} — {{ line.product_name }}</p>
        <p v-if="line.batch_number" class="text-xs text-ink-600">Lot {{ line.batch_number }}</p>
        <p v-if="line.serial_number" class="text-xs text-ink-600">Serial {{ line.serial_number }}</p>
        <p class="text-xs text-ink-600">{{ line.remaining_qty }} of {{ line.confirmed_qty }} unpacked</p>
      </div>
      <input
        v-if="isChecked(line.id)"
        type="number"
        step="0.0001"
        min="0.0001"
        :max="line.remaining_qty"
        :value="qtyFor(line.id)"
        class="w-28 rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm text-ink-900 shadow-sm focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/20"
        @input="setQty(line.id, Number(($event.target as HTMLInputElement).value))"
      />
    </div>
  </div>
</template>
