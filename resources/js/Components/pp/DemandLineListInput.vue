<!-- ponytail: repeatable manual demand lines (PP_SPECS.md §3B) -->
<script setup lang="ts">
import { Plus, Trash2 } from 'lucide-vue-next'
import FormAsyncSearchableSelect from '@/Components/forms/FormAsyncSearchableSelect.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormNumberInput from '@/Components/forms/FormNumberInput.vue'

export interface DemandLineRow {
  product_id: number | null
  need_by_date: string
  qty: number
}

const props = defineProps<{
  modelValue: DemandLineRow[]
  disabled?: boolean
}>()

const emit = defineEmits<{
  'update:modelValue': [value: DemandLineRow[]]
}>()

const emptyRow = (): DemandLineRow => ({ product_id: null, need_by_date: new Date().toISOString().slice(0, 10), qty: 0 })

const addRow = () => emit('update:modelValue', [...props.modelValue, emptyRow()])
const removeRow = (index: number) => emit('update:modelValue', props.modelValue.filter((_, i) => i !== index))
const update = (index: number, patch: Partial<DemandLineRow>) => {
  const rows = props.modelValue.map((row, i) => (i === index ? { ...row, ...patch } : row))
  emit('update:modelValue', rows)
}
</script>

<template>
  <div class="space-y-3">
    <div class="flex items-center justify-between">
      <p class="text-xs font-semibold uppercase tracking-wide text-ink-600">Lines</p>
      <button
        v-if="!disabled"
        type="button"
        class="inline-flex items-center gap-1 text-xs font-medium text-accent hover:underline"
        @click="addRow"
      >
        <Plus class="h-3.5 w-3.5" /> Add line
      </button>
    </div>

    <div v-if="modelValue.length === 0" class="text-sm text-ink-600">No lines yet.</div>

    <div
      v-for="(row, index) in modelValue"
      :key="index"
      class="grid grid-cols-[2fr_1fr_1fr_auto] items-end gap-3 rounded-md border border-border p-3"
    >
      <FormAsyncSearchableSelect
        :model-value="row.product_id"
        :name="`lines.${index}.product_id`"
        label="Product"
        api-entity="inventory_product"
        placeholder="Search SKU or name…"
        :disabled="disabled"
        @update:model-value="(value) => update(index, { product_id: Number(value) })"
      />

      <FormInput
        :model-value="row.need_by_date"
        :name="`lines.${index}.need_by_date`"
        label="Need-by date"
        type="date"
        :disabled="disabled"
        @update:model-value="(value) => update(index, { need_by_date: String(value) })"
      />

      <FormNumberInput
        :model-value="row.qty"
        :name="`lines.${index}.qty`"
        label="Qty"
        :decimals="4"
        :disabled="disabled"
        @update:model-value="(value) => update(index, { qty: value ?? 0 })"
      />

      <button v-if="!disabled" type="button" class="text-signal-danger" @click="removeRow(index)">
        <Trash2 class="h-4 w-4" />
      </button>
    </div>
  </div>
</template>
