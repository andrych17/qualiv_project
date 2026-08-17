<!-- ponytail: Plan create form -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import CentralAdminLayout from '@/Layouts/CentralAdminLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import FormInput from '@/Components/forms/FormInput.vue'

const form = useForm({
  code: '',
  name: '',
  description: '',
  price_monthly: 0,
  currency: 'IDR',
})

const submit = () => form.post(route('central.plans.store'))
</script>

<template>
  <CentralAdminLayout>
    <PageHeader title="Create Plan" description="Add a subscription plan to the catalog." />

    <div class="mt-6 max-w-2xl rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
      <form class="space-y-4" @submit.prevent="submit">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <FormInput v-model="form.code" name="code" label="Code" placeholder="e.g. legal" :error="form.errors.code" required />
          <FormInput v-model="form.name" name="name" label="Name" placeholder="e.g. Legal Starter" :error="form.errors.name" required />
        </div>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <FormInput v-model.number="form.price_monthly" name="price_monthly" label="Price / month" type="number" :error="form.errors.price_monthly" required />
          <FormInput v-model="form.currency" name="currency" label="Currency" :error="form.errors.currency" />
        </div>
        <FormInput v-model="form.description" name="description" label="Description" :error="form.errors.description" />

        <div class="flex items-center justify-end gap-3 border-t border-gray-100 pt-4">
          <Link :href="route('central.plans.index')" class="text-sm font-semibold text-gray-900">Cancel</Link>
          <button type="submit" :disabled="form.processing" class="rounded-md bg-gray-900 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-gray-800 disabled:opacity-50">
            Save Plan
          </button>
        </div>
      </form>
    </div>
  </CentralAdminLayout>
</template>
