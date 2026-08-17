<!-- ponytail: Tenant registration — creating this row triggers the existing stancl
     provisioning pipeline (DB create + schema create + migrate), synchronously. -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import CentralAdminLayout from '@/Layouts/CentralAdminLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
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

    <div class="mt-6 max-w-2xl rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
      <form class="space-y-4" @submit.prevent="submit">
        <FormInput v-model="form.name" name="name" label="Company / legal name" :error="form.errors.name" required />
        <FormSelect v-model="form.plan_code" name="plan_code" label="Plan" :options="plans" :error="form.errors.plan_code" required />

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <FormInput v-model="form.contact_name" name="contact_name" label="Contact name" :error="form.errors.contact_name" />
          <FormInput v-model="form.contact_email" name="contact_email" label="Contact email" type="email" :error="form.errors.contact_email" />
        </div>
        <FormInput v-model="form.contact_phone" name="contact_phone" label="Contact phone" :error="form.errors.contact_phone" />
        <FormInput v-model="form.billing_address" name="billing_address" label="Billing address" :error="form.errors.billing_address" />

        <div class="flex items-center justify-end gap-3 border-t border-gray-100 pt-4">
          <Link :href="route('central.tenants.index')" class="text-sm font-semibold text-gray-900">Cancel</Link>
          <button type="submit" :disabled="form.processing" class="rounded-md bg-gray-900 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-gray-800 disabled:opacity-50">
            {{ form.processing ? 'Provisioning…' : 'Register & Provision' }}
          </button>
        </div>
      </form>
    </div>
  </CentralAdminLayout>
</template>
