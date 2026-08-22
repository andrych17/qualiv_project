<!-- ponytail: Create serial counter -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'

const form = useForm({
  code: '',
  last_cnt: 0,
  wrap_low: 1,
  wrap_high: 999999,
  step_cnt: 1,
  descr: '',
  status_code: 'A',
  padding_length: null as number | null,
  reset_rule: 'never',
})

const submit = () => form.post(route('config.serials.store'))
</script>

<template>
  <AppLayout>
    <PageHeader title="Create Serial" description="Add a document number counter for this tenant." />

    <div class="mt-6 max-w-2xl rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
      <form class="space-y-4" @submit.prevent="submit">
        <FormInput
          v-model="form.code"
          name="code"
          label="Code"
          placeholder="e.g. LEGAL_MATTER_LASTID"
          :error="form.errors.code"
          required
        />
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <FormInput v-model.number="form.wrap_low" name="wrap_low" label="Wrap low" type="number" :error="form.errors.wrap_low" required />
          <FormInput v-model.number="form.wrap_high" name="wrap_high" label="Wrap high" type="number" :error="form.errors.wrap_high" required />
        </div>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <FormInput v-model.number="form.last_cnt" name="last_cnt" label="Last count" type="number" :error="form.errors.last_cnt" required />
          <FormInput v-model.number="form.step_cnt" name="step_cnt" label="Step" type="number" :error="form.errors.step_cnt" required />
        </div>
        <FormInput v-model="form.descr" name="descr" label="Description" :error="form.errors.descr" />
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <FormInput v-model.number="form.padding_length" name="padding_length" label="Padding length" type="number" :error="form.errors.padding_length" />
          <FormSelect
            v-model="form.reset_rule"
            name="reset_rule"
            label="Reset rule"
            :options="[
              { label: 'Never', value: 'never' },
              { label: 'Yearly', value: 'yearly' },
              { label: 'Monthly', value: 'monthly' },
            ]"
            :error="form.errors.reset_rule"
          />
        </div>
        <FormSelect
          v-model="form.status_code"
          name="status_code"
          label="Status"
          :options="[
            { label: 'Active', value: 'A' },
            { label: 'Inactive', value: 'I' },
          ]"
          :error="form.errors.status_code"
          required
        />
        <div class="flex justify-end gap-3 border-t border-gray-100 pt-4">
          <Link :href="route('config.serials.index')" class="text-sm font-semibold text-gray-900">Cancel</Link>
          <button type="submit" :disabled="form.processing" class="rounded-md bg-gray-900 px-3 py-2 text-sm font-semibold text-white disabled:opacity-50">
            Save Serial
          </button>
        </div>
      </form>
    </div>
  </AppLayout>
</template>
