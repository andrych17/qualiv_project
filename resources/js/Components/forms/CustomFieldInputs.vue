<!-- ponytail: Dynamic custom field inputs from CUSTOMFIELDS defs -->
<script setup lang="ts">
import FormInput from '@/Components/forms/FormInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'

export type CustomFieldDef = {
  code: string
  label: string
  field_type: string
  options: { label: string; value: string }[] | null
  is_required: boolean
  seq: number
  value: string | null
}

const props = defineProps<{
  fields: CustomFieldDef[]
  modelValue: Record<string, string>
  errors?: Record<string, string>
}>()

const emit = defineEmits<{
  'update:modelValue': [value: Record<string, string>]
}>()

const set = (code: string, value: string | number) => {
  emit('update:modelValue', { ...props.modelValue, [code]: String(value) })
}

const fieldError = (code: string): string | undefined =>
  props.errors?.[`custom_fields.${code}`]
</script>

<template>
  <div v-if="fields.length" class="space-y-4 border-t border-border pt-4">
    <p class="text-xs font-semibold uppercase tracking-wide text-ink-600">Custom fields</p>
    <template v-for="field in fields" :key="field.code">
      <FormSelect
        v-if="field.field_type === 'select'"
        :model-value="modelValue[field.code] ?? ''"
        :name="`custom_fields.${field.code}`"
        :label="field.label"
        :options="(field.options ?? []).map((o) => ({ label: o.label, value: o.value }))"
        :error="fieldError(field.code)"
        :required="field.is_required"
        @update:model-value="set(field.code, $event)"
      />
      <FormInput
        v-else
        :model-value="modelValue[field.code] ?? ''"
        :name="`custom_fields.${field.code}`"
        :label="field.label"
        :type="field.field_type === 'number' ? 'number' : field.field_type === 'date' ? 'date' : 'text'"
        :error="fieldError(field.code)"
        :required="field.is_required"
        @update:model-value="set(field.code, $event)"
      />
    </template>
  </div>
</template>
