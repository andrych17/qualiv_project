<!-- ponytail: repeatable process phases, each with nested parameters (MES_SPECS.md §3F) -->
<script setup lang="ts">
import { Plus, Trash2 } from 'lucide-vue-next'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import FormNumberInput from '@/Components/forms/FormNumberInput.vue'

export interface ProcessParameterRow {
  parameter_code: string
  target_value: number | null
  min_value: number | null
  max_value: number | null
  uom_code: string | null
}

export interface ProcessPhaseRow {
  phase_name: string
  work_center_id: number | null
  standard_duration_minutes: number | null
  parameters: ProcessParameterRow[]
}

const props = defineProps<{
  modelValue: ProcessPhaseRow[]
  workCenters: Array<{ value: number; label: string }>
  disabled?: boolean
}>()

const emit = defineEmits<{
  'update:modelValue': [value: ProcessPhaseRow[]]
}>()

const emptyParameter = (): ProcessParameterRow => ({ parameter_code: '', target_value: null, min_value: null, max_value: null, uom_code: null })
const emptyPhase = (): ProcessPhaseRow => ({ phase_name: '', work_center_id: null, standard_duration_minutes: null, parameters: [] })

const addPhase = () => emit('update:modelValue', [...props.modelValue, emptyPhase()])
const removePhase = (index: number) => emit('update:modelValue', props.modelValue.filter((_, i) => i !== index))
const updatePhase = (index: number, patch: Partial<ProcessPhaseRow>) => {
  const rows = props.modelValue.map((row, i) => (i === index ? { ...row, ...patch } : row))
  emit('update:modelValue', rows)
}

const addParameter = (phaseIndex: number) => {
  updatePhase(phaseIndex, { parameters: [...props.modelValue[phaseIndex].parameters, emptyParameter()] })
}
const removeParameter = (phaseIndex: number, paramIndex: number) => {
  updatePhase(phaseIndex, { parameters: props.modelValue[phaseIndex].parameters.filter((_, i) => i !== paramIndex) })
}
const updateParameter = (phaseIndex: number, paramIndex: number, patch: Partial<ProcessParameterRow>) => {
  const parameters = props.modelValue[phaseIndex].parameters.map((row, i) => (i === paramIndex ? { ...row, ...patch } : row))
  updatePhase(phaseIndex, { parameters })
}
</script>

<template>
  <div class="space-y-3">
    <div class="flex items-center justify-between">
      <p class="text-xs font-semibold uppercase tracking-wide text-ink-600">Phases (in sequence)</p>
      <button
        v-if="!disabled"
        type="button"
        class="inline-flex items-center gap-1 text-xs font-medium text-accent hover:underline"
        @click="addPhase"
      >
        <Plus class="h-3.5 w-3.5" /> Add phase
      </button>
    </div>

    <div v-if="modelValue.length === 0" class="text-sm text-ink-600">No phases yet.</div>

    <div
      v-for="(phase, phaseIndex) in modelValue"
      :key="phaseIndex"
      class="space-y-3 rounded-md border border-border p-3"
    >
      <div class="flex items-center justify-between">
        <span class="text-xs font-semibold text-ink-600">Phase {{ (phaseIndex + 1) * 10 }}</span>
        <button v-if="!disabled" type="button" class="text-signal-danger" @click="removePhase(phaseIndex)">
          <Trash2 class="h-4 w-4" />
        </button>
      </div>

      <div class="grid grid-cols-3 gap-3">
        <FormInput
          :model-value="phase.phase_name"
          :name="`phases.${phaseIndex}.phase_name`"
          label="Phase name"
          placeholder="e.g. Mixing"
          :disabled="disabled"
          @update:model-value="(value) => updatePhase(phaseIndex, { phase_name: String(value) })"
        />
        <FormSelect
          :model-value="phase.work_center_id"
          :name="`phases.${phaseIndex}.work_center_id`"
          label="Work Center"
          :options="workCenters"
          :disabled="disabled"
          @update:model-value="(value) => updatePhase(phaseIndex, { work_center_id: Number(value) })"
        />
        <FormNumberInput
          :model-value="phase.standard_duration_minutes"
          :name="`phases.${phaseIndex}.standard_duration_minutes`"
          label="Std duration (min)"
          :disabled="disabled"
          @update:model-value="(value) => updatePhase(phaseIndex, { standard_duration_minutes: value })"
        />
      </div>

      <div class="space-y-2 rounded-md bg-surface-50 p-3">
        <div class="flex items-center justify-between">
          <p class="text-xs font-semibold uppercase tracking-wide text-ink-600">Parameters</p>
          <button
            v-if="!disabled"
            type="button"
            class="inline-flex items-center gap-1 text-xs font-medium text-accent hover:underline"
            @click="addParameter(phaseIndex)"
          >
            <Plus class="h-3.5 w-3.5" /> Add parameter
          </button>
        </div>

        <div v-if="phase.parameters.length === 0" class="text-xs text-ink-600">No parameters yet.</div>

        <div
          v-for="(param, paramIndex) in phase.parameters"
          :key="paramIndex"
          class="grid grid-cols-[2fr_1fr_1fr_1fr_1fr_auto] items-end gap-2"
        >
          <FormInput
            :model-value="param.parameter_code"
            :name="`phases.${phaseIndex}.parameters.${paramIndex}.parameter_code`"
            label="Code"
            placeholder="e.g. TEMPERATURE"
            :disabled="disabled"
            @update:model-value="(value) => updateParameter(phaseIndex, paramIndex, { parameter_code: String(value) })"
          />
          <FormNumberInput
            :model-value="param.target_value"
            :name="`phases.${phaseIndex}.parameters.${paramIndex}.target_value`"
            label="Target"
            :decimals="4"
            :disabled="disabled"
            @update:model-value="(value) => updateParameter(phaseIndex, paramIndex, { target_value: value })"
          />
          <FormNumberInput
            :model-value="param.min_value"
            :name="`phases.${phaseIndex}.parameters.${paramIndex}.min_value`"
            label="Min"
            :decimals="4"
            :disabled="disabled"
            @update:model-value="(value) => updateParameter(phaseIndex, paramIndex, { min_value: value })"
          />
          <FormNumberInput
            :model-value="param.max_value"
            :name="`phases.${phaseIndex}.parameters.${paramIndex}.max_value`"
            label="Max"
            :decimals="4"
            :disabled="disabled"
            @update:model-value="(value) => updateParameter(phaseIndex, paramIndex, { max_value: value })"
          />
          <FormInput
            :model-value="param.uom_code ?? ''"
            :name="`phases.${phaseIndex}.parameters.${paramIndex}.uom_code`"
            label="UoM"
            placeholder="e.g. °C"
            :disabled="disabled"
            @update:model-value="(value) => updateParameter(phaseIndex, paramIndex, { uom_code: String(value) || null })"
          />
          <button v-if="!disabled" type="button" class="text-signal-danger" @click="removeParameter(phaseIndex, paramIndex)">
            <Trash2 class="h-4 w-4" />
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
