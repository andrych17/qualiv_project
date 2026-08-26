<!-- Purchase Contract Create (§3H) -->
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

const props = defineProps<{ vendors: VendorItem[] }>()

const form = useForm({
  supplier_id: null as number | null,
  title: '',
  type: 'project',
  value: null as number | null,
  currency_code: 'IDR',
  start_date: new Date().toISOString().slice(0, 10),
  end_date: new Date(Date.now() + 365 * 24 * 60 * 60 * 1000).toISOString().slice(0, 10),
  auto_renew: false,
  notice_period_days: 30,
})

const submit = () => form.post(route('purchase.contracts.store'))
</script>

<template>
  <AppLayout>
    <PageHeader title="New Procurement Contract" description="Register a framework agreement, blanket PO, or project contract with spend ceilings (§3H).">
      <template #actions>
        <SecondaryButton :href="route('purchase.contracts.index')">Cancel</SecondaryButton>
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
            placeholder="e.g. Master Supply Agreement 2026 - Hardware & Peripherals"
            :error="form.errors.title"
            required
          />
        </div>

        <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
          <FormCurrencyInput
            v-model="form.value"
            name="value"
            label="Contract Ceiling Value (Optional)"
            placeholder="0"
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
        <SecondaryButton :href="route('purchase.contracts.index')">Cancel</SecondaryButton>
        <PrimaryButton type="submit" :disabled="form.processing">
          Save Contract
        </PrimaryButton>
      </div>
    </form>
  </AppLayout>
</template>
