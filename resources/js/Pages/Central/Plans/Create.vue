<!-- ponytail: Plan create form -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import CentralAdminLayout from '@/Layouts/CentralAdminLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import FormInput from '@/Components/forms/FormInput.vue'

defineProps<{ availableModules: string[] }>()

const form = useForm({
  code: '',
  name: '',
  description: '',
  price_monthly: 0,
  price_annual: 0,
  billing_cycle: 'monthly',
  currency: 'IDR',
  module_codes: [] as string[],
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
          <FormInput v-model.number="form.price_annual" name="price_annual" label="Price / year (annual plans)" type="number" :error="form.errors.price_annual" />
        </div>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <label class="space-y-1">
            <span class="text-sm font-medium text-gray-700">Billing cycle</span>
            <select v-model="form.billing_cycle" name="billing_cycle" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm">
              <option value="monthly">Monthly</option>
              <option value="annual">Annual</option>
            </select>
            <span v-if="form.errors.billing_cycle" class="text-sm text-red-600">{{ form.errors.billing_cycle }}</span>
          </label>
          <FormInput v-model="form.currency" name="currency" label="Currency" :error="form.errors.currency" />
        </div>
        <FormInput v-model="form.description" name="description" label="Description" :error="form.errors.description" />

        <div class="space-y-1.5">
          <span class="text-sm font-medium text-gray-700">Included modules</span>
          <div class="grid grid-cols-2 gap-2 rounded-md border border-gray-200 p-3 sm:grid-cols-3">
            <label v-for="code in availableModules" :key="code" class="flex items-center gap-2 text-sm text-gray-700">
              <input type="checkbox" :value="code" v-model="form.module_codes" class="rounded border-gray-300" />
              {{ code }}
            </label>
          </div>
        </div>

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
