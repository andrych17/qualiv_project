<!-- ponytail: repeatable resource group members (PP_SPECS.md §3E) -->
<script setup lang="ts">
import { Plus, Trash2 } from 'lucide-vue-next'
import FormSelect from '@/Components/forms/FormSelect.vue'
import FormInput from '@/Components/forms/FormInput.vue'

export interface ResourceGroupMemberRow {
  resource_type: string
  resource_ref_id: number | null
  resource_label?: string | null
}

const props = defineProps<{
  modelValue: ResourceGroupMemberRow[]
  resourceOptions: Array<{ value: number; label: string }>
  disabled?: boolean
}>()

const emit = defineEmits<{
  'update:modelValue': [value: ResourceGroupMemberRow[]]
}>()

const typeOptions = [
  { value: 'pp_resource', label: 'PP Resource (tool / tank / utility / warehouse)' },
  { value: 'mes_work_center', label: 'MES Work Center (informational — MES not built yet)' },
  { value: 'mes_machine', label: 'MES Machine (informational — MES not built yet)' },
  { value: 'mes_station', label: 'MES Station (informational — MES not built yet)' },
]

const emptyRow = (): ResourceGroupMemberRow => ({ resource_type: 'pp_resource', resource_ref_id: null })

const addRow = () => emit('update:modelValue', [...props.modelValue, emptyRow()])
const removeRow = (index: number) => emit('update:modelValue', props.modelValue.filter((_, i) => i !== index))
const update = (index: number, patch: Partial<ResourceGroupMemberRow>) => {
  const rows = props.modelValue.map((row, i) => (i === index ? { ...row, ...patch } : row))
  emit('update:modelValue', rows)
}
</script>

<template>
  <div class="space-y-3">
    <div class="flex items-center justify-between">
      <p class="text-xs font-semibold uppercase tracking-wide text-ink-600">Members</p>
      <button
        v-if="!disabled"
        type="button"
        class="inline-flex items-center gap-1 text-xs font-medium text-accent hover:underline"
        @click="addRow"
      >
        <Plus class="h-3.5 w-3.5" /> Add member
      </button>
    </div>

    <div v-if="modelValue.length === 0" class="text-sm text-ink-600">No members yet.</div>

    <div
      v-for="(row, index) in modelValue"
      :key="index"
      class="grid grid-cols-[2fr_2fr_auto] items-end gap-3 rounded-md border border-border p-3"
    >
      <FormSelect
        :model-value="row.resource_type"
        :name="`members.${index}.resource_type`"
        label="Resource type"
        :options="typeOptions"
        :disabled="disabled"
        @update:model-value="(value) => update(index, { resource_type: String(value), resource_ref_id: null })"
      />

      <FormSelect
        v-if="row.resource_type === 'pp_resource'"
        :model-value="row.resource_ref_id"
        :name="`members.${index}.resource_ref_id`"
        label="Resource"
        :options="resourceOptions"
        :disabled="disabled"
        @update:model-value="(value) => update(index, { resource_ref_id: Number(value) })"
      />
      <FormInput
        v-else
        :model-value="row.resource_ref_id"
        :name="`members.${index}.resource_ref_id`"
        label="MES resource ID (informational)"
        type="number"
        :disabled="disabled"
        @update:model-value="(value) => update(index, { resource_ref_id: Number(value) })"
      />

      <button v-if="!disabled" type="button" class="text-signal-danger" @click="removeRow(index)">
        <Trash2 class="h-4 w-4" />
      </button>
    </div>
  </div>
</template>
