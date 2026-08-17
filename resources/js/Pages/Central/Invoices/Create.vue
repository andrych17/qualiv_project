<!-- ponytail: Generate an invoice for a tenant — single plan-fee line, no add-ons yet -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import CentralAdminLayout from '@/Layouts/CentralAdminLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'

defineProps<{
  tenants: Array<{ label: string; value: string }>
  plans: Array<{ label: string; value: string; price_monthly: string | number }>
}>()

const form = useForm({
  tenant_id: '',
  plan_code: '',
  billing_period_start: '',
  billing_period_end: '',
  due_date: '',
})

const submit = () => form.post(route('central.invoices.store'))
</script>

<template>
  <CentralAdminLayout>
    <PageHeader title="Generate Invoice" description="One line for the plan's monthly fee." />

    <div class="mt-6 max-w-2xl rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
      <form class="space-y-4" @submit.prevent="submit">
        <FormSelect v-model="form.tenant_id" name="tenant_id" label="Tenant" :options="tenants" :error="form.errors.tenant_id" required />
        <FormSelect v-model="form.plan_code" name="plan_code" label="Plan" :options="plans" :error="form.errors.plan_code" required />

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <FormInput v-model="form.billing_period_start" name="billing_period_start" label="Period start" type="date" :error="form.errors.billing_period_start" required />
          <FormInput v-model="form.billing_period_end" name="billing_period_end" label="Period end" type="date" :error="form.errors.billing_period_end" required />
        </div>
        <FormInput v-model="form.due_date" name="due_date" label="Due date" type="date" :error="form.errors.due_date" required />

        <div class="flex items-center justify-end gap-3 border-t border-gray-100 pt-4">
          <Link :href="route('central.invoices.index')" class="text-sm font-semibold text-gray-900">Cancel</Link>
          <button type="submit" :disabled="form.processing" class="rounded-md bg-gray-900 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-gray-800 disabled:opacity-50">
            Generate Invoice
          </button>
        </div>
      </form>
    </div>
  </CentralAdminLayout>
</template>
