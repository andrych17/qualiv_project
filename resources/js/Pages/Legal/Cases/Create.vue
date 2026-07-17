<!-- ponytail: Create legal case -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import CustomFieldInputs, { type CustomFieldDef } from '@/Components/forms/CustomFieldInputs.vue'

const props = defineProps<{
  customFields: CustomFieldDef[]
}>()

const customBag: Record<string, string> = {}
for (const f of props.customFields) {
  customBag[f.code] = f.value ?? ''
}

const form = useForm({
  code: '',
  title: '',
  status: 'open',
  notes: '',
  custom_fields: customBag,
})

const submit = () => form.post(route('legal.cases.store'))
</script>

<template>
  <AppLayout>
    <PageHeader title="Open Case" description="Register a new legal matter. Blank code → auto from LEGAL.CASE_PREFIX." />

    <div class="mt-6 max-w-xl rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
      <form class="space-y-4" @submit.prevent="submit">
        <FormInput v-model="form.code" name="code" label="Case code" placeholder="Leave blank to auto-generate" :error="form.errors.code" />
        <FormInput v-model="form.title" name="title" label="Title" :error="form.errors.title" required />
        <FormSelect
          v-model="form.status"
          name="status"
          label="Status"
          :options="[
            { label: 'Open', value: 'open' },
            { label: 'Pending', value: 'pending' },
            { label: 'Closed', value: 'closed' },
          ]"
          :error="form.errors.status"
          required
        />
        <div class="space-y-1.5">
          <label class="text-sm font-medium text-gray-700">Notes</label>
          <textarea
            v-model="form.notes"
            rows="3"
            class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-gray-900 focus:ring-2 focus:ring-gray-900/10"
          />
        </div>
        <CustomFieldInputs
          v-model="form.custom_fields"
          :fields="customFields"
          :errors="form.errors"
        />
        <div class="flex justify-end gap-3 border-t border-gray-100 pt-4">
          <Link :href="route('legal.cases.index')" class="text-sm font-semibold text-gray-900">Cancel</Link>
          <button type="submit" :disabled="form.processing" class="rounded-md bg-gray-900 px-3 py-2 text-sm font-semibold text-white disabled:opacity-50">
            Save Case
          </button>
        </div>
      </form>
    </div>
  </AppLayout>
</template>
