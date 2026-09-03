<!-- ponytail: repeatable Recipe ingredient lines (PP_SPECS.md §3D) -->
<script setup lang="ts">
import { Plus, Trash2 } from 'lucide-vue-next'
import FormAsyncSearchableSelect from '@/Components/forms/FormAsyncSearchableSelect.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormNumberInput from '@/Components/forms/FormNumberInput.vue'

export interface RecipeIngredientRow {
  raw_material_product_id: number | null
  raw_material_label?: string
  qty_per_batch: number
  uom_code: string | null
}

const props = defineProps<{
  modelValue: RecipeIngredientRow[]
  disabled?: boolean
}>()

const emit = defineEmits<{
  'update:modelValue': [value: RecipeIngredientRow[]]
}>()

const emptyRow = (): RecipeIngredientRow => ({ raw_material_product_id: null, qty_per_batch: 1, uom_code: null })

const addRow = () => emit('update:modelValue', [...props.modelValue, emptyRow()])
const removeRow = (index: number) => emit('update:modelValue', props.modelValue.filter((_, i) => i !== index))
const update = (index: number, patch: Partial<RecipeIngredientRow>) => {
  const rows = props.modelValue.map((row, i) => (i === index ? { ...row, ...patch } : row))
  emit('update:modelValue', rows)
}
</script>

<template>
  <div class="space-y-3">
    <div class="flex items-center justify-between">
      <p class="text-xs font-semibold uppercase tracking-wide text-ink-600">Ingredients</p>
      <button
        v-if="!disabled"
        type="button"
        class="inline-flex items-center gap-1 text-xs font-medium text-accent hover:underline"
        @click="addRow"
      >
        <Plus class="h-3.5 w-3.5" /> Add ingredient
      </button>
    </div>

    <div v-if="modelValue.length === 0" class="text-sm text-ink-600">No ingredients yet.</div>

    <div
      v-for="(row, index) in modelValue"
      :key="index"
      class="grid grid-cols-[2fr_1fr_1fr_auto] items-end gap-3 rounded-md border border-border p-3"
    >
      <FormAsyncSearchableSelect
        :model-value="row.raw_material_product_id"
        :name="`ingredients.${index}.raw_material_product_id`"
        label="Raw material"
        api-entity="inventory_product"
        placeholder="Search SKU or name…"
        :disabled="disabled"
        @update:model-value="(value) => update(index, { raw_material_product_id: Number(value) })"
      />

      <FormNumberInput
        :model-value="row.qty_per_batch"
        :name="`ingredients.${index}.qty_per_batch`"
        label="Qty per batch"
        :decimals="6"
        :disabled="disabled"
        @update:model-value="(value) => update(index, { qty_per_batch: value ?? 0 })"
      />

      <FormInput
        :model-value="row.uom_code ?? ''"
        :name="`ingredients.${index}.uom_code`"
        label="UoM code"
        placeholder="e.g. KG"
        :disabled="disabled"
        @update:model-value="(value) => update(index, { uom_code: String(value) || null })"
      />

      <button v-if="!disabled" type="button" class="text-signal-danger" @click="removeRow(index)">
        <Trash2 class="h-4 w-4" />
      </button>
    </div>
  </div>
</template>
