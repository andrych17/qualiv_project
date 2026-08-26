<!-- ponytail: Add vendor profile (§3G) — extends an existing CRM partner (role=Vendor),
     no name/address fields here, those live on the Partner record already. -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import FormSwitch from '@/Components/forms/FormSwitch.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import PurchaseSubNav from '@/Components/purchase/PurchaseSubNav.vue'

defineProps<{
  eligiblePartners: Array<{ id: number; name: string; type: string }>
}>()

const form = useForm({
  partner_id: null as number | null,
  payment_terms_days: 30,
  incoterms: '',
  preferred_currency: 'IDR',
  tax_registration_no: '',
  bank_name: '',
  bank_account: '',
  is_preferred: false,
  onboarding_status: 'pending',
})

const submit = () => form.post(route('purchase.vendors.store'))
</script>

<template>
  <AppLayout>
    <PageHeader title="Add vendor" description="Extend an existing CRM partner (role: Vendor) with procurement details." />

    <div class="mt-4">
      <PurchaseSubNav active="vendors" />
    </div>

    <Panel class="mt-6 max-w-xl">
      <form class="space-y-4" @submit.prevent="submit">
        <FormSelect
          v-model="form.partner_id"
          name="partner_id"
          label="Partner"
          placeholder="Select a vendor partner"
          :options="eligiblePartners.map((p) => ({ label: p.name, value: p.id }))"
          :error="form.errors.partner_id"
          required
        />
        <FormInput
          v-model="form.payment_terms_days"
          name="payment_terms_days"
          type="number"
          label="Payment terms (days)"
          :error="form.errors.payment_terms_days"
        />
        <FormInput v-model="form.incoterms" name="incoterms" label="Incoterms" :error="form.errors.incoterms" />
        <FormInput
          v-model="form.preferred_currency"
          name="preferred_currency"
          label="Preferred currency"
          :error="form.errors.preferred_currency"
        />
        <FormInput
          v-model="form.tax_registration_no"
          name="tax_registration_no"
          label="Tax registration no."
          :error="form.errors.tax_registration_no"
        />
        <FormInput v-model="form.bank_name" name="bank_name" label="Bank name" :error="form.errors.bank_name" />
        <FormInput v-model="form.bank_account" name="bank_account" label="Bank account" :error="form.errors.bank_account" />
        <FormSwitch v-model="form.is_preferred" name="is_preferred" label="Preferred vendor" />
        <FormSelect
          v-model="form.onboarding_status"
          name="onboarding_status"
          label="Onboarding status"
          :options="[
            { label: 'Pending', value: 'pending' },
            { label: 'Active', value: 'active' },
            { label: 'Suspended', value: 'suspended' },
          ]"
          :error="form.errors.onboarding_status"
        />

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <SecondaryButton :href="route('purchase.vendors.index')">
            Cancel
          </SecondaryButton>
          <PrimaryButton type="submit" :disabled="form.processing">Save vendor</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
