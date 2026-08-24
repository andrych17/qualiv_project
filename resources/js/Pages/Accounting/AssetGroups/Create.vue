<!-- ponytail: Accounting §3G new asset group. -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSearchableSelect from '@/Components/forms/FormSearchableSelect.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'

const props = defineProps<{
  companies: Array<{ id: number; legal_name: string }>
  selectedCompanyId: number | null
}>()

const form = useForm({
  company_id: props.selectedCompanyId,
  code: '',
  name: '',
  is_building: false,
  fiscal_useful_life_months: '',
  fiscal_straight_line_rate: '',
  fiscal_declining_rate: '',
})

const submit = () => form.transform((data) => ({
  ...data,
  fiscal_useful_life_months: Number(data.fiscal_useful_life_months),
  fiscal_straight_line_rate: Number(data.fiscal_straight_line_rate),
  fiscal_declining_rate: data.is_building || data.fiscal_declining_rate === '' ? null : Number(data.fiscal_declining_rate),
})).post(route('accounting.asset-groups.store'))
</script>

<template>
  <AppLayout>
    <PageHeader title="New asset group" />

    <Panel class="mt-6 max-w-xl">
      <form class="space-y-4" @submit.prevent="submit">
        <FormSearchableSelect v-model="form.company_id" name="company_id" label="Company" :options="companies.map((c) => ({ value: c.id, label: c.legal_name }))" :error="form.errors.company_id" required />
        <FormInput v-model="form.code" name="code" label="Code" placeholder="KELOMPOK_1" :error="form.errors.code" required />
        <FormInput v-model="form.name" name="name" label="Name" :error="form.errors.name" required />

        <label class="flex items-center gap-2 text-sm text-ink-900">
          <input v-model="form.is_building" type="checkbox" class="rounded border-border" />
          This is a building group (fiscal method locked to straight-line)
        </label>

        <FormInput v-model="form.fiscal_useful_life_months" name="fiscal_useful_life_months" type="number" label="Fiscal useful life (months)" :error="form.errors.fiscal_useful_life_months" required />
        <FormInput v-model="form.fiscal_straight_line_rate" name="fiscal_straight_line_rate" type="number" step="0.0001" label="Fiscal straight-line rate (annual, e.g. 0.25)" :error="form.errors.fiscal_straight_line_rate" required />
        <FormInput
          v-if="!form.is_building"
          v-model="form.fiscal_declining_rate"
          name="fiscal_declining_rate"
          type="number"
          step="0.0001"
          label="Fiscal declining-balance rate (annual, e.g. 0.50)"
          :error="form.errors.fiscal_declining_rate"
        />

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <Link
            :href="route('accounting.asset-groups.index', { company_id: form.company_id })"
            class="inline-flex items-center justify-center rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm font-semibold text-ink-900 shadow-sm transition hover:bg-surface-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            Cancel
          </Link>
          <PrimaryButton type="submit" :disabled="form.processing">Create group</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
