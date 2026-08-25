<!-- ponytail: Add Location (§3C) — nested under a warehouse -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import FormSearchableSelect from '@/Components/forms/FormSearchableSelect.vue'
import InventorySubNav from '@/Components/inventory/InventorySubNav.vue'
import LocationBarcodeListInput, { type LocationBarcodeRow } from '@/Components/inventory/LocationBarcodeListInput.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'

const props = defineProps<{
  warehouse: { id: number; name: string }
  parents: Array<{ value: number; label: string }>
}>()

const form = useForm({
  code: '',
  parent_location_id: null as number | null,
  type: 'bin',
  barcodes: [] as LocationBarcodeRow[],
})

const submit = () => form.post(route('inventory.warehouses.locations.store', props.warehouse.id))
</script>

<template>
  <AppLayout>
    <PageHeader title="Add location" :description="warehouse.name" />

    <InventorySubNav active="warehouses" class="mt-6" />

    <Panel class="mt-6 max-w-xl">
      <form class="space-y-4" @submit.prevent="submit">
        <FormInput v-model="form.code" name="code" label="Code" placeholder="e.g. A, A-01, A-01-01" :error="form.errors.code" required />
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
        <LocationBarcodeListInput v-model="form.barcodes" />

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <Link
            :href="route('inventory.warehouses.edit', warehouse.id)"
            class="inline-flex items-center justify-center rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm font-semibold text-ink-900 shadow-sm transition hover:bg-surface-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            Cancel
          </Link>
          <PrimaryButton type="submit" :disabled="form.processing">Save location</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
