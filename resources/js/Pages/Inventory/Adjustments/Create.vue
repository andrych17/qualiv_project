<!-- ponytail: New Adjustment (§3G) — always starts as a draft; posting is a separate step. -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import { computed } from 'vue'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import AdjustmentLineListInput, { type AdjustmentLineRow } from '@/Components/inventory/AdjustmentLineListInput.vue'
import InventorySubNav from '@/Components/inventory/InventorySubNav.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'

const props = defineProps<{
  warehouses: Array<{ id: number; name: string }>
  locations: Array<{ id: number; warehouse_id: number; code: string }>
  reasons: Array<{ id: number; name: string }>
  productTracking: Record<number, string>
}>()

const form = useForm({
  warehouse_id: null as number | null,
  location_id: null as number | null,
  adjustment_date: new Date().toISOString().slice(0, 10),
  reason_id: null as number | null,
  reference: '',
  lines: [] as AdjustmentLineRow[],
})

const locationOptions = computed(() =>
  props.locations.filter((l) => Number(l.warehouse_id) === Number(form.warehouse_id)).map((l) => ({ label: l.code, value: l.id })),
)

const submit = () => form.post(route('inventory.adjustments.store'))
</script>

<template>
  <AppLayout>
    <PageHeader title="New Adjustment" description="Saved as a draft — post it once the counted quantities are correct." />

    <InventorySubNav active="adjustments" class="mt-6" />

    <Panel class="mt-6 max-w-4xl">
      <form class="space-y-4" @submit.prevent="submit">
        <div class="grid grid-cols-2 gap-4">
          <FormSelect
            v-model="form.warehouse_id"
            name="warehouse_id"
            label="Warehouse"
            placeholder="Select a warehouse…"
            :options="warehouses.map((w) => ({ label: w.name, value: w.id }))"
            :error="form.errors.warehouse_id"
            required
          />
          <FormSelect
            v-model="form.location_id"
            name="location_id"
            label="Location"
            placeholder="Choose a bin…"
            :options="locationOptions"
            :error="form.errors.location_id"
            :disabled="!form.warehouse_id"
            required
          />
        </div>
        <div class="grid grid-cols-2 gap-4">
          <FormInput v-model="form.adjustment_date" name="adjustment_date" type="date" label="Adjustment date" :error="form.errors.adjustment_date" required />
          <FormSelect
            v-model="form.reason_id"
            name="reason_id"
            label="Reason"
            placeholder="Select a reason…"
            :options="reasons.map((r) => ({ label: r.name, value: r.id }))"
            :error="form.errors.reason_id"
            required
          />
        </div>
        <FormInput v-model="form.reference" name="reference" label="Reference" placeholder="e.g. linked cycle count" :error="form.errors.reference" />

        <AdjustmentLineListInput v-model="form.lines" :warehouse-id="form.warehouse_id" :location-id="form.location_id" :product-tracking="productTracking" />
        <p v-if="form.errors.lines" class="text-sm text-signal-danger">{{ form.errors.lines }}</p>

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <Link
            :href="route('inventory.adjustments.index')"
            class="inline-flex items-center justify-center rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm font-semibold text-ink-900 shadow-sm transition hover:bg-surface-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            Cancel
          </Link>
          <PrimaryButton type="submit" :disabled="form.processing">Save draft</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
