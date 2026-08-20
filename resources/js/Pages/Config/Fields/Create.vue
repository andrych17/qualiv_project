<!-- ponytail: Create custom field definition -->
<script setup lang="ts">
import { computed } from 'vue'
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import FormSwitch from '@/Components/forms/FormSwitch.vue'

const props = defineProps<{
  entityTypes: Array<{ label: string; value: string }>
  modules: Array<{ label: string; value: string }>
}>()

const form = useForm({
  entity_type: '',
  module_code: '',
  code: '',
  label: '',
  field_type: 'text',
  options: [] as Array<{ label: string; value: string }>,
  is_required: false,
  seq: 10,
  status: 'active',
})

const isSelect = computed(() => form.field_type === 'select')

const addOption = () => {
  form.options = [...form.options, { label: '', value: '' }]
}

const removeOption = (index: number) => {
  form.options = form.options.filter((_, i) => i !== index)
}

const submit = () => form.post(route('config.fields.store'))
</script>

<template>
  <AppLayout>
    <PageHeader title="Create Field" description="Add a tenant custom field. No migration required." />

    <div class="mt-6 max-w-2xl rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
      <form class="space-y-4" @submit.prevent="submit">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <FormInput
            v-model="form.entity_type"
            name="entity_type"
            label="Entity type"
            placeholder="e.g. legal_case"
            :error="form.errors.entity_type"
            required
          />
          <FormSelect
            v-model="form.module_code"
            name="module_code"
            label="Module"
            :options="[{ label: '(none)', value: '' }, ...props.modules]"
            :error="form.errors.module_code"
          />
        </div>
        <p v-if="props.entityTypes.length" class="text-xs text-gray-500">
          Known types:
          <button
            v-for="t in props.entityTypes"
            :key="t.value"
            type="button"
            class="mr-2 underline"
            @click="form.entity_type = t.value"
          >
            {{ t.value }}
          </button>
        </p>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <FormInput v-model="form.code" name="code" label="Code" placeholder="court_register" :error="form.errors.code" required />
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
            Save Field
          </button>
        </div>
      </form>
    </div>
  </AppLayout>
</template>
