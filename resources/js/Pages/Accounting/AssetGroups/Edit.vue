<!-- ponytail: Accounting §3G edit asset group. -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormNumberInput from '@/Components/forms/FormNumberInput.vue'
import FormSwitch from '@/Components/forms/FormSwitch.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'

const props = defineProps<{
  assetGroup: {
    id: number
    company_id: number
    code: string
    name: string
    is_building: boolean
    fiscal_useful_life_months: number
    fiscal_straight_line_rate: string
    fiscal_declining_rate: string | null
    is_active: boolean
  }
}>()

const form = useForm({
  code: props.assetGroup.code,
  name: props.assetGroup.name,
  is_building: props.assetGroup.is_building,
  fiscal_useful_life_months: Number(props.assetGroup.fiscal_useful_life_months) || null,
  fiscal_straight_line_rate: props.assetGroup.fiscal_straight_line_rate,
  fiscal_declining_rate: props.assetGroup.fiscal_declining_rate ?? '',
  is_active: props.assetGroup.is_active,
})

const submit = () => form.transform((data) => ({
  ...data,
  fiscal_useful_life_months: Number(data.fiscal_useful_life_months),
  fiscal_straight_line_rate: Number(data.fiscal_straight_line_rate),
  fiscal_declining_rate: data.is_building || data.fiscal_declining_rate === '' ? null : Number(data.fiscal_declining_rate),
})).put(route('accounting.asset-groups.update', props.assetGroup.id))
</script>

<template>
  <AppLayout>
    <PageHeader :title="`Edit ${assetGroup.code}`" />

    <Panel class="mt-6 max-w-xl">
      <form class="space-y-4" @submit.prevent="submit">
        <FormInput v-model="form.code" name="code" label="Group Code" :error="form.errors.code" required />
        <FormInput v-model="form.name" name="name" label="Group Name" :error="form.errors.name" required />

        <FormSwitch
          v-model="form.is_building"
          name="is_building"
          label="This is a building group (fiscal method locked to straight-line)"
        />

        <FormNumberInput v-model="form.fiscal_useful_life_months" name="fiscal_useful_life_months" label="Fiscal Useful Life (Months)" suffix="months" :error="form.errors.fiscal_useful_life_months" required />
        <FormInput v-model="form.fiscal_straight_line_rate" name="fiscal_straight_line_rate" type="number" step="0.0001" label="Fiscal Straight-Line Rate (annual)" :error="form.errors.fiscal_straight_line_rate" required />
        <FormInput
          v-if="!form.is_building"
          v-model="form.fiscal_declining_rate"
          name="fiscal_declining_rate"
          type="number"
          step="0.0001"
          label="Fiscal Declining-Balance Rate (annual)"
          :error="form.errors.fiscal_declining_rate"
        />

        <FormSwitch
          v-model="form.is_active"
          name="is_active"
          label="Active Status"
        />

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <SecondaryButton :href="route('accounting.asset-groups.index', { company_id: assetGroup.company_id })">
            Cancel
          </SecondaryButton>
          <PrimaryButton type="submit" :disabled="form.processing">Save Changes</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
