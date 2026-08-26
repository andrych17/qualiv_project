<!-- Create Contract Form (§3L) -->
<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import FormSwitch from '@/Components/forms/FormSwitch.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'

interface CustomerOption {
  id: number
  name: string
}

interface PriceListOption {
  id: number
  name: string
}

const props = defineProps<{
  customers: CustomerOption[]
  priceLists: PriceListOption[]
  intervals: string[]
}>()

const form = useForm({
  customer_id: null as number | null,
  price_list_id: null as number | null,
  name: '',
  term_start: '',
  term_end: '',
  auto_renew: true,
  subscriptions: [
    {
      description: '',
      billing_interval: 'monthly',
      recurring_amount: 0,
      next_billing_date: '',
    },
  ],
})

const addSubscription = () => {
  form.subscriptions.push({
    description: '',
    billing_interval: 'monthly',
    recurring_amount: 0,
    next_billing_date: form.term_start,
  })
}

const removeSubscription = (index: number) => {
  if (form.subscriptions.length > 1) {
    form.subscriptions.splice(index, 1)
  }
}

const submit = () => {
  form.post(route('sales.contracts.store'))
}
</script>

<template>
  <AppLayout>
    <PageHeader
      title="Create Contract Agreement"
      description="Setup service contracts and recurring billing subscription lines (§3L)."
    />

    <div class="mt-6">
      <form @submit.prevent="submit" class="space-y-6">
        <Panel title="Contract Terms">
          <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div class="sm:col-span-3">
              <FormInput
                label="Contract Agreement Title"
                name="name"
                v-model="form.name"
                :error="form.errors.name"
                placeholder="e.g. IT Managed Services & Software Retainer 2026"
                required
              />
            </div>

            <div>
              <FormSelect
                label="Customer / Client"
                name="customer_id"
                v-model="form.customer_id"
                :error="form.errors.customer_id"
                :options="props.customers.map(c => ({ value: c.id, label: c.name }))"
                placeholder="Select customer…"
                required
              />
            </div>

            <div>
              <FormInput
                label="Term Start Date"
                name="term_start"
                type="date"
                v-model="form.term_start"
                :error="form.errors.term_start"
                required
              />
            </div>

            <div>
              <FormInput
                label="Term End Date"
                name="term_end"
                type="date"
                v-model="form.term_end"
                :error="form.errors.term_end"
                required
              />
            </div>

            <div>
              <FormSelect
                label="Price List (Optional)"
                name="price_list_id"
                v-model="form.price_list_id"
                :error="form.errors.price_list_id"
                :options="props.priceLists.map(p => ({ value: p.id, label: p.name }))"
                placeholder="Default price list…"
              />
            </div>

            <div class="sm:col-span-2 pt-6">
              <FormSwitch
                v-model="form.auto_renew"
                name="auto_renew"
                label="Auto-renew Contract"
                description="Automatically extend terms upon period completion."
              />
            </div>
          </div>
        </Panel>

        <!-- Subscriptions -->
        <Panel title="Recurring Subscription Lines">
          <div class="space-y-4">
            <div
              v-for="(sub, idx) in form.subscriptions"
              :key="idx"
              class="grid grid-cols-1 gap-3 p-3 border border-border rounded-md bg-surface-50 sm:grid-cols-4 items-end"
            >
              <div class="sm:col-span-2">
                <label class="block text-xs font-medium text-ink-700 mb-1">Service / Subscription Description *</label>
                <input
                  v-model="sub.description"
                  type="text"
                  placeholder="e.g. Monthly Maintenance & Hosting"
                  class="w-full rounded border border-border bg-surface-0 py-1.5 px-2 text-sm text-ink-900 focus:outline-none"
                  required
                />
              </div>

              <div>
                <label class="block text-xs font-medium text-ink-700 mb-1">Billing Interval *</label>
                <select
                  v-model="sub.billing_interval"
                  class="w-full rounded border border-border bg-surface-0 py-1.5 px-2 text-sm text-ink-900 focus:outline-none capitalize"
                >
                  <option v-for="interval in props.intervals" :key="interval" :value="interval">
                    {{ interval }}
                  </option>
                </select>
              </div>

              <div>
                <label class="block text-xs font-medium text-ink-700 mb-1">Recurring Amount (IDR) *</label>
                <div class="flex items-center gap-2">
                  <input
                    v-model.number="sub.recurring_amount"
                    type="number"
                    step="any"
                    min="0"
                    class="w-full rounded border border-border bg-surface-0 py-1.5 px-2 text-sm text-ink-900 focus:outline-none font-mono"
                    required
                  />
                  <button
                    type="button"
                    @click="removeSubscription(idx)"
                    class="text-signal-danger hover:underline text-base font-bold px-1"
                    title="Remove subscription line"
                  >
                    &times;
                  </button>
                </div>
              </div>
            </div>

            <SecondaryButton type="button" @click="addSubscription">
              + Add Subscription Line
            </SecondaryButton>
          </div>
        </Panel>

        <div class="flex items-center justify-end gap-3">
          <SecondaryButton :href="route('sales.contracts.index')">Cancel</SecondaryButton>
          <PrimaryButton type="submit" :disabled="form.processing">Save Contract Draft</PrimaryButton>
        </div>
      </form>
    </div>
  </AppLayout>
</template>
