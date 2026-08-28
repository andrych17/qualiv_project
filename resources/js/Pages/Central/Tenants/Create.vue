<!-- ponytail: Tenant registration — creating this row triggers the existing stancl
     provisioning pipeline (DB create + schema create + migrate), synchronously. -->
<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import CentralAdminLayout from '@/Layouts/CentralAdminLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'

defineProps<{ plans: Array<{ label: string; value: string }> }>()

const form = useForm({
  name: '',
  plan_code: '',
  contact_name: '',
  contact_email: '',
  contact_phone: '',
  billing_address: '',
})

const submit = () => form.post(route('central.tenants.store'))
</script>

<template>
  <CentralAdminLayout>
    <PageHeader title="Register Tenant" description="Creates the tenant record and provisions their database immediately." />

    <div class="mt-6 max-w-2xl">
      <Panel>
        <form class="space-y-4" @submit.prevent="submit">
          <FormInput v-model="form.name" name="name" label="Company / legal name" :error="form.errors.name" required />
          <FormSelect v-model="form.plan_code" name="plan_code" label="Plan" :options="plans" :error="form.errors.plan_code" required />

          <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <FormInput v-model="form.contact_name" name="contact_name" label="Contact name" :error="form.errors.contact_name" />
            <FormInput v-model="form.contact_email" name="contact_email" label="Contact email" type="email" :error="form.errors.contact_email" />
          </div>
          <FormInput v-model="form.contact_phone" name="contact_phone" label="Contact phone" :error="form.errors.contact_phone" />
          <FormInput v-model="form.billing_address" name="billing_address" label="Billing address" :error="form.errors.billing_address" />

          <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
            <SecondaryButton :href="route('central.tenants.index')">Cancel</SecondaryButton>
            <PrimaryButton type="submit" :disabled="form.processing">
              {{ form.processing ? 'Provisioning…' : 'Register & Provision' }}
            </PrimaryButton>
          </div>
        </form>
      </Panel>
    </div>
  </CentralAdminLayout>
</template>
