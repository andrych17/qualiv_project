<!-- ponytail: Edit vendor profile (§3G) — procurement fields + doc list (§3G vendor docs). -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import FormSwitch from '@/Components/forms/FormSwitch.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import PurchaseSubNav from '@/Components/purchase/PurchaseSubNav.vue'

interface VendorDocument {
  id: number
  doc_type: string
  title: string
  expiry_date: string | null
}

const props = defineProps<{
  vendor: {
    id: number
    partner_id: number
    partner_name: string | null
    payment_terms_days: number
    incoterms: string | null
    preferred_currency: string | null
    tax_registration_no: string | null
    bank_name: string | null
    is_preferred: boolean
    onboarding_status: string
    documents: VendorDocument[]
  }
}>()

const form = useForm({
  payment_terms_days: props.vendor.payment_terms_days,
  incoterms: props.vendor.incoterms ?? '',
  preferred_currency: props.vendor.preferred_currency ?? '',
  tax_registration_no: props.vendor.tax_registration_no ?? '',
  bank_name: props.vendor.bank_name ?? '',
  bank_account: '',
  is_preferred: props.vendor.is_preferred,
  onboarding_status: props.vendor.onboarding_status,
})

const submit = () => form.patch(route('purchase.vendors.update', props.vendor.id))

const docForm = useForm({
  file: null as File | null,
  doc_type: 'other',
  title: '',
  expiry_date: '',
})

const uploadDocument = () => {
  docForm.post(route('purchase.vendors.documents.store', props.vendor.id), {
    forceFormData: true,
    onSuccess: () => docForm.reset(),
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader
      :title="`Vendor: ${vendor.partner_name ?? '#' + vendor.id}`"
      description="Procurement details, banking, compliance documents (§3G)."
    >
      <template #actions>
        <SecondaryButton :href="route('purchase.vendors.index')">Back to list</SecondaryButton>
      </template>
    </PageHeader>

    <div class="mt-4">
      <PurchaseSubNav active="vendors" />
    </div>

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
      <Panel>
        <form class="space-y-4" @submit.prevent="submit">
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
          <FormInput
            v-model="form.bank_account"
            name="bank_account"
            label="Bank account"
            placeholder="Leave blank to keep unchanged"
            :error="form.errors.bank_account"
          />
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
              Back
            </SecondaryButton>
            <PrimaryButton type="submit" :disabled="form.processing">Save</PrimaryButton>
          </div>
        </form>
      </Panel>

      <Panel>
        <h3 class="text-sm font-semibold text-ink-900">Documents</h3>
        <ul class="mt-3 divide-y divide-border">
          <li v-for="doc in vendor.documents" :key="doc.id" class="flex items-center justify-between py-2 text-sm">
            <span>{{ doc.title }} <span class="text-ink-500">({{ doc.doc_type }})</span></span>
            <span class="text-ink-500">{{ doc.expiry_date ?? '—' }}</span>
          </li>
          <li v-if="!vendor.documents.length" class="py-2 text-sm text-ink-500">No documents attached.</li>
        </ul>

        <form class="mt-4 space-y-3 border-t border-border pt-4" @submit.prevent="uploadDocument">
          <FormInput v-model="docForm.title" name="doc_title" label="Title" :error="docForm.errors.title" />
          <FormSelect
            v-model="docForm.doc_type"
            name="doc_type"
            label="Type"
            :options="[
              { label: 'License', value: 'license' },
              { label: 'Insurance', value: 'insurance' },
              { label: 'Tax certificate', value: 'tax_cert' },
              { label: 'Other', value: 'other' },
            ]"
            :error="docForm.errors.doc_type"
          />
          <FormInput
            v-model="docForm.expiry_date"
            name="expiry_date"
            type="date"
            label="Expiry date"
            :error="docForm.errors.expiry_date"
          />
          <div class="space-y-1.5">
            <label class="text-sm font-medium text-ink-900">File</label>
            <input
              type="file"
              class="block w-full text-sm text-ink-900"
              @change="(e) => (docForm.file = (e.target as HTMLInputElement).files?.[0] ?? null)"
            />
          </div>
          <PrimaryButton type="submit" :disabled="docForm.processing">Attach document</PrimaryButton>
        </form>
      </Panel>
    </div>
  </AppLayout>
</template>
