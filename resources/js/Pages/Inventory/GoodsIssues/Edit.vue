<!-- ponytail: Edit Goods Issue (§3E) — draft is freely editable; posted is read-only and
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
import GoodsIssueLineListInput, { type IssueLineRow } from '@/Components/inventory/GoodsIssueLineListInput.vue'
import InventorySubNav from '@/Components/inventory/InventorySubNav.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import { useConfirm } from '@/Composables/useConfirmDialog'

const props = defineProps<{
  issue: {
    id: number
    warehouse_id: number
    issue_date: string
    subject_type: string | null
    subject_id: string | null
    reason: string | null
    status: string
    lines: IssueLineRow[]
  }
  warehouses: Array<{ id: number; name: string }>
  uoms: Array<{ id: number; code: string; name: string }>
  locations: Array<{ id: number; warehouse_id: number; code: string }>
  productTracking: Record<number, string>
}>()

const isDraft = computed(() => props.issue.status === 'draft')

const form = useForm({
  warehouse_id: props.issue.warehouse_id,
  issue_date: props.issue.issue_date,
  reason: props.issue.reason ?? '',
  lines: props.issue.lines.map((l) => ({ ...l })),
})

const submit = () => form.put(route('inventory.goodsIssues.update', props.issue.id))

const { confirm } = useConfirm()
const confirmPost = () => {
  confirm({
    title: 'Post this issue?',
    description: 'This deducts stock and consumes cost layers — it can no longer be edited afterward.',
    confirmText: 'Post',
    onConfirm: () => router.patch(route('inventory.goodsIssues.post', props.issue.id)),
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader :title="isDraft ? 'Edit Goods Issue' : 'Goods Issue'">
      <template #actions>
        <StatusBadge :status="issue.status" />
      </template>
    </PageHeader>

    <InventorySubNav active="goodsIssues" class="mt-6" />

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
          <FormInput v-model="form.issue_date" name="issue_date" type="date" label="Issue date" :error="form.errors.issue_date" :disabled="!isDraft" required />
        </div>
        <FormSelect
          v-model="form.reason"
          name="reason"
          label="Reason (unlinked issues)"
          placeholder="No reason set"
          :options="[
            { label: 'Consumption', value: 'consumption' },
            { label: 'Sample', value: 'sample' },
            { label: 'Write-off (pending adjustment review)', value: 'write_off_pending_adjustment_review' },
          ]"
          :error="form.errors.reason"
          :disabled="!isDraft"
        />

        <GoodsIssueLineListInput
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
            :href="route('inventory.goodsIssues.index')"
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
