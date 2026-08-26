<!-- Edit Price List Form (§3B) -->
<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'

interface PriceListLine {
  id?: number
  item_type: 'product' | 'service'
  description: string
  unit_price: number
}

interface PriceListDetail {
  id: number
  name: string
  currency: string
  territory_id: number | null
  customer_segment: string | null
  effective_from: string | null
  effective_to: string | null
  is_tenant_default: boolean
  is_active: boolean
  lines: PriceListLine[]
}

const props = defineProps<{
  priceList: PriceListDetail
  territories: Array<{ id: number; name: string }>
}>()

const form = useForm({
  name: props.priceList.name,
  currency: props.priceList.currency,
  territory_id: props.priceList.territory_id,
  customer_segment: props.priceList.customer_segment ?? '',
  effective_from: props.priceList.effective_from,
  effective_to: props.priceList.effective_to,
  is_tenant_default: props.priceList.is_tenant_default,
  is_active: props.priceList.is_active,
  lines: props.priceList.lines.map(l => ({
    item_type: l.item_type,
    description: l.description,
    unit_price: Number(l.unit_price),
  })),
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
  form.put(route('sales.master.price-lists.update', props.priceList.id))
}
</script>

<template>
  <AppLayout>
    <PageHeader
      :title="`Edit Price List: ${props.priceList.name}`"
      description="Update pricing rules and item catalog."
    />

    <div class="mt-6">
      <form @submit.prevent="submit" class="space-y-6">
        <Panel title="Price List Header">
          <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div>
              <FormInput
                label="Price List Name *"
                v-model="form.name"
                :error="form.errors.name"
                required
              />
            </div>

            <div>
              <FormInput
                label="Currency Code *"
                v-model="form.currency"
                :error="form.errors.currency"
                required
              />
            </div>

            <div>
              <FormSelect
                label="Territory (Optional)"
                v-model="form.territory_id"
                :error="form.errors.territory_id"
                :options="props.territories.map(t => ({ value: t.id, label: t.name }))"
                placeholder="Apply to all territories"
              />
            </div>

            <div>
              <FormInput
                label="Customer Segment"
                v-model="form.customer_segment"
                :error="form.errors.customer_segment"
              />
            </div>

            <div>
              <FormInput
                label="Effective From"
                type="date"
                v-model="form.effective_from"
                :error="form.errors.effective_from"
              />
            </div>

            <div>
              <FormInput
                label="Effective To"
                type="date"
                v-model="form.effective_to"
                :error="form.errors.effective_to"
              />
            </div>

            <div class="flex items-center gap-4 sm:col-span-3 pt-2">
              <label class="flex items-center gap-2 text-sm text-ink-900 cursor-pointer">
                <input type="checkbox" v-model="form.is_tenant_default" class="rounded border-border text-accent focus:ring-accent" />
                <span>Set as Tenant Default Price List</span>
              </label>

              <label class="flex items-center gap-2 text-sm text-ink-900 cursor-pointer">
                <input type="checkbox" v-model="form.is_active" class="rounded border-border text-accent focus:ring-accent" />
                <span>Active</span>
              </label>
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
                      class="text-rose-500 hover:text-rose-700 text-lg font-bold"
                    >
                      &times;
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <div class="mt-4 border-t border-border pt-4">
            <button
              type="button"
              @click="addLine"
              class="rounded-md border border-border px-3 py-1.5 text-xs font-semibold text-ink-700 hover:bg-surface-100"
            >
              + Add Item Price
            </button>
          </div>
        </Panel>

        <div class="flex items-center justify-end gap-3">
          <SecondaryButton :href="route('sales.master.price-lists.index')">Cancel</SecondaryButton>
          <PrimaryButton type="submit" :disabled="form.processing">Save Changes</PrimaryButton>
        </div>
      </form>
    </div>
  </AppLayout>
</template>
