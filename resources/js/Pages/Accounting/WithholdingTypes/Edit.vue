<!-- ponytail: Accounting §3M PPh withholding types — edit form. -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormNumberInput from '@/Components/forms/FormNumberInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import FormSearchableSelect from '@/Components/forms/FormSearchableSelect.vue'
import FormSwitch from '@/Components/forms/FormSwitch.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'

const props = defineProps<{
  withholdingType: {
    id: number
    company_id: number
    code: string
    bp_type: string | null
    name: string
    rate: number
    is_final: boolean
    gl_payable_account_id: number
    is_active: boolean
  }
  accounts: Array<{ value: number; label: string }>
  bpTypes: string[]
}>()

const form = useForm({
  code: props.withholdingType.code,
  bp_type: props.withholdingType.bp_type,
  name: props.withholdingType.name,
  rate: Number(props.withholdingType.rate) || null,
  is_final: props.withholdingType.is_final,
  gl_payable_account_id: props.withholdingType.gl_payable_account_id,
  is_active: props.withholdingType.is_active,
})

const submit = () => form.transform((data) => ({ ...data, rate: Number(data.rate) })).put(route('accounting.withholding-types.update', props.withholdingType.id))
</script>

<template>
  <AppLayout>
    <PageHeader title="Edit Withholding Type" :description="withholdingType.code" />

    <Panel class="mt-6 max-w-xl">
      <form class="space-y-4" @submit.prevent="submit">
        <FormInput v-model="form.code" name="code" label="Withholding Code" :error="form.errors.code" required />
        <FormSelect
          v-model="form.bp_type"
          name="bp_type"
          label="Bukti Potong Type"
          placeholder="Select Bukti Potong type..."
          :options="bpTypes.map((t) => ({ label: t, value: t }))"
          :error="form.errors.bp_type"
        />
        <FormInput v-model="form.name" name="name" label="Withholding Type Name" :error="form.errors.name" required />
        <FormNumberInput v-model="form.rate" name="rate" label="Withholding Rate (%)" suffix="%" :error="form.errors.rate" required />
        <FormSearchableSelect v-model="form.gl_payable_account_id" name="gl_payable_account_id" label="Payable GL Account" :options="accounts" :error="form.errors.gl_payable_account_id" required />

        <FormSwitch
          v-model="form.is_final"
          name="is_final"
          label="Final Tax"
        />
        <FormSwitch
          v-model="form.is_active"
          name="is_active"
          label="Active Status"
        />

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <SecondaryButton :href="route('accounting.withholding-types.index', { company_id: withholdingType.company_id })">
            Cancel
          </SecondaryButton>
          <PrimaryButton type="submit" :disabled="form.processing">Save Changes</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
