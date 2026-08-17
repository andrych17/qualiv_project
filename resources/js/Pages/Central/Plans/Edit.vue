<!-- ponytail: Plan edit form -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import CentralAdminLayout from '@/Layouts/CentralAdminLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import FormInput from '@/Components/forms/FormInput.vue'

const props = defineProps<{
  plan: { id: number; code: string; name: string; description: string | null; price_monthly: string | number; currency: string; is_active: boolean }
}>()

const form = useForm({
  name: props.plan.name,
  description: props.plan.description ?? '',
  price_monthly: Number(props.plan.price_monthly),
  currency: props.plan.currency,
  is_active: props.plan.is_active,
})

const submit = () => form.put(route('central.plans.update', props.plan.id))
</script>

<template>
  <CentralAdminLayout>
    <PageHeader :title="`Edit Plan: ${plan.code}`" description="Pricing changes apply to future invoices only." />

    <div class="mt-6 max-w-2xl rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
      <form class="space-y-4" @submit.prevent="submit">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <FormInput v-model="form.name" name="name" label="Name" :error="form.errors.name" required />
          <FormInput v-model.number="form.price_monthly" name="price_monthly" label="Price / month" type="number" :error="form.errors.price_monthly" required />
        </div>
        <FormInput v-model="form.description" name="description" label="Description" :error="form.errors.description" />

        <label class="flex items-center gap-2 text-sm text-gray-700">
          <input type="checkbox" v-model="form.is_active" class="rounded border-gray-300" />
          Active (new tenants can be assigned this plan)
        </label>

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
