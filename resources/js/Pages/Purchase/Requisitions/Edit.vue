<!-- Purchase Requisition Edit (§3B) -->
<script setup lang="ts">
import { computed } from 'vue'
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import FormTextarea from '@/Components/forms/FormTextarea.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import { formatCurrency } from '@/Utils/formatters'

interface CostCenter {
  id: number
  code: string
  name: string
}

interface Category {
  id: number
  name: string
  kind: string
  capex_opex: string
}

interface CatalogItem {
  id: number
  item_code: string
  description: string
  negotiated_price: number | null
  category_id: number | null
  unit: string
}

interface UserItem {
  id: number
  name: string
  email: string
}

interface LineItem {
  id?: number
  catalog_item_id: number | null
  description: string
  qty: number
  estimated_unit_price: number
  category_id: number | null
  local_content_pct: number | null
}

const props = defineProps<{
  requisition: {
    id: number
    pr_no: string
    requester_id: number | null
    cost_center_id: number | null
    needed_by: string | null
    subject_type: string | null
    subject_id: number | null
    status: string
    notes: string | null
    lines: LineItem[]
  }
  costCenters: CostCenter[]
  categories: Category[]
  catalogItems: CatalogItem[]
  users: UserItem[]
}>()

const form = useForm({
  requester_id: props.requisition.requester_id,
  cost_center_id: props.requisition.cost_center_id,
  needed_by: props.requisition.needed_by ?? '',
  subject_type: props.requisition.subject_type ?? '',
  subject_id: props.requisition.subject_id,
  notes: props.requisition.notes ?? '',
  lines: (props.requisition.lines.length > 0 ? props.requisition.lines : [
    {
      catalog_item_id: null,
      description: '',
      qty: 1,
      estimated_unit_price: 0,
      category_id: null,
      local_content_pct: null,
    },
  ]) as LineItem[],
})

const onCatalogItemChange = (index: number) => {
  const line = form.lines[index]
  if (line.catalog_item_id) {
    const item = props.catalogItems.find((c) => c.id === Number(line.catalog_item_id))
    if (item) {
      line.description = item.description
      if (item.negotiated_price) {
        line.estimated_unit_price = Number(item.negotiated_price)
      }
      if (item.category_id) {
        line.category_id = item.category_id
      }
    }
  }
}

const addLine = () => {
  form.lines.push({
    catalog_item_id: null,
    description: '',
    qty: 1,
    estimated_unit_price: 0,
    category_id: null,
    local_content_pct: null,
  })
}

const removeLine = (index: number) => {
  if (form.lines.length > 1) {
    form.lines.splice(index, 1)
  }
}

const estimatedTotal = computed(() => {
  return form.lines.reduce((sum, line) => {
    return sum + (Number(line.qty) || 0) * (Number(line.estimated_unit_price) || 0)
  }, 0)
})

const submit = () => form.put(route('purchase.requisitions.update', props.requisition.id))
</script>

<template>
  <AppLayout>
    <PageHeader :title="`Edit ${requisition.pr_no}`" description="Modify draft purchase requisition details (§3B).">
      <template #actions>
        <SecondaryButton :href="route('purchase.requisitions.show', requisition.id)">Back</SecondaryButton>
      </template>
    </PageHeader>

    <form class="mt-6 space-y-6" @submit.prevent="submit">
      <Panel title="Requisition Details">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
          <FormSelect
            v-model="form.requester_id"
            name="requester_id"
            label="Requester"
            placeholder="Select requester"
            :options="users.map((u) => ({ label: `${u.name} (${u.email})`, value: u.id }))"
            :error="form.errors.requester_id"
          />

          <FormSelect
            v-model="form.cost_center_id"
            name="cost_center_id"
            label="Cost Center"
            placeholder="Select cost center / department"
            :options="costCenters.map((c) => ({ label: `${c.code} - ${c.name}`, value: c.id }))"
            :error="form.errors.cost_center_id"
          />

          <FormInput
            v-model="form.needed_by"
            name="needed_by"
            type="date"
            label="Needed By Date"
            :error="form.errors.needed_by"
          />
        </div>

        <div class="mt-4">
          <FormTextarea
            v-model="form.notes"
            name="notes"
            label="Notes / Justification"
            placeholder="Describe the business purpose or project context…"
            :rows="2"
            :error="form.errors.notes"
          />
        </div>
      </Panel>

      <Panel title="Line Items">
        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-border">
            <thead>
              <tr class="text-left text-xs font-semibold text-ink-500 uppercase tracking-wider">
                <th class="py-2 px-3 w-48">Catalog Item</th>
                <th class="py-2 px-3">Description *</th>
                <th class="py-2 px-3 w-28 text-right">Qty *</th>
                <th class="py-2 px-3 w-36 text-right">Est. Unit Price</th>
                <th class="py-2 px-3 w-40">Category</th>
                <th class="py-2 px-3 w-24 text-right">TKDN %</th>
                <th class="py-2 px-3 w-36 text-right">Est. Total</th>
                <th class="py-2 px-3 w-12"></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-border/60">
              <tr v-for="(line, idx) in form.lines" :key="idx" class="align-top">
                <td class="py-2 px-2">
                  <select
                    v-model="line.catalog_item_id"
                    class="w-full rounded-md border border-border text-sm py-1.5 px-2 bg-surface text-ink-900 focus:border-accent focus:ring-1 focus:ring-accent"
                    @change="onCatalogItemChange(idx)"
                  >
                    <option :value="null">Custom item</option>
                    <option v-for="cat in catalogItems" :key="cat.id" :value="cat.id">
                      {{ cat.item_code }} - {{ cat.description }}
                    </option>
                  </select>
                </td>
                <td class="py-2 px-2">
                  <input
                    v-model="line.description"
                    type="text"
                    required
                    placeholder="Item description"
                    class="w-full rounded-md border border-border text-sm py-1.5 px-2 bg-surface text-ink-900 focus:border-accent focus:ring-1 focus:ring-accent"
                  />
                </td>
                <td class="py-2 px-2">
                  <input
                    v-model.number="line.qty"
                    type="number"
                    step="any"
                    min="0.0001"
                    required
                    class="w-full text-right rounded-md border border-border text-sm py-1.5 px-2 bg-surface text-ink-900 focus:border-accent focus:ring-1 focus:ring-accent"
                  />
                </td>
                <td class="py-2 px-2">
                  <input
                    v-model.number="line.estimated_unit_price"
                    type="number"
                    step="0.01"
                    min="0"
                    class="w-full text-right rounded-md border border-border text-sm py-1.5 px-2 bg-surface text-ink-900 focus:border-accent focus:ring-1 focus:ring-accent"
                  />
                </td>
                <td class="py-2 px-2">
                  <select
                    v-model="line.category_id"
                    class="w-full rounded-md border border-border text-sm py-1.5 px-2 bg-surface text-ink-900 focus:border-accent focus:ring-1 focus:ring-accent"
                  >
                    <option :value="null">Select category</option>
                    <option v-for="c in categories" :key="c.id" :value="c.id">
                      {{ c.name }} ({{ c.kind }})
                    </option>
                  </select>
                </td>
                <td class="py-2 px-2">
                  <input
                    v-model.number="line.local_content_pct"
                    type="number"
                    step="0.01"
                    min="0"
                    max="100"
                    placeholder="%"
                    class="w-full text-right rounded-md border border-border text-sm py-1.5 px-2 bg-surface text-ink-900 focus:border-accent focus:ring-1 focus:ring-accent"
                  />
                </td>
                <td class="py-2 px-2 text-right text-sm font-medium text-ink-900 pt-3">
                  {{ formatCurrency((Number(line.qty) || 0) * (Number(line.estimated_unit_price) || 0)) }}
                </td>
                <td class="py-2 px-2 text-center pt-2">
                  <button
                    type="button"
                    class="text-ink-400 hover:text-rose-600 transition"
                    title="Remove line"
                    @click="removeLine(idx)"
                    :disabled="form.lines.length <= 1"
                  >
                    ✕
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="mt-4 flex items-center justify-between border-t border-border pt-4">
          <SecondaryButton type="button" @click="addLine">+ Add Line</SecondaryButton>
          <div class="text-right">
            <span class="text-sm text-ink-600 mr-3">Estimated Total:</span>
            <span class="text-lg font-bold text-ink-900">{{ formatCurrency(estimatedTotal) }}</span>
          </div>
        </div>
      </Panel>

      <div class="flex justify-end gap-3">
        <SecondaryButton :href="route('purchase.requisitions.show', requisition.id)">Cancel</SecondaryButton>
        <PrimaryButton type="submit" :disabled="form.processing">Save Changes</PrimaryButton>
      </div>
    </form>
  </AppLayout>
</template>
