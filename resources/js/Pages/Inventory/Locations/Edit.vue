<!-- ponytail: Edit Location (§3C) — nested under a warehouse -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import FormSearchableSelect from '@/Components/forms/FormSearchableSelect.vue'
import FormSwitch from '@/Components/forms/FormSwitch.vue'
import InventorySubNav from '@/Components/inventory/InventorySubNav.vue'
import LocationBarcodeListInput, { type LocationBarcodeRow } from '@/Components/inventory/LocationBarcodeListInput.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'

const props = defineProps<{
  warehouse: { id: number; name: string }
  location: { id: number; code: string; parent_location_id: number | null; type: string; is_active: boolean; barcodes: LocationBarcodeRow[] }
  parents: Array<{ value: number; label: string }>
}>()

const form = useForm({
  code: props.location.code,
  parent_location_id: props.location.parent_location_id,
  type: props.location.type,
  is_active: props.location.is_active,
  barcodes: props.location.barcodes.map((b) => ({ ...b })),
})

const submit = () => form.put(route('inventory.warehouses.locations.update', [props.warehouse.id, props.location.id]))
</script>

<template>
  <AppLayout>
    <PageHeader title="Edit location" :description="`${warehouse.name} — ${location.code}`" />

    <InventorySubNav active="warehouses" class="mt-6" />

    <Panel class="mt-6 max-w-xl">
      <form class="space-y-4" @submit.prevent="submit">
        <FormInput v-model="form.code" name="code" label="Code" :error="form.errors.code" required />
        <FormSearchableSelect v-model="form.parent_location_id" name="parent_location_id" label="Parent location" placeholder="No parent (top-level)" :options="parents" :error="form.errors.parent_location_id" />
        <FormSelect
          v-model="form.type"
          name="type"
          label="Type"
          :options="[
            { label: 'Zone', value: 'zone' },
            { label: 'Bin', value: 'bin' },
            { label: 'Staging', value: 'staging' },
            { label: 'Dock', value: 'dock' },
          ]"
          :error="form.errors.type"
          required
        />
        <FormSwitch v-model="form.is_active" label="Active" description="Inactive locations are hidden as put-away/pick destinations." />
        <LocationBarcodeListInput v-model="form.barcodes" />

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <Link
            :href="route('inventory.warehouses.edit', warehouse.id)"
            class="inline-flex items-center justify-center rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm font-semibold text-ink-900 shadow-sm transition hover:bg-surface-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            Cancel
          </Link>
          <PrimaryButton type="submit" :disabled="form.processing">Update location</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
