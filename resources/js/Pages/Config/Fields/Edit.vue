<!-- ponytail: Edit custom field definition -->
<script setup lang="ts">
import { computed } from 'vue'
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import FormSwitch from '@/Components/forms/FormSwitch.vue'

interface FieldDefItem {
  id: number
  entity_type: string
  module_code: string | null
  code: string
  label: string
  field_type: string
  options: Array<{ label: string; value: string }> | null
  is_required: boolean
  seq: number
  status: string
}

const props = defineProps<{
  def: FieldDefItem
  entityTypes: Array<{ label: string; value: string }>
  modules: Array<{ label: string; value: string }>
}>()

const form = useForm({
  entity_type: props.def.entity_type,
  module_code: props.def.module_code ?? '',
  code: props.def.code,
  label: props.def.label,
  field_type: props.def.field_type,
  options: (props.def.options ?? []) as Array<{ label: string; value: string }>,
  is_required: props.def.is_required,
  seq: props.def.seq,
  status: props.def.status,
})

const isSelect = computed(() => form.field_type === 'select')

const addOption = () => {
  form.options = [...form.options, { label: '', value: '' }]
}

const removeOption = (index: number) => {
  form.options = form.options.filter((_, i) => i !== index)
}

const submit = () => form.put(route('config.fields.update', props.def.id))
</script>

<template>
  <AppLayout>
    <PageHeader title="Edit Field" :description="`${def.entity_type}.${def.code}`" />

    <div class="mt-6 max-w-2xl rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
      <form class="space-y-4" @submit.prevent="submit">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <FormInput v-model="form.entity_type" name="entity_type" label="Entity type" :error="form.errors.entity_type" required />
          <FormSelect v-model="form.module_code" name="module_code" label="Module" :options="[{ label: '(none)', value: '' }, ...props.modules]" :error="form.errors.module_code" />
        </div>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <FormInput v-model="form.code" name="code" label="Code" :error="form.errors.code" required />
          <FormInput v-model="form.label" name="label" label="Label" :error="form.errors.label" required />
        </div>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <FormSelect
            v-model="form.field_type"
            name="field_type"
            label="Type"
            :options="[
              { label: 'Text', value: 'text' },
              { label: 'Number', value: 'number' },
              { label: 'Date', value: 'date' },
              { label: 'Select', value: 'select' },
            ]"
            :error="form.errors.field_type"
            required
          />
          <FormInput v-model.number="form.seq" name="seq" label="Sequence" type="number" :error="form.errors.seq" required />
        </div>
        <FormSelect
          v-model="form.status"
          name="status"
          label="Status"
          :options="[
            { label: 'Active', value: 'active' },
            { label: 'Inactive', value: 'inactive' },
          ]"
          :error="form.errors.status"
          required
        />
        <FormSwitch v-model="form.is_required" name="is_required" label="Required" />

        <div v-if="isSelect" class="space-y-2">
          <p class="text-sm font-medium text-gray-700">Options</p>
          <p v-if="form.errors.options" class="text-sm text-red-600">{{ form.errors.options }}</p>
          <div v-for="(opt, i) in form.options" :key="i" class="grid grid-cols-[1fr_1fr_auto] gap-2">
            <FormInput v-model="opt.label" :name="`options.${i}.label`" label="Label" />
            <FormInput v-model="opt.value" :name="`options.${i}.value`" label="Value" />
            <button type="button" class="self-end text-sm text-red-600" @click="removeOption(i)">Remove</button>
          </div>
          <button type="button" class="text-sm font-medium text-gray-900 underline" @click="addOption">Add option</button>
        </div>

        <div class="flex items-center justify-end gap-3 border-t border-gray-100 pt-4">
          <Link :href="route('config.fields.index')" class="text-sm font-semibold text-gray-900">Cancel</Link>
          <button type="submit" :disabled="form.processing" class="min-h-11 rounded-md bg-gray-900 px-3 py-2 text-sm font-semibold text-white disabled:opacity-50">
            Update Field
          </button>
        </div>
      </form>
    </div>
  </AppLayout>
</template>
