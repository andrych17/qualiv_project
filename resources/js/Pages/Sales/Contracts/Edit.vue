<!-- Edit Contract Form (§3L) -->
<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'

interface SubscriptionItem {
  id?: number
  description: string
  billing_interval: string
  recurring_amount: number
  next_billing_date: string
}

interface ContractDetail {
  id: number
  name: string
  status: string
  customer_id: number
  price_list_id: number | null
  term_start: string
  term_end: string
  auto_renew: boolean
  subscriptions: SubscriptionItem[]
}

const props = defineProps<{
  contract: ContractDetail
  customers: Array<{ id: number; name: string }>
  priceLists: Array<{ id: number; name: string }>
  intervals: string[]
}>()

const form = useForm({
  customer_id: props.contract.customer_id,
  price_list_id: props.contract.price_list_id,
  name: props.contract.name,
  term_start: props.contract.term_start,
  term_end: props.contract.term_end,
  auto_renew: props.contract.auto_renew,
  subscriptions: props.contract.subscriptions.map(s => ({
    description: s.description,
    billing_interval: s.billing_interval,
    recurring_amount: Number(s.recurring_amount),
    next_billing_date: s.next_billing_date,
  })),
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
  form.put(route('sales.contracts.update', props.contract.id))
}
</script>

<template>
  <AppLayout>
    <PageHeader
      :title="`Edit Contract Agreement`"
      :description="props.contract.name"
    />

    <div class="mt-6">
      <form @submit.prevent="submit" class="space-y-6">
        <Panel title="Contract Terms">
          <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div class="sm:col-span-3">
              <FormInput
                label="Contract Agreement Title *"
                v-model="form.name"
                :error="form.errors.name"
                required
              />
            </div>

            <div>
              <FormSelect
                label="Customer / Client *"
                v-model="form.customer_id"
                :error="form.errors.customer_id"
                :options="props.customers.map(c => ({ value: c.id, label: c.name }))"
                required
              />
            </div>

            <div>
              <FormInput
                label="Term Start Date *"
                type="date"
                v-model="form.term_start"
                :error="form.errors.term_start"
                required
              />
            </div>

            <div>
              <FormInput
                label="Term End Date *"
                type="date"
                v-model="form.term_end"
                :error="form.errors.term_end"
                required
              />
            </div>

            <div>
              <FormSelect
                label="Price List"
                v-model="form.price_list_id"
                :error="form.errors.price_list_id"
                :options="props.priceLists.map(p => ({ value: p.id, label: p.name }))"
              />
            </div>

            <div class="flex items-center pt-6">
              <label class="flex items-center gap-2 text-sm text-ink-900 cursor-pointer">
                <input
                  type="checkbox"
                  v-model="form.auto_renew"
                  class="rounded border-border text-accent focus:ring-accent"
                />
                <span>Auto-renew upon term completion</span>
              </label>
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
                  class="w-full rounded border border-border bg-surface-0 py-1.5 px-2 text-sm text-ink-900 focus:outline-none"
                  required
                />
              </div>

              <div>
                <label class="block text-xs font-medium text-ink-700 mb-1">Billing Interval *</label>
                <select
                  v-model="sub.billing_interval"
                  class="w-full rounded border border-border bg-surface-0 py-1.5 px-2 text-sm text-ink-900 focus:outline-none"
                >
                  <option v-for="interval in props.intervals" :key="interval" :value="interval">
                    {{ interval.toUpperCase() }}
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
                    class="text-rose-500 hover:text-rose-700 text-lg font-bold px-1"
                    title="Remove subscription line"
                  >
                    &times;
                  </button>
                </div>
              </div>
            </div>

            <button
              type="button"
              @click="addSubscription"
              class="rounded-md border border-border px-3 py-1.5 text-xs font-semibold text-ink-700 hover:bg-surface-100"
            >
              + Add Subscription Line
            </button>
          </div>
        </Panel>

        <div class="flex items-center justify-end gap-3">
          <SecondaryButton :href="route('sales.contracts.show', props.contract.id)">Cancel</SecondaryButton>
          <PrimaryButton type="submit" :disabled="form.processing">Save Changes</PrimaryButton>
        </div>
      </form>
    </div>
  </AppLayout>
</template>
