<!-- Create Price List Form (§3B) -->
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

interface TerritoryOption {
  id: number
  name: string
}

const props = defineProps<{
  territories: TerritoryOption[]
}>()

const form = useForm({
  name: '',
  currency: 'IDR',
  territory_id: null as number | null,
  customer_segment: '',
  effective_from: null as string | null,
  effective_to: null as string | null,
  is_tenant_default: false,
  is_active: true,
  lines: [
    {
      item_type: 'service',
      description: '',
      unit_price: 0,
    },
  ],
})

const addLine = () => {
  form.lines.push({
    item_type: 'service',
    description: '',
    unit_price: 0,
  })
}

const removeLine = (index: number) => {
  if (form.lines.length > 1) {
    form.lines.splice(index, 1)
  }
}

const submit = () => {
  form.post(route('sales.master.price-lists.store'))
}
</script>

<template>
  <AppLayout>
    <PageHeader
      title="Create Price List"
      description="Define custom pricing rules, customer segments, and default item pricing (§3B)."
    />

    <div class="mt-6">
      <form @submit.prevent="submit" class="space-y-6">
        <Panel title="Price List Header">
          <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div>
              <FormInput
                label="Price List Name"
                name="name"
                v-model="form.name"
                :error="form.errors.name"
                placeholder="e.g. Enterprise Client Pricing 2026"
                required
              />
            </div>

            <div>
              <FormInput
                label="Currency Code"
                name="currency"
                v-model="form.currency"
                :error="form.errors.currency"
                required
              />
            </div>

            <div>
              <FormSelect
                label="Territory (Optional)"
                name="territory_id"
                v-model="form.territory_id"
                :error="form.errors.territory_id"
                :options="props.territories.map(t => ({ value: t.id, label: t.name }))"
                placeholder="Apply to all territories"
              />
            </div>

            <div>
              <FormInput
                label="Customer Segment"
                name="customer_segment"
                v-model="form.customer_segment"
                :error="form.errors.customer_segment"
                placeholder="e.g. enterprise, retail, wholesale"
              />
            </div>

            <div>
              <FormInput
                label="Effective From"
                name="effective_from"
                type="date"
                v-model="form.effective_from"
                :error="form.errors.effective_from"
              />
            </div>

            <div>
              <FormInput
                label="Effective To"
                name="effective_to"
                type="date"
                v-model="form.effective_to"
                :error="form.errors.effective_to"
              />
            </div>

            <div class="sm:col-span-3 pt-2 grid grid-cols-1 sm:grid-cols-2 gap-4">
              <FormSwitch
                v-model="form.is_tenant_default"
                name="is_tenant_default"
                label="Set as Tenant Default"
                description="Use this price list by default for all unassigned clients."
              />

              <FormSwitch
                v-model="form.is_active"
                name="is_active"
                label="Active Status"
                description="Allow quotes and orders to use this price list."
              />
            </div>
          </div>
        </Panel>

        <!-- Pricing Lines -->
        <Panel title="Pricing Matrix Lines">
          <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
              <thead class="bg-surface-50 text-xs text-ink-500 uppercase border-b border-border">
                <tr>
                  <th class="py-2 px-3 w-32">Type</th>
                  <th class="py-2 px-3">Item / Service Description *</th>
                  <th class="py-2 px-3 w-48 text-right">Unit Price (IDR) *</th>
                  <th class="py-2 w-12 text-center"></th>
                </tr>
              </thead>
              <tbody class="divide-y divide-border">
                <tr v-for="(line, idx) in form.lines" :key="idx">
                  <td class="py-2 px-3">
                    <select
                      v-model="line.item_type"
                      class="w-full rounded border border-border bg-surface-0 py-1.5 px-2 text-xs text-ink-900 focus:outline-none"
                    >
                      <option value="service">Service</option>
                      <option value="product">Product</option>
                    </select>
                  </td>
                  <td class="py-2 px-3">
                    <input
                      v-model="line.description"
                      type="text"
                      placeholder="e.g. Legal Consulting per hour"
                      class="w-full rounded border border-border bg-surface-0 py-1.5 px-2 text-sm text-ink-900 focus:outline-none"
                      required
                    />
                  </td>
                  <td class="py-2 px-3">
                    <input
                      v-model.number="line.unit_price"
                      type="number"
                      step="any"
                      min="0"
                      class="w-full rounded border border-border bg-surface-0 py-1.5 px-2 text-sm font-mono text-right text-ink-900 focus:outline-none"
                      required
                    />
                  </td>
                  <td class="py-2 text-center">
                    <button
                      type="button"
                      @click="removeLine(idx)"
                      class="text-signal-danger hover:underline text-base font-bold"
                      title="Remove line"
                    >
                      &times;
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <div class="mt-4 border-t border-border pt-4">
            <SecondaryButton type="button" @click="addLine">
              + Add Item Price
            </SecondaryButton>
          </div>
        </Panel>

        <div class="flex items-center justify-end gap-3">
          <SecondaryButton :href="route('sales.master.price-lists.index')">Cancel</SecondaryButton>
          <PrimaryButton type="submit" :disabled="form.processing">Save Price List</PrimaryButton>
        </div>
      </form>
    </div>
  </AppLayout>
</template>
