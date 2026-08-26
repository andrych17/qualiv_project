<!-- Plan edit form -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import CentralAdminLayout from '@/Layouts/CentralAdminLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormCurrencyInput from '@/Components/forms/FormCurrencyInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import FormSwitch from '@/Components/forms/FormSwitch.vue'
import FormMultiSelect from '@/Components/forms/FormMultiSelect.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'

const props = defineProps<{
  plan: { id: number; code: string; name: string; description: string | null; price_monthly: string | number; price_annual: string | number | null; billing_cycle: string; currency: string; is_active: boolean; module_codes: string[] }
  availableModules: string[]
}>()

const form = useForm({
  name: props.plan.name,
  description: props.plan.description ?? '',
  price_monthly: props.plan.price_monthly !== null ? Number(props.plan.price_monthly) : 0,
  price_annual: props.plan.price_annual !== null ? Number(props.plan.price_annual) : 0,
  billing_cycle: props.plan.billing_cycle ?? 'monthly',
  currency: props.plan.currency,
  is_active: props.plan.is_active,
  module_codes: [...props.plan.module_codes],
})

const billingCycleOptions = [
  { label: 'Monthly', value: 'monthly' },
  { label: 'Annual', value: 'annual' },
]

const submit = () => form.put(route('central.plans.update', props.plan.id))
</script>

<template>
  <CentralAdminLayout>
    <PageHeader :title="`Edit Plan: ${plan.code}`" description="Pricing changes apply to future invoices only." />

    <Panel class="mt-6 max-w-2xl">
      <form class="space-y-4" @submit.prevent="submit">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <FormInput v-model="form.name" name="name" label="Name" :error="form.errors.name" required />
          <FormCurrencyInput v-model="form.price_monthly" name="price_monthly" label="Price / month" :error="form.errors.price_monthly" show-terbilang required />
        </div>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <FormCurrencyInput v-model="form.price_annual" name="price_annual" label="Price / year (annual plans)" :error="form.errors.price_annual" />
          <FormSelect
            v-model="form.billing_cycle"
            name="billing_cycle"
            label="Billing cycle"
            :options="billingCycleOptions"
            :error="form.errors.billing_cycle"
            required
          />
        </div>
        <FormInput v-model="form.description" name="description" label="Description" :error="form.errors.description" />

        <FormSwitch
          v-model="form.is_active"
          label="Active Plan"
          description="New tenants can be assigned this plan when active."
        />

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
