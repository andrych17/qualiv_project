<!-- Plan create form -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import CentralAdminLayout from '@/Layouts/CentralAdminLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormCurrencyInput from '@/Components/forms/FormCurrencyInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import FormMultiSelect from '@/Components/forms/FormMultiSelect.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'

defineProps<{ availableModules: string[] }>()

const form = useForm({
  code: '',
  name: '',
  description: '',
  price_monthly: 0 as number | null,
  price_annual: 0 as number | null,
  billing_cycle: 'monthly',
  currency: 'IDR',
  module_codes: [] as string[],
})

const billingCycleOptions = [
  { label: 'Monthly', value: 'monthly' },
  { label: 'Annual', value: 'annual' },
]

const submit = () => form.post(route('central.plans.store'))
</script>

<template>
  <CentralAdminLayout>
    <PageHeader title="Create Plan" description="Add a subscription plan to the catalog." />

    <Panel class="mt-6 max-w-2xl">
      <form class="space-y-4" @submit.prevent="submit">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <FormInput v-model="form.code" name="code" label="Code" placeholder="e.g. legal" :error="form.errors.code" required />
          <FormInput v-model="form.name" name="name" label="Name" placeholder="e.g. Legal Starter" :error="form.errors.name" required />
        </div>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <FormCurrencyInput v-model="form.price_monthly" name="price_monthly" label="Price / month" :error="form.errors.price_monthly" show-terbilang required />
          <FormCurrencyInput v-model="form.price_annual" name="price_annual" label="Price / year (annual plans)" :error="form.errors.price_annual" />
        </div>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <FormSelect
            v-model="form.billing_cycle"
            name="billing_cycle"
            label="Billing cycle"
            :options="billingCycleOptions"
            :error="form.errors.billing_cycle"
            required
          />
          <FormInput v-model="form.currency" name="currency" label="Currency" :error="form.errors.currency" />
        </div>
        <FormInput v-model="form.description" name="description" label="Description" :error="form.errors.description" />

        <FormMultiSelect
          v-model="form.module_codes"
          name="module_codes"
          label="Included Modules"
          placeholder="Pilih modul yang disertakan..."
          :options="availableModules.map((m) => ({ label: m, value: m }))"
          :error="form.errors.module_codes"
        />

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <Link :href="route('central.plans.index')">
            <SecondaryButton type="button">Cancel</SecondaryButton>
          </Link>
          <PrimaryButton type="submit" :disabled="form.processing">
            Save Plan
          </PrimaryButton>
        </div>
      </form>
    </Panel>
  </CentralAdminLayout>
</template>
