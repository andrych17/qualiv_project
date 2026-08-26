<!-- ponytail: partner_roles multi-select dropdown — shared by Contact/Company forms -->
<script setup lang="ts">
import FormMultiSelect from '@/Components/forms/FormMultiSelect.vue'

const props = defineProps<{
  modelValue: number[]
  roleTypes: Array<{ id: number; name: string }>
}>()

const emit = defineEmits<{
  'update:modelValue': [value: number[]]
}>()
</script>

<template>
  <div>
    <FormMultiSelect
      :model-value="modelValue"
      :options="roleTypes"
      label="Roles"
      placeholder="Pilih roles partner..."
      search-placeholder="Cari roles..."
      @update:model-value="emit('update:modelValue', $event as number[])"
    />
    <p v-if="roleTypes.length === 0" class="mt-1 text-xs text-ink-600">
      No role types configured yet — add one under Config.
    </p>
  </div>
</template>
