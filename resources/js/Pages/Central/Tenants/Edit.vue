<!-- ponytail: Tenant edit — does not touch tenant_db_name / re-provision anything -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import CentralAdminLayout from '@/Layouts/CentralAdminLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'

const props = defineProps<{
  tenant: { id: string; name: string; plan: string; contact_name: string | null; contact_email: string | null; contact_phone: string | null; billing_address: string | null }
  plans: Array<{ label: string; value: string }>
  addons: Array<{ id: number; module_code: string; price_override: string | number | null; added_at: string }>
  availableModules: string[]
}>()

const form = useForm({
  name: props.tenant.name,
  plan_code: props.tenant.plan,
  contact_name: props.tenant.contact_name ?? '',
  contact_email: props.tenant.contact_email ?? '',
  contact_phone: props.tenant.contact_phone ?? '',
  billing_address: props.tenant.billing_address ?? '',
})

const submit = () => form.put(route('central.tenants.update', props.tenant.id))

const addonForm = useForm({ module_code: '', price_override: null as number | null })
const addAddon = () => addonForm.post(route('central.tenants.addons.store', props.tenant.id), { onSuccess: () => addonForm.reset() })
const removeAddon = (addonId: number) => useForm({}).delete(route('central.tenants.addons.destroy', [props.tenant.id, addonId]))
</script>

<template>
  <CentralAdminLayout>
    <PageHeader :title="`Edit Tenant: ${tenant.name}`" :description="`Tenant ID ${tenant.id}`" />

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
            Save Tenant
          </button>
        </div>
      </form>
    </div>

    <div class="mt-6 max-w-2xl rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
      <h2 class="text-sm font-semibold text-gray-900">À la carte addons</h2>
      <p class="mt-1 text-sm text-gray-500">Modules bought on top of the base plan (CENTRAL_SPECS.md §3C).</p>

      <ul class="mt-4 space-y-2">
        <li v-for="addon in addons" :key="addon.id" class="flex items-center justify-between rounded-md border border-gray-100 px-3 py-2 text-sm">
          <span>{{ addon.module_code }}</span>
          <button type="button" class="text-sm font-semibold text-red-600" @click="removeAddon(addon.id)">Remove</button>
        </li>
        <li v-if="addons.length === 0" class="text-sm text-gray-400">No addons.</li>
      </ul>

      <form class="mt-4 flex items-end gap-3" @submit.prevent="addAddon">
        <FormSelect
          v-model="addonForm.module_code"
          name="addon_module_code"
          label="Add module"
          :options="availableModules.map((code) => ({ label: code, value: code }))"
          :error="addonForm.errors.module_code"
        />
        <button type="submit" :disabled="addonForm.processing || !addonForm.module_code" class="rounded-md bg-gray-900 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-gray-800 disabled:opacity-50">
          Add
        </button>
      </form>
    </div>
  </CentralAdminLayout>
</template>
