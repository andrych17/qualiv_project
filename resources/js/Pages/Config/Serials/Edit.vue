<!-- ponytail: Edit serial counter -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'

const props = defineProps<{
  snum: {
    id: number
    code: string
    last_cnt: number
    wrap_low: number
    wrap_high: number
    step_cnt: number
    descr: string | null
    status_code: string
  }
}>()

const form = useForm({
  code: props.snum.code,
  last_cnt: props.snum.last_cnt,
  wrap_low: props.snum.wrap_low,
  wrap_high: props.snum.wrap_high,
  step_cnt: props.snum.step_cnt,
  descr: props.snum.descr ?? '',
  status_code: props.snum.status_code,
})

const submit = () => form.put(route('config.serials.update', props.snum.id))
</script>

<template>
  <AppLayout>
    <PageHeader title="Edit Serial" :description="snum.code" />

    <div class="mt-6 max-w-2xl rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
      <form class="space-y-4" @submit.prevent="submit">
        <FormInput v-model="form.code" name="code" label="Code" :error="form.errors.code" required />
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <FormInput v-model.number="form.wrap_low" name="wrap_low" label="Wrap low" type="number" :error="form.errors.wrap_low" required />
          <FormInput v-model.number="form.wrap_high" name="wrap_high" label="Wrap high" type="number" :error="form.errors.wrap_high" required />
        </div>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <FormInput v-model.number="form.last_cnt" name="last_cnt" label="Last count" type="number" :error="form.errors.last_cnt" required />
          <FormInput v-model.number="form.step_cnt" name="step_cnt" label="Step" type="number" :error="form.errors.step_cnt" required />
        </div>
        <FormInput v-model="form.descr" name="descr" label="Description" :error="form.errors.descr" />
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
            Update Serial
          </button>
        </div>
      </form>
    </div>
  </AppLayout>
</template>
