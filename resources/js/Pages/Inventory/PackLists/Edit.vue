<!-- ponytail: Pack List edit/view (§3P) — editable while unshipped; read-only once
     shipment_id is set (fields disabled, no save/delete), same posture as a posted Goods Issue. -->
<script setup lang="ts">
import { useForm, Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import InventorySubNav from '@/Components/inventory/InventorySubNav.vue'
import PackListLineSelector, { type AvailablePackLine, type PackLineRow } from '@/Components/inventory/PackListLineSelector.vue'
import { useConfirm } from '@/Composables/useConfirmDialog'

const props = defineProps<{
  packList: {
    id: number
    pick_list_id: number
    package_type: 'carton' | 'pallet'
    weight: number | null
    weight_uom: string | null
    length: number | null
    width: number | null
    height: number | null
    dimension_uom: string | null
    shipment_id: number | null
    status: 'packed' | 'shipped'
    lines: PackLineRow[]
  }
  pickList: { id: number; warehouse_id: number; warehouse_name: string | null; status: string }
  availableLines: AvailablePackLine[]
}>()

const readOnly = props.packList.shipment_id !== null

const form = useForm({
  package_type: props.packList.package_type,
  weight: props.packList.weight,
  weight_uom: props.packList.weight_uom ?? 'kg',
  length: props.packList.length,
  width: props.packList.width,
  height: props.packList.height,
  dimension_uom: props.packList.dimension_uom ?? 'cm',
  lines: [...props.packList.lines] as PackLineRow[],
})

const submit = () => form.put(route('inventory.packLists.update', props.packList.id))

const { confirm } = useConfirm()

const confirmDelete = () => {
  confirm({
    title: `Delete package #${props.packList.id}?`,
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () => router.delete(route('inventory.packLists.destroy', props.packList.id)),
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader :title="`Package #${packList.id}`" :description="`Pick List #${packList.pick_list_id} — ${pickList.warehouse_name}`">
      <template #actions>
        <Link :href="route('inventory.packLists.index')" class="text-sm font-medium text-accent hover:underline">Back</Link>
      </template>
    </PageHeader>

    <InventorySubNav active="packLists" class="mt-6" />

    <Panel class="mt-6 max-w-3xl">
      <div class="mb-4 flex items-center gap-2">
        <StatusBadge :status="packList.status" />
        <span v-if="readOnly" class="text-xs text-ink-600">On shipment #{{ packList.shipment_id }} — no longer editable.</span>
      </div>

      <form class="space-y-4" @submit.prevent="submit">
        <div class="grid grid-cols-2 gap-4">
          <FormSelect
            v-model="form.package_type"
            name="package_type"
            label="Package type"
            :options="[{ label: 'Carton', value: 'carton' }, { label: 'Pallet', value: 'pallet' }]"
            :error="form.errors.package_type"
            :disabled="readOnly"
          />
        </div>

        <div class="grid grid-cols-2 gap-4">
          <FormInput v-model.number="form.weight" name="weight" type="number" step="0.0001" label="Weight" :error="form.errors.weight" :disabled="readOnly" />
          <FormInput v-model="form.weight_uom" name="weight_uom" label="Weight unit" :error="form.errors.weight_uom" :disabled="readOnly" />
        </div>

        <div class="grid grid-cols-4 gap-4">
          <FormInput v-model.number="form.length" name="length" type="number" step="0.01" label="Length" :error="form.errors.length" :disabled="readOnly" />
          <FormInput v-model.number="form.width" name="width" type="number" step="0.01" label="Width" :error="form.errors.width" :disabled="readOnly" />
          <FormInput v-model.number="form.height" name="height" type="number" step="0.01" label="Height" :error="form.errors.height" :disabled="readOnly" />
          <FormInput v-model="form.dimension_uom" name="dimension_uom" label="Dimension unit" :error="form.errors.dimension_uom" :disabled="readOnly" />
        </div>

        <div v-if="!readOnly">
          <p class="mb-2 text-sm font-medium text-ink-900">Contents</p>
          <PackListLineSelector v-model="form.lines" :available-lines="availableLines" />
          <p v-if="form.errors.lines" class="mt-2 text-sm text-signal-danger">{{ form.errors.lines }}</p>
        </div>

        <div v-if="!readOnly" class="flex items-center justify-between border-t border-border pt-4">
          <button type="button" class="text-sm font-medium text-signal-danger hover:underline" @click="confirmDelete">Delete package</button>
          <PrimaryButton type="submit" :disabled="form.processing || form.lines.length === 0">Save</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
