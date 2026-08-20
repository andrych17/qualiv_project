<!-- ponytail: repeatable Contact Point rows — shared by Contact/Company Create+Edit -->
<script setup lang="ts">
import { Plus, Trash2 } from 'lucide-vue-next'
import FormSelect from '@/Components/forms/FormSelect.vue'

export interface ContactPointRow {
  type: string
  value: string
  is_primary: boolean
  opt_out: boolean
}

const props = defineProps<{
  modelValue: ContactPointRow[]
}>()

const emit = defineEmits<{
  'update:modelValue': [value: ContactPointRow[]]
}>()

const emptyRow = (): ContactPointRow => ({
  type: 'email',
  value: '',
  is_primary: props.modelValue.length === 0,
  opt_out: false,
})

const addRow = () => emit('update:modelValue', [...props.modelValue, emptyRow()])
const removeRow = (index: number) => emit('update:modelValue', props.modelValue.filter((_, i) => i !== index))
const update = (index: number, patch: Partial<ContactPointRow>) => {
  const rows = props.modelValue.map((row, i) => (i === index ? { ...row, ...patch } : row))
  emit('update:modelValue', rows)
}
</script>

<template>
  <div class="space-y-3">
    <div class="flex items-center justify-between">
      <p class="text-xs font-semibold uppercase tracking-wide text-ink-600">Contact points</p>
      <button
        type="button"
        class="inline-flex items-center gap-1 text-xs font-medium text-accent hover:underline"
        @click="addRow"
      >
        <Plus class="h-3.5 w-3.5" /> Add contact point
      </button>
    </div>

    <div v-if="modelValue.length === 0" class="text-sm text-ink-600">No contact points added.</div>

    <div
      v-for="(row, index) in modelValue"
      :key="index"
      class="flex items-center gap-3 rounded-md border border-border p-3"
    >
      <div class="w-32">
        <FormSelect
          :model-value="row.type"
          :name="`contact_points.${index}.type`"
          :options="[
            { label: 'Email', value: 'email' },
            { label: 'Phone', value: 'phone' },
            { label: 'Mobile', value: 'mobile' },
            { label: 'Fax', value: 'fax' },
          ]"
          @update:model-value="update(index, { type: String($event) })"
        />
      </div>
      <input
        :value="row.value"
        placeholder="Value"
        class="flex-1 rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm text-ink-900 shadow-sm focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/20"
        @input="update(index, { value: ($event.target as HTMLInputElement).value })"
      />
      <label class="flex items-center gap-1.5 text-xs text-ink-600">
        <input
          type="checkbox"
          :checked="row.is_primary"
          @change="update(index, { is_primary: ($event.target as HTMLInputElement).checked })"
        />
        Primary
      </label>
      <label class="flex items-center gap-1.5 text-xs text-ink-600">
        <input
          type="checkbox"
          :checked="row.opt_out"
          @change="update(index, { opt_out: ($event.target as HTMLInputElement).checked })"
        />
        Opt out
      </label>
      <button type="button" class="text-signal-danger" @click="removeRow(index)">
        <Trash2 class="h-4 w-4" />
      </button>
    </div>
  </div>
</template>
