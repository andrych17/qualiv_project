<!-- ponytail: New Pack List (§3P) — always arrives with ?pick_list_id= from a PickList's Show
     page; the bare-URL fallback just asks the user to pick one first. -->
<script setup lang="ts">
import { useForm, Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import InventorySubNav from '@/Components/inventory/InventorySubNav.vue'
import PackListLineSelector, { type AvailablePackLine, type PackLineRow } from '@/Components/inventory/PackListLineSelector.vue'

const props = defineProps<{
  pickList: { id: number; warehouse_id: number; warehouse_name: string | null; status: string } | null
  availableLines: AvailablePackLine[]
  eligiblePickLists: Array<{ id: number; warehouse_id: number; warehouse_name: string | null; status: string }>
}>()

const form = useForm({
  pick_list_id: props.pickList?.id ?? null,
  package_type: 'carton' as 'carton' | 'pallet',
  weight: null as number | null,
  weight_uom: 'kg',
  length: null as number | null,
  width: null as number | null,
  height: null as number | null,
  dimension_uom: 'cm',
  lines: [] as PackLineRow[],
})

const goToPickList = (id: string | number) => {
  router.get(route('inventory.packLists.create'), { pick_list_id: id })
}

const submit = () => form.post(route('inventory.packLists.store'))
</script>

<template>
  <AppLayout>
    <PageHeader title="New Package" description="Group picked lines into one physical package and capture weight/dimensions." />

    <InventorySubNav active="packLists" class="mt-6" />

    <Panel v-if="!pickList" class="mt-6 max-w-lg">
      <FormSelect
        :model-value="null"
        name="pick_list_id"
        label="Pick list"
        placeholder="Select a pick list with picked lines…"
        :options="eligiblePickLists.map((p) => ({ label: `#${p.id} — ${p.warehouse_name}`, value: p.id }))"
        @update:model-value="goToPickList"
      />
    </Panel>

    <Panel v-else class="mt-6 max-w-3xl">
      <form class="space-y-4" @submit.prevent="submit">
        <p class="text-sm text-ink-600">Pick List #{{ pickList.id }} — {{ pickList.warehouse_name }}</p>

        <div class="grid grid-cols-2 gap-4">
          <FormSelect
            v-model="form.package_type"
            name="package_type"
            label="Package type"
            :options="[{ label: 'Carton', value: 'carton' }, { label: 'Pallet', value: 'pallet' }]"
            :error="form.errors.package_type"
          />
        </div>

        <div class="grid grid-cols-2 gap-4">
          <FormInput v-model.number="form.weight" name="weight" type="number" step="0.0001" label="Weight" :error="form.errors.weight" />
          <FormInput v-model="form.weight_uom" name="weight_uom" label="Weight unit" placeholder="kg" :error="form.errors.weight_uom" />
        </div>

        <div class="grid grid-cols-4 gap-4">
          <FormInput v-model.number="form.length" name="length" type="number" step="0.01" label="Length" :error="form.errors.length" />
          <FormInput v-model.number="form.width" name="width" type="number" step="0.01" label="Width" :error="form.errors.width" />
          <FormInput v-model.number="form.height" name="height" type="number" step="0.01" label="Height" :error="form.errors.height" />
          <FormInput v-model="form.dimension_uom" name="dimension_uom" label="Dimension unit" placeholder="cm" :error="form.errors.dimension_uom" />
        </div>

        <div>
          <p class="mb-2 text-sm font-medium text-ink-900">Contents</p>
          <PackListLineSelector v-model="form.lines" :available-lines="availableLines" />
          <p v-if="form.errors.lines" class="mt-2 text-sm text-signal-danger">{{ form.errors.lines }}</p>
        </div>

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <Link
            :href="route('inventory.pickLists.show', pickList.id)"
            class="inline-flex items-center justify-center rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm font-semibold text-ink-900 shadow-sm transition hover:bg-surface-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            Cancel
          </Link>
          <PrimaryButton type="submit" :disabled="form.processing || form.lines.length === 0">Create package</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
