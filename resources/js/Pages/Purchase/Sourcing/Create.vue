<!-- Purchase RFQ Create (§3C) -->
<script setup lang="ts">
import { ref, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import FormMultiSelect from '@/Components/forms/FormMultiSelect.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'

interface PrItem {
  id: number
  pr_no: string
  lines?: Array<{ description: string; qty: number }>
}

interface VendorItem {
  id: number
  name: string
}

const props = defineProps<{
  approvedPrs: Array<{ id: number; pr_no: string; estimated_total: number }>
  vendors: VendorItem[]
  selectedPr?: PrItem | null
}>()

const form = useForm({
  type: 'rfq',
  pr_id: props.selectedPr?.id ?? null as number | null,
  due_date: new Date(Date.now() + 14 * 24 * 60 * 60 * 1000).toISOString().slice(0, 10),
  suppliers: [] as number[],
  lines: props.selectedPr?.lines?.length
    ? props.selectedPr.lines.map((l) => ({ description: l.description, qty: l.qty }))
    : [{ description: '', qty: 1 }],
})

const addLine = () => {
  form.lines.push({ description: '', qty: 1 })
}

const removeLine = (idx: number) => {
  if (form.lines.length > 1) {
    form.lines.splice(idx, 1)
  }
}

const submit = () => form.post(route('purchase.sourcing.store'))
</script>

<template>
  <AppLayout>
    <PageHeader title="New Request for Quotation (RFQ)" description="Invite multiple vendors to submit competitive price quotes for goods or services (§3C).">
      <template #actions>
        <SecondaryButton :href="route('purchase.sourcing.index')">Cancel</SecondaryButton>
      </template>
    </PageHeader>

    <form class="mt-6 space-y-6 max-w-4xl" @submit.prevent="submit">
      <Panel title="RFQ Header">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <FormSelect
            v-model="form.pr_id"
            name="pr_id"
            label="Linked Purchase Requisition (Optional)"
            placeholder="Standalone RFQ (no linked PR)"
            :options="approvedPrs.map((p) => ({ label: `${p.pr_no}`, value: p.id }))"
            :error="form.errors.pr_id"
          />

          <FormInput
            v-model="form.due_date"
            name="due_date"
            type="date"
            label="Quotation Due Date *"
            :error="form.errors.due_date"
            required
          />
        </div>
      </Panel>

      <!-- Invited Vendors -->
      <Panel title="Invited Vendors / Suppliers *">
        <div class="space-y-2">
          <FormMultiSelect
            v-model="form.suppliers"
            name="suppliers"
            label="Select vendors to receive quotation requests"
            placeholder="Pilih vendor yang diundang..."
            search-placeholder="Cari nama vendor..."
            :options="vendors.map((v) => ({ label: v.name, value: v.id }))"
            :error="form.errors.suppliers"
            required
          />
          <div v-if="vendors.length === 0" class="text-xs text-ink-600">
            No active vendors found. Please register vendors first.
          </div>
        </div>
      </Panel>

      <!-- Requested Line Items -->
      <Panel title="Quotation Line Items *">
        <div class="space-y-3">
          <div
            v-for="(line, idx) in form.lines"
            :key="idx"
            class="flex items-center gap-3 p-3 bg-surface-elevated rounded-lg border border-border"
          >
            <span class="text-xs font-bold text-ink-400 w-6 text-center">#{{ idx + 1 }}</span>

            <div class="flex-1">
              <input
                v-model="line.description"
                type="text"
                placeholder="Item specification / description *"
                class="w-full text-xs rounded border-border bg-surface text-ink-900 focus:ring-accent focus:border-accent"
                required
              />
            </div>

            <div class="w-28">
              <input
                v-model.number="line.qty"
                type="number"
                step="0.01"
                min="0.01"
                placeholder="Qty"
                class="w-full text-xs text-right rounded border-border bg-surface text-ink-900 focus:ring-accent focus:border-accent"
                required
              />
            </div>

            <button
              v-if="form.lines.length > 1"
              type="button"
              class="text-rose-600 hover:text-rose-800 text-sm p-1"
              @click="removeLine(idx)"
            >
              ✕
            </button>
          </div>

          <button
            type="button"
            class="w-full py-2 text-xs font-semibold text-accent border border-dashed border-accent/40 rounded-lg hover:bg-accent/5 transition"
            @click="addLine"
          >
            + Add Another Line Item
          </button>
        </div>
      </Panel>

      <div class="flex justify-end gap-3">
        <SecondaryButton :href="route('purchase.sourcing.index')">Cancel</SecondaryButton>
        <PrimaryButton type="submit" :disabled="form.processing || form.suppliers.length === 0">
          Create RFQ
        </PrimaryButton>
      </div>
    </form>
  </AppLayout>
</template>
