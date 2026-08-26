<!-- Purchase RFQ Show & Side-by-Side Comparison Matrix (§3C) -->
<script setup lang="ts">
import { ref } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import Modal from '@/Components/Modal.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import PurchaseSubNav from '@/Components/purchase/PurchaseSubNav.vue'
import { useConfirm } from '@/Composables/useConfirmDialog'
import { formatCurrency, formatDate } from '@/Utils/formatters'

interface SupplierItem {
  invitation_id: number
  supplier_id: number
  supplier_name: string
  responded: boolean
  responded_at: string | null
  notes: string | null
}

interface QuoteInfo {
  price: number | null
  lead_time_days: number | null
  notes: string | null
}

interface ComparisonLine {
  id: number
  line_no: number
  description: string
  qty: number
  awarded_supplier_id: number | null
  awarded_supplier_name: string | null
  quotes: Record<number, QuoteInfo>
  min_price: number | null
}

interface RfxData {
  id: number
  uuid: string
  rfx_no: string
  type: string
  pr_id: number | null
  pr_no: string | null
  due_date: string
  status: string
  creator_name: string | null
  created_at: string
}

const props = defineProps<{
  rfx: RfxData
  suppliers: SupplierItem[]
  comparisonLines: ComparisonLine[]
}>()

// Award Form
const awardForm = useForm({
  awards: props.comparisonLines.reduce((acc, line) => {
    acc[line.id] = line.awarded_supplier_id ?? null
    return acc
  }, {} as Record<number, number | null>),
})

// Record Quote Modal
const showQuoteModal = ref(false)
const selectedInvitationId = ref<number | null>(null)

const quoteForm = useForm({
  invitation_id: null as number | null,
  notes: '',
  quotes: props.comparisonLines.map((line) => ({
    rfx_line_id: line.id,
    description: line.description,
    qty: line.qty,
    price: null as number | null,
    lead_time_days: null as number | null,
    notes: '',
  })),
})

const openQuoteModal = (supplier: SupplierItem) => {
  selectedInvitationId.value = supplier.invitation_id
  quoteForm.invitation_id = supplier.invitation_id
  quoteForm.notes = supplier.notes ?? ''

  // Pre-fill existing quotes if available
  quoteForm.quotes = props.comparisonLines.map((line) => {
    const q = line.quotes[supplier.supplier_id]
    return {
      rfx_line_id: line.id,
      description: line.description,
      qty: line.qty,
      price: q?.price ?? null,
      lead_time_days: q?.lead_time_days ?? null,
      notes: q?.notes ?? '',
    }
  })

  showQuoteModal.value = true
}

const submitQuote = () => {
  quoteForm.post(route('purchase.sourcing.response', props.rfx.id), {
    onSuccess: () => {
      showQuoteModal.value = false
    },
  })
}

const { confirm } = useConfirm()

const sendToSuppliers = () => {
  router.post(route('purchase.sourcing.send', props.rfx.id))
}

const submitAward = () => {
  confirm({
    title: 'Award Selected Suppliers?',
    description: 'Award selected suppliers and generate Purchase Orders?',
    confirmText: 'Award & Generate POs',
    onConfirm: () => awardForm.post(route('purchase.sourcing.award', props.rfx.id)),
  })
}

const cancelRfx = () => {
  confirm({
    title: 'Cancel RFQ?',
    description: 'Are you sure you want to cancel this RFQ?',
    variant: 'destructive',
    confirmText: 'Cancel RFQ',
    onConfirm: () => router.post(route('purchase.sourcing.cancel', props.rfx.id)),
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader :title="`RFQ: ${rfx.rfx_no}`" :description="`Competitive Bidding & Side-by-Side Quote Comparison (§3C)`">
      <template #actions>
        <div class="flex items-center gap-2">
          <SecondaryButton :href="route('purchase.sourcing.index')">Back</SecondaryButton>

          <PrimaryButton v-if="rfx.status === 'draft'" @click="sendToSuppliers">
            Send to Suppliers
          </PrimaryButton>

          <PrimaryButton
            v-if="['sent', 'responses_open'].includes(rfx.status)"
            @click="submitAward"
          >
            🏆 Award & Generate POs
          </PrimaryButton>

          <button
            v-if="!['awarded', 'cancelled'].includes(rfx.status)"
            type="button"
            class="px-3 py-2 text-xs font-semibold text-rose-700 bg-rose-50 border border-rose-200 rounded-md hover:bg-rose-100"
            @click="cancelRfx"
          >
            Cancel RFQ
          </button>
        </div>
      </template>
    </PageHeader>

    <div class="mt-4">
      <PurchaseSubNav active="sourcing" />
    </div>

    <!-- Header Details Banner -->
    <div class="mt-6 p-4 bg-surface rounded-lg border border-border flex flex-wrap items-center justify-between gap-4">
      <div class="flex items-center gap-4">
        <span class="text-xs font-semibold uppercase tracking-wider text-ink-500">RFQ Status:</span>
        <StatusBadge :status="rfx.status" />
      </div>

      <div class="flex items-center gap-6 text-xs text-ink-600">
        <div>Due Date: <strong class="text-ink-900">{{ rfx.due_date }}</strong></div>
        <div v-if="rfx.pr_no">Linked PR: <strong class="text-ink-900">{{ rfx.pr_no }}</strong></div>
        <div>Created by: <strong class="text-ink-900">{{ rfx.creator_name ?? '—' }}</strong></div>
      </div>
    </div>

    <!-- Invited Suppliers Strip & Quick Response Action -->
    <div class="mt-6">
      <Panel title="Invited Vendors & Quotation Status">
        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-3">
          <div
            v-for="s in suppliers"
            :key="s.supplier_id"
            class="p-3 rounded-lg border flex items-center justify-between"
            :class="s.responded ? 'bg-emerald-50/40 border-emerald-200' : 'bg-surface-elevated border-border'"
          >
            <div>
              <div class="text-xs font-bold text-ink-900">{{ s.supplier_name }}</div>
              <div class="text-[11px] mt-0.5" :class="s.responded ? 'text-emerald-700 font-medium' : 'text-ink-500'">
                {{ s.responded ? `✓ Quoted on ${s.responded_at?.slice(0, 10)}` : '⏳ Awaiting Quote' }}
              </div>
            </div>

            <button
              v-if="!['awarded', 'cancelled'].includes(rfx.status)"
              type="button"
              class="px-2.5 py-1 text-xs font-semibold rounded transition"
              :class="s.responded ? 'bg-surface border border-border text-ink-700 hover:bg-surface-elevated' : 'bg-accent text-white hover:bg-accent/90'"
              @click="openQuoteModal(s)"
            >
              {{ s.responded ? 'Edit Quote' : '+ Enter Quote' }}
            </button>
          </div>
        </div>
      </Panel>
    </div>

    <!-- Side-by-Side Comparison Matrix (§3C) -->
    <div class="mt-6">
      <Panel title="Side-by-Side Comparison Matrix (§3C)">
        <div class="overflow-x-auto">
          <table class="w-full text-xs text-left border-collapse">
            <thead>
              <tr class="border-b border-border bg-surface-elevated">
                <th class="py-2.5 px-3 font-semibold text-ink-700 w-10">#</th>
                <th class="py-2.5 px-3 font-semibold text-ink-700 min-w-[200px]">Item Description</th>
                <th class="py-2.5 px-3 font-semibold text-ink-700 text-right w-20">Qty</th>

                <!-- Supplier Columns -->
                <th
                  v-for="s in suppliers"
                  :key="s.supplier_id"
                  class="py-2.5 px-3 font-semibold text-ink-900 border-l border-border min-w-[160px]"
                >
                  <div>{{ s.supplier_name }}</div>
                  <div class="text-[10px] font-normal" :class="s.responded ? 'text-emerald-600 font-medium' : 'text-ink-400'">
                    {{ s.responded ? '✓ Response on file' : 'No quote yet' }}
                  </div>
                </th>

                <!-- Award Decision Column -->
                <th class="py-2.5 px-3 font-semibold text-ink-900 border-l border-border min-w-[180px]">
                  🏆 Award Winner
                </th>
              </tr>
            </thead>
            <tbody class="divide-y divide-border">
              <tr
                v-for="line in comparisonLines"
                :key="line.id"
                class="hover:bg-surface-elevated/40"
              >
                <td class="py-3 px-3 font-medium text-ink-500">{{ line.line_no }}</td>
                <td class="py-3 px-3 font-medium text-ink-900">{{ line.description }}</td>
                <td class="py-3 px-3 font-semibold text-ink-900 text-right">{{ line.qty }}</td>

                <!-- Quote per Supplier -->
                <td
                  v-for="s in suppliers"
                  :key="s.supplier_id"
                  class="py-3 px-3 border-l border-border"
                  :class="{
                    'bg-emerald-100/40 text-emerald-950 font-semibold': line.quotes[s.supplier_id]?.price !== null && line.quotes[s.supplier_id]?.price === line.min_price,
                  }"
                >
                  <div v-if="line.quotes[s.supplier_id]?.price !== null" class="space-y-0.5">
                    <div class="flex items-center gap-1">
                      <span class="font-bold">
                        {{ formatCurrency(line.quotes[s.supplier_id]?.price) }}
                      </span>
                      <span
                        v-if="line.quotes[s.supplier_id]?.price === line.min_price && suppliers.filter(x => x.responded).length > 1"
                        class="px-1 py-0.2 rounded text-[10px] bg-emerald-600 text-white font-bold"
                      >
                        Lowest
                      </span>
                    </div>
                    <div v-if="line.quotes[s.supplier_id]?.lead_time_days" class="text-[11px] text-ink-500">
                      Lead: {{ line.quotes[s.supplier_id]?.lead_time_days }} day(s)
                    </div>
                    <div v-if="line.quotes[s.supplier_id]?.notes" class="text-[10px] text-ink-400 italic">
                      "{{ line.quotes[s.supplier_id]?.notes }}"
                    </div>
                  </div>
                  <div v-else class="text-ink-400 italic">
                    —
                  </div>
                </td>

                <!-- Award Picker -->
                <td class="py-3 px-3 border-l border-border">
                  <div v-if="rfx.status === 'awarded'" class="font-semibold text-emerald-800">
                    {{ line.awarded_supplier_name ?? '—' }}
                  </div>
                  <div v-else>
                    <select
                      v-model="awardForm.awards[line.id]"
                      class="w-full text-xs rounded border-border bg-surface text-ink-900 focus:ring-accent focus:border-accent"
                    >
                      <option :value="null">-- Select winner --</option>
                      <option
                        v-for="s in suppliers"
                        :key="s.supplier_id"
                        :value="s.supplier_id"
                      >
                        {{ s.supplier_name }} ({{ formatCurrency(line.quotes[s.supplier_id]?.price) }})
                      </option>
                    </select>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div v-if="!['awarded', 'cancelled'].includes(rfx.status)" class="mt-4 pt-3 border-t border-border flex justify-end">
          <PrimaryButton :disabled="awardForm.processing" @click="submitAward">
            Save Award & Generate Purchase Orders →
          </PrimaryButton>
        </div>
      </Panel>
    </div>

    <!-- Record Quote Modal -->
    <Modal :show="showQuoteModal" @close="showQuoteModal = false">
      <form class="p-6 space-y-4 max-w-2xl" @submit.prevent="submitQuote">
        <h3 class="text-lg font-semibold text-ink-900">Record Vendor Quotation</h3>
        <p class="text-xs text-ink-600">
          Enter quoted pricing and lead times provided by the vendor.
        </p>

        <div class="space-y-3 max-h-80 overflow-y-auto p-1">
          <div
            v-for="(q, idx) in quoteForm.quotes"
            :key="q.rfx_line_id"
            class="p-3 bg-surface-elevated rounded border border-border space-y-2"
          >
            <div class="text-xs font-bold text-ink-900">
              #{{ idx + 1 }}. {{ q.description }} (Qty: {{ q.qty }})
            </div>

            <div class="grid grid-cols-2 gap-3">
              <FormInput
                v-model.number="q.price"
                :name="`price_${idx}`"
                type="number"
                step="0.01"
                min="0"
                label="Unit Price (IDR) *"
                placeholder="0.00"
                required
              />

              <FormInput
                v-model.number="q.lead_time_days"
                :name="`lead_${idx}`"
                type="number"
                min="0"
                label="Lead Time (Days)"
                placeholder="e.g. 7"
              />
            </div>

            <FormInput
              v-model="q.notes"
              :name="`notes_${idx}`"
              label="Line Note / Brand Specified"
              placeholder="e.g. OEM genuine parts"
            />
          </div>
        </div>

        <div class="pt-2">
          <FormInput
            v-model="quoteForm.notes"
            name="notes"
            label="General Quotation Notes / Terms"
            placeholder="e.g. Price includes shipping and 1-year warranty."
          />
        </div>

        <div class="flex justify-end gap-2 pt-4 border-t border-border">
          <SecondaryButton @click="showQuoteModal = false">Cancel</SecondaryButton>
          <PrimaryButton type="submit" :disabled="quoteForm.processing">
            Save Quotation
          </PrimaryButton>
        </div>
      </form>
    </Modal>
  </AppLayout>
</template>
