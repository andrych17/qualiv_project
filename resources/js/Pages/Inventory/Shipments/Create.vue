<!-- ponytail: New Shipment (§3P) — header + pick which packed packages travel on it. -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import InventorySubNav from '@/Components/inventory/InventorySubNav.vue'
import ShipmentPackageSelector, { type EligiblePackList } from '@/Components/inventory/ShipmentPackageSelector.vue'
import { computed } from 'vue'

const props = defineProps<{
  warehouses: Array<{ id: number; name: string }>
  eligiblePackLists: EligiblePackList[]
}>()

const form = useForm({
  warehouse_id: null as number | null,
  carrier: '',
  tracking_number: '',
  ship_date: '',
  pack_list_ids: [] as number[],
})

const availablePackLists = computed(() =>
  form.warehouse_id ? props.eligiblePackLists.filter((p) => p.warehouse_id === form.warehouse_id) : [],
)

const submit = () => form.post(route('inventory.shipments.store'))
</script>

<template>
  <AppLayout>
    <PageHeader title="New Shipment" description="Attach one or more packed packages, then ship-confirm when ready to go." />

    <InventorySubNav active="shipments" class="mt-6" />

    <Panel class="mt-6 max-w-3xl">
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
          <FormInput v-model="form.ship_date" name="ship_date" type="date" label="Ship date" :error="form.errors.ship_date" />
        </div>
        <div class="grid grid-cols-2 gap-4">
          <FormInput v-model="form.carrier" name="carrier" label="Carrier" :error="form.errors.carrier" />
          <FormInput v-model="form.tracking_number" name="tracking_number" label="Tracking number" :error="form.errors.tracking_number" />
        </div>

        <div>
          <p class="mb-2 text-sm font-medium text-ink-900">Packages</p>
          <ShipmentPackageSelector v-model="form.pack_list_ids" :eligible-pack-lists="availablePackLists" />
          <p v-if="form.errors.pack_list_ids" class="mt-2 text-sm text-signal-danger">{{ form.errors.pack_list_ids }}</p>
        </div>

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <Link
            :href="route('inventory.shipments.index')"
            class="inline-flex items-center justify-center rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm font-semibold text-ink-900 shadow-sm transition hover:bg-surface-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            Cancel
          </Link>
          <PrimaryButton type="submit" :disabled="form.processing || form.pack_list_ids.length === 0">Create shipment</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
