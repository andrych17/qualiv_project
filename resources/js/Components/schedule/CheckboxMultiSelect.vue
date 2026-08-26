<!-- ponytail: multi-picker using FormMultiSelect dropdown for schedule / tasks -->
<script setup lang="ts">
import FormMultiSelect from '@/Components/forms/FormMultiSelect.vue'

const props = withDefaults(defineProps<{
  modelValue: number[]
  options: Array<{ id: number; name: string }>
  label?: string
  emptyText?: string
}>(), {
  emptyText: 'None available.',
})

const emit = defineEmits<{
  'update:modelValue': [value: number[]]
}>()
</script>

<template>
  <div>
    <FormMultiSelect
      :model-value="modelValue"
      :options="options"
      :label="label"
      :placeholder="`Pilih ${label?.toLowerCase() || 'opsi'}...`"
      :search-placeholder="`Cari ${label?.toLowerCase() || 'opsi'}...`"
      @update:model-value="emit('update:modelValue', $event as number[])"
    />
    <p v-if="options.length === 0" class="mt-1 text-xs text-ink-600">
      {{ emptyText }}
    </p>
  </div>
</template>
