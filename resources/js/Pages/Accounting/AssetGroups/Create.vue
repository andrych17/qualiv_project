<!-- ponytail: Accounting §3G new asset group. -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormNumberInput from '@/Components/forms/FormNumberInput.vue'
import FormSearchableSelect from '@/Components/forms/FormSearchableSelect.vue'
import FormSwitch from '@/Components/forms/FormSwitch.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'

const props = defineProps<{
  companies: Array<{ id: number; legal_name: string }>
  selectedCompanyId: number | null
}>()

const form = useForm({
  company_id: props.selectedCompanyId,
  code: '',
  name: '',
  is_building: false,
  fiscal_useful_life_months: null as number | null,
  fiscal_straight_line_rate: null as number | null,
  fiscal_declining_rate: null as number | null,
})

const submit = () => form.transform((data) => ({
  ...data,
  fiscal_useful_life_months: Number(data.fiscal_useful_life_months),
  fiscal_straight_line_rate: Number(data.fiscal_straight_line_rate),
  fiscal_declining_rate: data.is_building || data.fiscal_declining_rate === null ? null : Number(data.fiscal_declining_rate),
})).post(route('accounting.asset-groups.store'))
</script>

<template>
  <AppLayout>
    <PageHeader title="New Asset Group" description="Fixed asset tax depreciation category under Indonesian tax law (UU PPh Pasal 11)." />

    <Panel class="mt-6 max-w-xl">
      <form class="space-y-4" @submit.prevent="submit">
        <FormSearchableSelect v-model="form.company_id" name="company_id" label="Company" :options="companies.map((c) => ({ value: c.id, label: c.legal_name }))" :error="form.errors.company_id" required />
        <FormInput v-model="form.code" name="code" label="Group Code" placeholder="KELOMPOK_1" :error="form.errors.code" required />
        <FormInput v-model="form.name" name="name" label="Group Name" placeholder="e.g. Non-Building Group 1" :error="form.errors.name" required />

        <FormSwitch
          v-model="form.is_building"
          name="is_building"
          label="This is a building group (fiscal method locked to straight-line)"
        />

        <FormNumberInput v-model="form.fiscal_useful_life_months" name="fiscal_useful_life_months" label="Fiscal Useful Life (Months)" suffix="months" :error="form.errors.fiscal_useful_life_months" required />
        <FormInput v-model="form.fiscal_straight_line_rate" name="fiscal_straight_line_rate" type="number" step="0.0001" label="Fiscal Straight-Line Rate (annual, e.g. 0.25)" :error="form.errors.fiscal_straight_line_rate" required />
        <FormInput
          v-if="!form.is_building"
          v-model="form.fiscal_declining_rate"
          name="fiscal_declining_rate"
          type="number"
          step="0.0001"
          label="Fiscal Declining-Balance Rate (annual, e.g. 0.50)"
          :error="form.errors.fiscal_declining_rate"
        />

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <SecondaryButton :href="route('accounting.asset-groups.index', { company_id: form.company_id })">
            Cancel
          </SecondaryButton>
          <PrimaryButton type="submit" :disabled="form.processing">Create Group</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
