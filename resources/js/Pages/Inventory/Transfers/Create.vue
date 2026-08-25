<!-- ponytail: New Transfer (§3F) — always starts as a draft; posting is a separate step. -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import { computed } from 'vue'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import TransferLineListInput, { type TransferLineRow } from '@/Components/inventory/TransferLineListInput.vue'
import InventorySubNav from '@/Components/inventory/InventorySubNav.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'

const props = defineProps<{
  warehouses: Array<{ id: number; name: string }>
  uoms: Array<{ id: number; code: string; name: string }>
  locations: Array<{ id: number; warehouse_id: number; code: string }>
  productTracking: Record<number, string>
}>()

const form = useForm({
  source_warehouse_id: null as number | null,
  source_location_id: null as number | null,
  destination_warehouse_id: null as number | null,
  destination_location_id: null as number | null,
  transfer_date: new Date().toISOString().slice(0, 10),
  lines: [] as TransferLineRow[],
})

const sourceLocationOptions = computed(() =>
  props.locations.filter((l) => Number(l.warehouse_id) === Number(form.source_warehouse_id)).map((l) => ({ label: l.code, value: l.id })),
)
const destinationLocationOptions = computed(() =>
  props.locations.filter((l) => Number(l.warehouse_id) === Number(form.destination_warehouse_id)).map((l) => ({ label: l.code, value: l.id })),
)

const submit = () => form.post(route('inventory.transfers.store'))
</script>

<template>
  <AppLayout>
    <PageHeader title="New Transfer" description="Saved as a draft — post it once the lines are correct." />

    <InventorySubNav active="transfers" class="mt-6" />

    <Panel class="mt-6 max-w-4xl">
      <form class="space-y-4" @submit.prevent="submit">
        <FormInput v-model="form.transfer_date" name="transfer_date" type="date" label="Transfer date" :error="form.errors.transfer_date" required />

        <div class="grid grid-cols-2 gap-4 rounded-md border border-border p-3">
          <div class="space-y-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-ink-600">Source</p>
            <FormSelect
              v-model="form.source_warehouse_id"
              name="source_warehouse_id"
              label="Warehouse"
              placeholder="Select a warehouse…"
              :options="warehouses.map((w) => ({ label: w.name, value: w.id }))"
              :error="form.errors.source_warehouse_id"
              required
            />
            <FormSelect
              v-model="form.source_location_id"
              name="source_location_id"
              label="Location"
              placeholder="Choose a bin…"
              :options="sourceLocationOptions"
              :error="form.errors.source_location_id"
              :disabled="!form.source_warehouse_id"
              required
            />
          </div>
          <div class="space-y-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-ink-600">Destination</p>
            <FormSelect
              v-model="form.destination_warehouse_id"
              name="destination_warehouse_id"
              label="Warehouse"
              placeholder="Select a warehouse…"
              :options="warehouses.map((w) => ({ label: w.name, value: w.id }))"
              :error="form.errors.destination_warehouse_id"
              required
            />
            <FormSelect
              v-model="form.destination_location_id"
              name="destination_location_id"
              label="Location"
              placeholder="Choose a bin…"
              :options="destinationLocationOptions"
              :error="form.errors.destination_location_id"
              :disabled="!form.destination_warehouse_id"
              required
            />
          </div>
        </div>

        <TransferLineListInput v-model="form.lines" :uoms="uoms" :product-tracking="productTracking" />
        <p v-if="form.errors.lines" class="text-sm text-signal-danger">{{ form.errors.lines }}</p>

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <Link
            :href="route('inventory.transfers.index')"
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
