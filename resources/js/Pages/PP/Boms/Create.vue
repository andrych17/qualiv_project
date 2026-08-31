<!-- ponytail: Add BOM (PP_SPECS.md §3D) -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSwitch from '@/Components/forms/FormSwitch.vue'
import FormAsyncSearchableSelect from '@/Components/forms/FormAsyncSearchableSelect.vue'
import CustomFieldInputs, { type CustomFieldDef } from '@/Components/forms/CustomFieldInputs.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import BomLineListInput, { type BomLineRow } from '@/Components/pp/BomLineListInput.vue'

const props = defineProps<{
  customFields: CustomFieldDef[]
}>()

const customBag: Record<string, string> = {}
for (const f of props.customFields) {
  customBag[f.code] = f.value ?? ''
}

const form = useForm({
  product_id: null as number | null,
  effective_from: new Date().toISOString().slice(0, 10),
  effective_to: '',
  is_active: true,
  lines: [] as BomLineRow[],
  custom_fields: customBag,
})

const submit = () => form.post(route('pp.boms.store'))
</script>

<template>
  <AppLayout>
    <PageHeader title="Add BOM" description="Discrete Bill of Material — a new version is created automatically for this product." />

    <Panel class="mt-6 max-w-3xl">
      <form class="space-y-4" @submit.prevent="submit">
        <FormAsyncSearchableSelect
          v-model="form.product_id"
          name="product_id"
          label="Product"
          api-entity="inventory_product"
          placeholder="Search SKU or name…"
          :error="form.errors.product_id"
          required
        />

        <div class="grid grid-cols-2 gap-4">
          <FormInput v-model="form.effective_from" name="effective_from" label="Effective from" type="date" :error="form.errors.effective_from" required />
          <FormInput v-model="form.effective_to" name="effective_to" label="Effective to" type="date" :error="form.errors.effective_to" />
        </div>

        <FormSwitch v-model="form.is_active" name="is_active" label="Active version" />
        <p class="text-xs text-ink-600">Only one active version per product — marking this active deactivates any other active BOM for the same product.</p>

        <BomLineListInput v-model="form.lines" />
        <p v-if="form.errors.lines" class="text-sm text-signal-danger">{{ form.errors.lines }}</p>

        <CustomFieldInputs v-model="form.custom_fields" :fields="customFields" :errors="form.errors" />

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <Link
            :href="route('pp.boms.index')"
            class="inline-flex items-center justify-center rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm font-semibold text-ink-900 shadow-sm transition hover:bg-surface-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            Cancel
          </Link>
          <PrimaryButton type="submit" :disabled="form.processing">Save BOM</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
