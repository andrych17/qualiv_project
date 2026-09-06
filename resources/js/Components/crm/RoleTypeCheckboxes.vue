<!-- ponytail: partner_roles multi-select dropdown — shared by Contact/Company forms -->
<script setup lang="ts">
import FormMultiSelect from '@/Components/forms/FormMultiSelect.vue'
import { useI18n } from '@/Composables/useI18n'

const props = defineProps<{
  modelValue: number[]
  roleTypes: Array<{ id: number; name: string }>
}>()

const emit = defineEmits<{
  'update:modelValue': [value: number[]]
}>()

const { t } = useI18n()
</script>

<template>
  <div>
    <FormMultiSelect
      :model-value="modelValue"
      :options="roleTypes"
      :label="t('crm.role_types')"
      :placeholder="t('crm.role_types')"
      :search-placeholder="t('common.search')"
      @update:model-value="emit('update:modelValue', $event as number[])"
    />
  </div>
</template>
