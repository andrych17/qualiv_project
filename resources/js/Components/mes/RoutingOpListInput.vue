<!-- ponytail: repeatable routing operations (MES_SPECS.md §3E) -->
<script setup lang="ts">
import { Plus, Trash2 } from 'lucide-vue-next'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import FormNumberInput from '@/Components/forms/FormNumberInput.vue'
import FormTextarea from '@/Components/forms/FormTextarea.vue'
import FormSwitch from '@/Components/forms/FormSwitch.vue'

export interface RoutingOpRow {
  op_code: string
  op_name: string
  work_center_id: number | null
  setup_time_minutes: number
  run_time_minutes: number
  queue_time_minutes: number
  standard_output_qty: number | null
  instructions: string | null
  auto_issue_components: boolean
  is_rework_destination: boolean
}

const props = defineProps<{
  modelValue: RoutingOpRow[]
  workCenters: Array<{ value: number; label: string }>
  disabled?: boolean
}>()

const emit = defineEmits<{
  'update:modelValue': [value: RoutingOpRow[]]
}>()

const emptyRow = (): RoutingOpRow => ({
  op_code: '', op_name: '', work_center_id: null,
  setup_time_minutes: 0, run_time_minutes: 0, queue_time_minutes: 0,
  standard_output_qty: null, instructions: null, auto_issue_components: true, is_rework_destination: false,
})

const addRow = () => emit('update:modelValue', [...props.modelValue, emptyRow()])
const removeRow = (index: number) => emit('update:modelValue', props.modelValue.filter((_, i) => i !== index))
const update = (index: number, patch: Partial<RoutingOpRow>) => {
  const rows = props.modelValue.map((row, i) => (i === index ? { ...row, ...patch } : row))
  emit('update:modelValue', rows)
}
</script>

<template>
  <div class="space-y-3">
    <div class="flex items-center justify-between">
      <p class="text-xs font-semibold uppercase tracking-wide text-ink-600">Operations (in sequence)</p>
      <button
        v-if="!disabled"
        type="button"
        class="inline-flex items-center gap-1 text-xs font-medium text-accent hover:underline"
        @click="addRow"
      >
        <Plus class="h-3.5 w-3.5" /> Add operation
      </button>
    </div>

    <div v-if="modelValue.length === 0" class="text-sm text-ink-600">No operations yet.</div>

    <div
      v-for="(row, index) in modelValue"
      :key="index"
      class="space-y-3 rounded-md border border-border p-3"
    >
      <div class="flex items-center justify-between">
        <span class="text-xs font-semibold text-ink-600">Step {{ (index + 1) * 10 }}</span>
        <button v-if="!disabled" type="button" class="text-signal-danger" @click="removeRow(index)">
          <Trash2 class="h-4 w-4" />
        </button>
      </div>

      <div class="grid grid-cols-3 gap-3">
        <FormInput
          :model-value="row.op_code"
          :name="`ops.${index}.op_code`"
          label="Op code"
          placeholder="e.g. OP-10"
          :disabled="disabled"
          @update:model-value="(value) => update(index, { op_code: String(value) })"
        />
        <FormInput
          :model-value="row.op_name"
          :name="`ops.${index}.op_name`"
          label="Op name"
          placeholder="e.g. Cutting"
          :disabled="disabled"
          @update:model-value="(value) => update(index, { op_name: String(value) })"
        />
        <FormSelect
          :model-value="row.work_center_id"
          :name="`ops.${index}.work_center_id`"
          label="Work Center"
          :options="workCenters"
          :disabled="disabled"
          @update:model-value="(value) => update(index, { work_center_id: Number(value) })"
        />
      </div>

      <div class="grid grid-cols-4 gap-3">
        <FormNumberInput
          :model-value="row.setup_time_minutes"
          :name="`ops.${index}.setup_time_minutes`"
          label="Setup (min)"
          :disabled="disabled"
          @update:model-value="(value) => update(index, { setup_time_minutes: value ?? 0 })"
        />
        <FormNumberInput
          :model-value="row.run_time_minutes"
          :name="`ops.${index}.run_time_minutes`"
          label="Run (min)"
          :disabled="disabled"
          @update:model-value="(value) => update(index, { run_time_minutes: value ?? 0 })"
        />
        <FormNumberInput
          :model-value="row.queue_time_minutes"
          :name="`ops.${index}.queue_time_minutes`"
          label="Queue (min)"
          :disabled="disabled"
          @update:model-value="(value) => update(index, { queue_time_minutes: value ?? 0 })"
        />
        <FormNumberInput
          :model-value="row.standard_output_qty"
          :name="`ops.${index}.standard_output_qty`"
          label="Std output qty"
          :decimals="4"
          :disabled="disabled"
          @update:model-value="(value) => update(index, { standard_output_qty: value })"
        />
      </div>

      <FormTextarea
        :model-value="row.instructions ?? ''"
        :name="`ops.${index}.instructions`"
        label="Instructions"
        :disabled="disabled"
        @update:model-value="(value) => update(index, { instructions: String(value) || null })"
      />

      <FormSwitch
        :model-value="row.auto_issue_components"
        :name="`ops.${index}.auto_issue_components`"
        label="Auto-issue components on Complete (§3G — 1:1 with the order's BOM, scaled to qty completed)"
        :disabled="disabled"
        @update:model-value="(value) => update(index, { auto_issue_components: value })"
      />

      <FormSwitch
        :model-value="row.is_rework_destination"
        :name="`ops.${index}.is_rework_destination`"
        label="Rework destination (§3N — 'Send to Rework' on a scrapped unit creates a child order that starts here, skipping earlier operations)"
        :disabled="disabled"
        @update:model-value="(value) => update(index, { is_rework_destination: value })"
      />
    </div>
  </div>
</template>
