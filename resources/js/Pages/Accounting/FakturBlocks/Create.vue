<!-- ponytail: Accounting §3M — create a Faktur Pajak number-allocation block. -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSearchableSelect from '@/Components/forms/FormSearchableSelect.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'

const props = defineProps<{
  companies: Array<{ id: number; legal_name: string }>
  selectedCompanyId: number | null
}>()

const form = useForm({
  company_id: props.selectedCompanyId,
  prefix: '',
  range_start: 1,
  range_end: 100,
})

const submit = () => form.transform((data) => ({
  ...data,
  range_start: Number(data.range_start),
  range_end: Number(data.range_end),
})).post(route('accounting.faktur-blocks.store'))
</script>

<template>
  <AppLayout>
    <PageHeader title="New Faktur Pajak Number Block" description="Enter the NSFP range exactly as allocated by DJP — numbers are drawn sequentially and never reused." />

    <Panel class="mt-6 max-w-xl">
      <form class="space-y-4" @submit.prevent="submit">
        <FormSearchableSelect v-model="form.company_id" name="company_id" label="Company" :options="companies.map((c) => ({ value: c.id, label: c.legal_name }))" :error="form.errors.company_id" required />
        <FormInput v-model="form.prefix" name="prefix" label="Prefix" placeholder="e.g. 010.000-26." :error="form.errors.prefix" required />
        <FormInput v-model="form.range_start" name="range_start" type="number" label="Range Start" :error="form.errors.range_start" required />
        <FormInput v-model="form.range_end" name="range_end" type="number" label="Range End" :error="form.errors.range_end" required />

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <SecondaryButton :href="route('accounting.faktur-blocks.index', { company_id: form.company_id })">
            Cancel
          </SecondaryButton>
          <PrimaryButton type="submit" :disabled="form.processing">Create Block</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
