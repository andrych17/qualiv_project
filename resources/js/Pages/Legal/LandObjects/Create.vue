<!-- ponytail: Register land object (§3H) -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormAsyncSearchableSelect from '@/Components/forms/FormAsyncSearchableSelect.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'

const props = defineProps<{
  certificateTypes: string[]
}>()

const form = useForm({
  certificate_type: 'SHM',
  certificate_number: '',
  nib: '',
  address: '',
  area_m2: '',
  njop_reference: '',
  current_owner_partner_id: null as number | null,
  status: 'active',
})

const submit = () => form.post(route('legal.landObjects.store'))
</script>

<template>
  <AppLayout>
    <PageHeader title="Register land object" description="One record per parcel/certificate — reused across due diligence and future deeds." />

    <Panel class="mt-6 max-w-xl">
      <form class="space-y-4" @submit.prevent="submit">
        <FormSelect
          v-model="form.certificate_type"
          name="certificate_type"
          label="Certificate type"
          :options="certificateTypes.map((t) => ({ label: t, value: t }))"
          :error="form.errors.certificate_type"
          required
        />
        <FormInput v-model="form.certificate_number" name="certificate_number" label="Certificate number" :error="form.errors.certificate_number" required />
        <FormInput v-model="form.nib" name="nib" label="NIB" :error="form.errors.nib" />
        <FormInput v-model="form.address" name="address" label="Address / location" :error="form.errors.address" required />
        <div class="grid grid-cols-2 gap-4">
          <FormInput v-model="form.area_m2" name="area_m2" type="number" label="Area (m²)" :error="form.errors.area_m2" />
          <FormInput v-model="form.njop_reference" name="njop_reference" label="NJOP reference" :error="form.errors.njop_reference" />
        </div>
        <FormAsyncSearchableSelect
          v-model="form.current_owner_partner_id"
          name="current_owner_partner_id"
          label="Current registered owner (informational)"
          api-entity="crm_partner"
          placeholder="Search — the certificate remains source of truth"
          :error="form.errors.current_owner_partner_id"
        />
        <FormSelect
          v-model="form.status"
          name="status"
          label="Status"
          :options="[
            { label: 'Active', value: 'active' },
            { label: 'In transaction', value: 'in_transaction' },
            { label: 'Transferred', value: 'transferred' },
            { label: 'Disputed', value: 'disputed' },
          ]"
          :error="form.errors.status"
          required
        />
        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <Link
            :href="route('legal.landObjects.index')"
            class="inline-flex items-center justify-center rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm font-semibold text-ink-900 shadow-sm transition hover:bg-surface-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            Cancel
          </Link>
          <PrimaryButton type="submit" :disabled="form.processing">Register</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
