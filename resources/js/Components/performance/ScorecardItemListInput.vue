<!-- ponytail: repeatable Scorecard items (§3F) — each row picks a perspective, then links to
     exactly one of a KPI or an OKR Objective, plus its weight within that perspective (must sum
     to 100% per perspective, enforced server-side on save). -->
<script setup lang="ts">
import { Plus, Trash2 } from 'lucide-vue-next'
import FormSelect from '@/Components/forms/FormSelect.vue'
import FormRadioGroup from '@/Components/forms/FormRadioGroup.vue'
import FormNumberInput from '@/Components/forms/FormNumberInput.vue'

export interface ScorecardItemRow {
  perspective_id: number | null
  linkType: 'kpi' | 'okr'
  kpi_id: number | null
  okr_id: number | null
  weight: number | null
}

const props = defineProps<{
  modelValue: ScorecardItemRow[]
  perspectives: Array<{ id: number; name: string }>
  kpis: Array<{ id: number; name: string }>
  okrObjectives: Array<{ id: number; objective_text: string }>
}>()

const emit = defineEmits<{
  'update:modelValue': [value: ScorecardItemRow[]]
}>()

const emptyRow = (): ScorecardItemRow => ({ perspective_id: null, linkType: 'kpi', kpi_id: null, okr_id: null, weight: null })

const addRow = () => emit('update:modelValue', [...props.modelValue, emptyRow()])
const removeRow = (index: number) => emit('update:modelValue', props.modelValue.filter((_, i) => i !== index))
const update = (index: number, patch: Partial<ScorecardItemRow>) => {
  const rows = props.modelValue.map((row, i) => (i === index ? { ...row, ...patch } : row))
  emit('update:modelValue', rows)
}

const perspectiveWeightTotal = (perspectiveId: number | null) => {
  if (perspectiveId === null) return 0
  return props.modelValue
    .filter((r) => r.perspective_id === perspectiveId)
    .reduce((sum, r) => sum + (r.weight ?? 0), 0)
}
</script>

<template>
  <div class="space-y-3">
    <div class="flex items-center justify-between">
      <p class="text-xs font-semibold uppercase tracking-wide text-ink-600">Items</p>
      <button type="button" class="inline-flex items-center gap-1 text-xs font-medium text-accent hover:underline" @click="addRow">
        <Plus class="h-3.5 w-3.5" /> Add item
      </button>
    </div>

    <div v-if="modelValue.length === 0" class="text-sm text-ink-600">No items yet.</div>

    <div v-for="(row, index) in modelValue" :key="index" class="space-y-3 rounded-md border border-border p-3">
      <div class="grid grid-cols-[1.5fr_1fr_auto] items-end gap-3">
        <FormSelect
          :model-value="row.perspective_id"
          :name="`items.${index}.perspective_id`"
          label="Perspective"
          placeholder="Select…"
          :options="perspectives.map((p) => ({ label: p.name, value: p.id }))"
          @update:model-value="(value) => update(index, { perspective_id: Number(value) })"
        />

        <div class="space-y-1.5">
          <label class="text-sm font-medium text-ink-900">Perspective weight total</label>
          <p
            class="px-3 py-2 text-sm font-medium"
            :class="Math.abs(perspectiveWeightTotal(row.perspective_id) - 100) < 0.01 ? 'text-signal-success' : 'text-signal-warning'"
          >
            {{ perspectiveWeightTotal(row.perspective_id) }}% (must be 100%)
          </p>
        </div>

        <button type="button" class="text-signal-danger" @click="removeRow(index)">
          <Trash2 class="h-4 w-4" />
        </button>
      </div>

      <FormRadioGroup
        :model-value="row.linkType"
        :name="`items.${index}.linkType`"
        label="Link to"
        inline
        :options="[{ label: 'A KPI', value: 'kpi' }, { label: 'An OKR Objective', value: 'okr' }]"
        @update:model-value="(value) => update(index, { linkType: value as 'kpi' | 'okr', kpi_id: null, okr_id: null })"
      />

      <div class="grid grid-cols-2 gap-3">
        <FormSelect
          v-if="row.linkType === 'kpi'"
          :model-value="row.kpi_id"
          :name="`items.${index}.kpi_id`"
          label="KPI"
          placeholder="Select a KPI…"
          :options="kpis.map((k) => ({ label: k.name, value: k.id }))"
          @update:model-value="(value) => update(index, { kpi_id: Number(value) })"
        />
        <FormSelect
          v-else
          :model-value="row.okr_id"
          :name="`items.${index}.okr_id`"
          label="OKR Objective"
          placeholder="Select an objective…"
          :options="okrObjectives.map((o) => ({ label: o.objective_text, value: o.id }))"
          @update:model-value="(value) => update(index, { okr_id: Number(value) })"
        />

        <FormNumberInput
          :model-value="row.weight"
          :name="`items.${index}.weight`"
          label="Weight (%)"
          @update:model-value="(value) => update(index, { weight: value })"
        />
      </div>
    </div>
  </div>
</template>
