<!-- ponytail: Accounting §3M PPN tax codes — edit form. -->
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
  taxCode: {
    id: number
    company_id: number
    code: string
    rate: number
    tax_type: string
    gl_account_id: number
    is_active: boolean
  }
  accounts: Array<{ value: number; label: string }>
}>()

const form = useForm({
  code: props.taxCode.code,
  rate: Number(props.taxCode.rate) || null,
  tax_type: props.taxCode.tax_type,
  gl_account_id: props.taxCode.gl_account_id,
  is_active: props.taxCode.is_active,
})

const submit = () => form.transform((data) => ({ ...data, rate: Number(data.rate) })).put(route('accounting.tax-codes.update', props.taxCode.id))
</script>

<template>
  <AppLayout>
    <PageHeader title="Edit Tax Code" :description="taxCode.code" />

    <Panel class="mt-6 max-w-xl">
      <form class="space-y-4" @submit.prevent="submit">
        <FormInput v-model="form.code" name="code" label="Tax Code" :error="form.errors.code" required />
        <FormNumberInput v-model="form.rate" name="rate" label="Tax Rate (%)" suffix="%" :error="form.errors.rate" required />
        <FormSelect
          v-model="form.tax_type"
          name="tax_type"
          label="Tax Type"
          :options="[{ label: 'Output (Keluaran — Sales)', value: 'output' }, { label: 'Input (Masukan — Purchase)', value: 'input' }]"
          :error="form.errors.tax_type"
          required
        />
        <FormSearchableSelect v-model="form.gl_account_id" name="gl_account_id" label="GL Account" :options="accounts" :error="form.errors.gl_account_id" required />

        <FormSwitch
          v-model="form.is_active"
          name="is_active"
          label="Active Status"
        />

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <SecondaryButton :href="route('accounting.tax-codes.index', { company_id: taxCode.company_id })">
            Cancel
          </SecondaryButton>
          <PrimaryButton type="submit" :disabled="form.processing">Save Changes</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
