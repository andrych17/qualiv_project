<!-- Edit Customer Sales & Credit Profile (§3B / §3K / §3D) -->
<script setup lang="ts">
import { useForm, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import FormSwitch from '@/Components/forms/FormSwitch.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import { formatCurrency } from '@/Utils/formatters'

interface PartnerItem {
  id: number
  name: string
}

interface SalesProfileItem {
  sales_team_id: number | null
  price_list_id: number | null
  assigned_rep_id: number | null
}

interface CreditProfileItem {
  credit_limit: number
  payment_terms_days: number
  on_hold: boolean
}

const props = defineProps<{
  customer: PartnerItem
  salesProfile: SalesProfileItem
  creditProfile: CreditProfileItem
  exposure: {
    credit_limit: number
    open_ar_balance: number
    available_credit: number
    on_hold: boolean
    payment_terms_days: number
  }
  teams: Array<{ id: number; name: string }>
  priceLists: Array<{ id: number; name: string }>
  users: Array<{ id: number; name: string }>
}>()

const form = useForm({
  sales_team_id: props.salesProfile.sales_team_id,
  price_list_id: props.salesProfile.price_list_id,
  assigned_rep_id: props.salesProfile.assigned_rep_id,
  credit_limit: Number(props.creditProfile.credit_limit || 0),
  payment_terms_days: props.creditProfile.payment_terms_days || 30,
  on_hold: props.creditProfile.on_hold,
})

const submit = () => {
  form.put(route('sales.master.customers.update', props.customer.id))
}

const generatePortalToken = () => {
  router.post(route('sales.master.customers.portal-token', props.customer.id))
}
</script>

<template>
  <AppLayout>
    <PageHeader
      :title="`Customer Sales Profile: ${props.customer.name}`"
      description="Configure custom pricing lists, representative assignments, and AR credit exposure."
    />

    <div class="mt-6 max-w-4xl">
      <form @submit.prevent="submit" class="space-y-6">
        <!-- Sales assignment panel -->
        <Panel title="Sales Assignment & Pricing">
          <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div>
              <FormSelect
                label="Assigned Sales Team"
                name="sales_team_id"
                v-model="form.sales_team_id"
                :options="props.teams.map(t => ({ value: t.id, label: t.name }))"
                placeholder="Unassigned"
              />
            </div>

            <div>
              <FormSelect
                label="Assigned Sales Representative"
                name="assigned_rep_id"
                v-model="form.assigned_rep_id"
                :options="props.users.map(u => ({ value: u.id, label: u.name }))"
                placeholder="Unassigned"
              />
            </div>

            <div>
              <FormSelect
                label="Custom Price List"
                name="price_list_id"
                v-model="form.price_list_id"
                :options="props.priceLists.map(p => ({ value: p.id, label: p.name }))"
                placeholder="Tenant default price list"
              />
            </div>
          </div>
        </Panel>

        <!-- Credit limits & Terms panel -->
        <Panel title="Credit Limit & Payment Terms (§3K)">
          <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div>
              <FormInput
                label="Credit Limit (IDR)"
                name="credit_limit"
                type="number"
                step="any"
                min="0"
                v-model="form.credit_limit"
                :error="form.errors.credit_limit"
                required
              />
            </div>

            <div>
              <FormInput
                label="Payment Terms (Days)"
                name="payment_terms_days"
                type="number"
                min="0"
                v-model="form.payment_terms_days"
                :error="form.errors.payment_terms_days"
                required
              />
            </div>

            <div class="pt-6">
              <FormSwitch
                v-model="form.on_hold"
                name="on_hold"
                label="Account On Hold"
                description="Blocks new sales order confirmation for this client."
              />
            </div>
          </div>

          <!-- Real-time Exposure Summary -->
          <div class="mt-6 border-t border-border pt-4 bg-surface-50 p-4 rounded-md">
            <h4 class="text-xs font-semibold uppercase text-ink-500 mb-2">Live Credit Exposure</h4>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 text-sm">
              <div>
                <p class="text-xs text-ink-500">Credit Limit</p>
                <p class="font-mono font-semibold">{{ formatCurrency(props.exposure.credit_limit) }}</p>
              </div>
              <div>
                <p class="text-xs text-ink-500">Open AR Balance</p>
                <p class="font-mono text-signal-danger font-semibold">{{ formatCurrency(props.exposure.open_ar_balance) }}</p>
              </div>
              <div>
                <p class="text-xs text-ink-500">Remaining Available Credit</p>
                <p class="font-mono text-signal-success font-bold">{{ formatCurrency(props.exposure.available_credit) }}</p>
              </div>
            </div>
          </div>
        </Panel>

        <!-- Customer Portal Section (§3D) -->
        <Panel title="Customer Portal Access (§3D)">
          <p class="text-xs text-ink-600 mb-4">
            Generate a secure signed URL link giving this customer read-only visibility into their quotations, sales orders, deliveries, and payment balances.
          </p>
          <SecondaryButton type="button" @click="generatePortalToken">
            Generate Portal Link
          </SecondaryButton>
        </Panel>

        <div class="flex items-center justify-end gap-3">
          <SecondaryButton :href="route('sales.master.customers.index')">Cancel</SecondaryButton>
          <PrimaryButton type="submit" :disabled="form.processing">Save Customer Profile</PrimaryButton>
        </div>
      </form>
    </div>
  </AppLayout>
</template>
