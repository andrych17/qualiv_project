<!-- ponytail: resource-group-or-single-resource picker shared by CapacityPlans Create/Edit (PP_SPECS.md §3F) -->
<script setup lang="ts">
import { computed } from 'vue'
import FormRadioGroup from '@/Components/forms/FormRadioGroup.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import FormInput from '@/Components/forms/FormInput.vue'

export interface CapacityTarget {
  resource_group_id: number | null
  resource_type: string | null
  resource_ref_id: number | null
}

const props = defineProps<{
  modelValue: CapacityTarget
  resourceGroupOptions: Array<{ value: number; label: string }>
  resourceOptions: Array<{ value: number; label: string }>
  errors?: { resource_group_id?: string; resource_type?: string; resource_ref_id?: string }
}>()

const emit = defineEmits<{
  'update:modelValue': [value: CapacityTarget]
}>()

const targetTypeOptions = [
  { value: 'group', label: 'Resource Group' },
  { value: 'resource', label: 'Single Resource' },
]

const singleResourceTypeOptions = [
  { value: 'pp_resource', label: 'PP Resource (tool / tank / utility / warehouse)' },
  { value: 'mes_work_center', label: 'MES Work Center (informational — MES not built yet)' },
  { value: 'mes_machine', label: 'MES Machine (informational — MES not built yet)' },
]

const targetType = computed(() => (props.modelValue.resource_type ? 'resource' : 'group'))

const setTargetType = (value: string | number) => {
  emit('update:modelValue', value === 'group'
    ? { resource_group_id: null, resource_type: null, resource_ref_id: null }
    : { resource_group_id: null, resource_type: 'pp_resource', resource_ref_id: null })
}

const update = (patch: Partial<CapacityTarget>) => emit('update:modelValue', { ...props.modelValue, ...patch })
</script>

<template>
  <div class="space-y-4">
    <FormRadioGroup :model-value="targetType" name="target_type" label="Applies to" :options="targetTypeOptions" inline @update:model-value="setTargetType" />

    <FormSelect
      v-if="targetType === 'group'"
      :model-value="modelValue.resource_group_id"
      name="resource_group_id"
      label="Resource Group"
      :options="resourceGroupOptions"
      :error="errors?.resource_group_id"
      required
      @update:model-value="(value) => update({ resource_group_id: Number(value) })"
    />

    <template v-else>
      <FormSelect
        :model-value="modelValue.resource_type"
        name="resource_type"
        label="Resource type"
        :options="singleResourceTypeOptions"
        :error="errors?.resource_type"
        required
        @update:model-value="(value) => update({ resource_type: String(value), resource_ref_id: null })"
      />

      <FormSelect
        v-if="modelValue.resource_type === 'pp_resource'"
        :model-value="modelValue.resource_ref_id"
        name="resource_ref_id"
        label="Resource"
        :options="resourceOptions"
        :error="errors?.resource_ref_id"
        required
        @update:model-value="(value) => update({ resource_ref_id: Number(value) })"
      />
      <FormInput
        v-else
        :model-value="modelValue.resource_ref_id"
        name="resource_ref_id"
        label="MES resource ID (informational)"
        type="number"
        :error="errors?.resource_ref_id"
        required
        @update:model-value="(value) => update({ resource_ref_id: Number(value) })"
      />
    </template>
  </div>
</template>
