<!-- ponytail: Accounting §3M PPh withholding types — edit form. -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import FormSearchableSelect from '@/Components/forms/FormSearchableSelect.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'

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
  rate: props.withholdingType.rate,
  is_final: props.withholdingType.is_final,
  gl_payable_account_id: props.withholdingType.gl_payable_account_id,
  is_active: props.withholdingType.is_active,
})

const submit = () => form.transform((data) => ({ ...data, rate: Number(data.rate) })).put(route('accounting.withholding-types.update', props.withholdingType.id))
</script>

<template>
  <AppLayout>
    <PageHeader title="Edit withholding type" :description="withholdingType.code" />

    <Panel class="mt-6 max-w-xl">
      <form class="space-y-4" @submit.prevent="submit">
        <FormInput v-model="form.code" name="code" label="Code" :error="form.errors.code" required />
        <FormSelect
          v-model="form.bp_type"
          name="bp_type"
          label="Bukti Potong type"
          placeholder="None — bills using this type can't post until set"
          :options="bpTypes.map((t) => ({ label: t, value: t }))"
          :error="form.errors.bp_type"
        />
        <FormInput v-model="form.name" name="name" label="Name" :error="form.errors.name" required />
        <FormInput v-model="form.rate" name="rate" type="number" label="Rate (%)" :error="form.errors.rate" required />
        <FormSearchableSelect v-model="form.gl_payable_account_id" name="gl_payable_account_id" label="Payable GL account" :options="accounts" :error="form.errors.gl_payable_account_id" required />

        <label class="flex items-center gap-2 text-sm text-ink-900">
          <input v-model="form.is_final" type="checkbox" class="rounded border-border" />
          Final (e.g. PPh 4(2) on rent)
        </label>
        <label class="flex items-center gap-2 text-sm text-ink-900">
          <input v-model="form.is_active" type="checkbox" class="rounded border-border" />
          Active
        </label>

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <Link
            :href="route('accounting.withholding-types.index', { company_id: withholdingType.company_id })"
            class="inline-flex items-center justify-center rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm font-semibold text-ink-900 shadow-sm transition hover:bg-surface-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            Cancel
          </Link>
          <PrimaryButton type="submit" :disabled="form.processing">Save changes</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
