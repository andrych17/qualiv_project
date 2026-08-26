<!-- Purchase Contract Edit (§3H) -->
<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import FormCurrencyInput from '@/Components/forms/FormCurrencyInput.vue'
import FormSwitch from '@/Components/forms/FormSwitch.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'

interface VendorItem {
  id: number
  name: string
}

interface ContractData {
  id: number
  supplier_id: number
  title: string
  type: string
  value: number | null
  currency_code: string
  start_date: string
  end_date: string
  auto_renew: boolean
  notice_period_days: number
  status: string
}

const props = defineProps<{
  contract: ContractData
  vendors: VendorItem[]
}>()

const form = useForm({
  supplier_id: props.contract.supplier_id,
  title: props.contract.title,
  type: props.contract.type,
  value: props.contract.value,
  currency_code: props.contract.currency_code,
  start_date: props.contract.start_date,
  end_date: props.contract.end_date,
  auto_renew: Boolean(props.contract.auto_renew),
  notice_period_days: props.contract.notice_period_days,
})

const submit = () => form.put(route('purchase.contracts.update', props.contract.id))
</script>

<template>
  <AppLayout>
    <PageHeader :title="`Edit Contract: ${contract.title}`" description="Update contract specifications, ceiling value, and renewal rules (§3H).">
      <template #actions>
        <SecondaryButton :href="route('purchase.contracts.show', contract.id)">Cancel</SecondaryButton>
      </template>
    </PageHeader>

    <form class="mt-6 space-y-6 max-w-3xl" @submit.prevent="submit">
      <Panel title="Contract Information">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <FormSelect
            v-model="form.supplier_id"
            name="supplier_id"
            label="Supplier / Vendor"
            placeholder="Select vendor"
            :options="vendors.map((v) => ({ label: v.name, value: v.id }))"
            :error="form.errors.supplier_id"
            required
          />

          <FormSelect
            v-model="form.type"
            name="type"
            label="Contract Type"
            :options="[
              { label: 'Project Contract', value: 'project' },
              { label: 'Framework Agreement', value: 'framework' },
              { label: 'Blanket Order', value: 'blanket' },
            ]"
            :error="form.errors.type"
            required
          />
        </div>

        <div class="mt-4">
          <FormInput
            v-model="form.title"
            name="title"
            label="Contract Title"
            :error="form.errors.title"
            required
          />
        </div>

        <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
          <FormCurrencyInput
            v-model="form.value"
            name="value"
            label="Contract Ceiling Value"
            :error="form.errors.value"
          />

          <FormInput
            v-model="form.currency_code"
            name="currency_code"
            label="Currency Code"
            maxlength="3"
            :error="form.errors.currency_code"
          />
        </div>
      </Panel>

      <Panel title="Validity & Renewal Terms">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <FormInput
            v-model="form.start_date"
            name="start_date"
            type="date"
            label="Start Date"
            :error="form.errors.start_date"
            required
          />

          <FormInput
            v-model="form.end_date"
            name="end_date"
            type="date"
            label="End Date"
            :error="form.errors.end_date"
            required
          />
        </div>

        <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4 items-center">
          <FormInput
            v-model.number="form.notice_period_days"
            name="notice_period_days"
            type="number"
            min="1"
            max="365"
            label="Renewal Notice Period (Days)"
            :error="form.errors.notice_period_days"
          />

          <div class="pt-4">
            <FormSwitch
              v-model="form.auto_renew"
              name="auto_renew"
              label="Enable Auto-Renewal Reminder"
              description="Sends an alert before the notice period expires."
            />
          </div>
        </div>
      </Panel>

      <div class="flex justify-end gap-3">
        <SecondaryButton :href="route('purchase.contracts.show', contract.id)">Cancel</SecondaryButton>
        <PrimaryButton type="submit" :disabled="form.processing">
          Save Changes
        </PrimaryButton>
      </div>
    </form>
  </AppLayout>
</template>
