<!-- ponytail: Edit Goods Receipt (§3D) — draft is freely editable; posted is read-only and
     immutable (correct via a reversing Adjustment later, §3G — never an edit). -->
<script setup lang="ts">
import { useForm, Link, router } from '@inertiajs/vue3'
import { computed } from 'vue'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import GoodsReceiptLineListInput, { type ReceiptLineRow } from '@/Components/inventory/GoodsReceiptLineListInput.vue'
import InventorySubNav from '@/Components/inventory/InventorySubNav.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import { useConfirm } from '@/Composables/useConfirmDialog'

const props = defineProps<{
  receipt: {
    id: number
    warehouse_id: number
    receipt_date: string
    subject_type: string | null
    subject_id: string | null
    reference_number: string | null
    status: string
    lines: ReceiptLineRow[]
  }
  warehouses: Array<{ id: number; name: string }>
  uoms: Array<{ id: number; code: string; name: string }>
  locations: Array<{ id: number; warehouse_id: number; code: string }>
  productTracking: Record<number, string>
}>()

const isDraft = computed(() => props.receipt.status === 'draft')

const form = useForm({
  warehouse_id: props.receipt.warehouse_id,
  receipt_date: props.receipt.receipt_date,
  reference_number: props.receipt.reference_number ?? '',
  lines: props.receipt.lines.map((l) => ({ ...l })),
})

const submit = () => form.put(route('inventory.goodsReceipts.update', props.receipt.id))

const { confirm } = useConfirm()
const confirmPost = () => {
  confirm({
    title: 'Post this receipt?',
    description: 'This creates the stock ledger entries and cost layers — it can no longer be edited afterward.',
    confirmText: 'Post',
    onConfirm: () => router.patch(route('inventory.goodsReceipts.post', props.receipt.id)),
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader :title="isDraft ? 'Edit Goods Receipt' : 'Goods Receipt'" :description="receipt.reference_number ?? undefined">
      <template #actions>
        <StatusBadge :status="receipt.status" />
      </template>
    </PageHeader>

    <InventorySubNav active="goodsReceipts" class="mt-6" />

    <Panel class="mt-6 max-w-4xl">
      <form class="space-y-4" @submit.prevent="submit">
        <div class="grid grid-cols-2 gap-4">
          <FormSelect
            v-model="form.warehouse_id"
            name="warehouse_id"
            label="Warehouse"
            :options="warehouses.map((w) => ({ label: w.name, value: w.id }))"
            :error="form.errors.warehouse_id"
            :disabled="!isDraft"
            required
          />
          <FormInput v-model="form.receipt_date" name="receipt_date" type="date" label="Receipt date" :error="form.errors.receipt_date" :disabled="!isDraft" required />
        </div>
        <FormInput v-model="form.reference_number" name="reference_number" label="Reference number" :error="form.errors.reference_number" :disabled="!isDraft" />

        <GoodsReceiptLineListInput
          v-model="form.lines"
          :uoms="uoms"
          :locations="locations"
          :warehouse-id="form.warehouse_id"
          :product-tracking="productTracking"
          :disabled="!isDraft"
        />
        <p v-if="form.errors.lines" class="text-sm text-signal-danger">{{ form.errors.lines }}</p>

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <Link
            :href="route('inventory.goodsReceipts.index')"
            class="inline-flex items-center justify-center rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm font-semibold text-ink-900 shadow-sm transition hover:bg-surface-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            {{ isDraft ? 'Cancel' : 'Back' }}
          </Link>
          <template v-if="isDraft">
            <PrimaryButton type="submit" :disabled="form.processing">Save</PrimaryButton>
            <button
              type="button"
              class="inline-flex items-center justify-center rounded-sm bg-signal-success px-3 py-2 text-sm font-semibold text-white shadow-sm transition hover:opacity-90 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
              @click="confirmPost"
            >
              Post
            </button>
          </template>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
