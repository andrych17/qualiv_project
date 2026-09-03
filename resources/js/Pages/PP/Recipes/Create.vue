<!-- ponytail: Add Recipe (PP_SPECS.md §3D) -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormNumberInput from '@/Components/forms/FormNumberInput.vue'
import FormSwitch from '@/Components/forms/FormSwitch.vue'
import FormAsyncSearchableSelect from '@/Components/forms/FormAsyncSearchableSelect.vue'
import CustomFieldInputs, { type CustomFieldDef } from '@/Components/forms/CustomFieldInputs.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import RecipeIngredientListInput, { type RecipeIngredientRow } from '@/Components/pp/RecipeIngredientListInput.vue'

const props = defineProps<{
  customFields: CustomFieldDef[]
}>()

const customBag: Record<string, string> = {}
for (const f of props.customFields) {
  customBag[f.code] = f.value ?? ''
}

const form = useForm({
  product_id: null as number | null,
  batch_size: 1,
  uom_code: '',
  expected_yield_pct: 100,
  expected_waste_pct: 0,
  effective_from: new Date().toISOString().slice(0, 10),
  effective_to: '',
  is_active: true,
  ingredients: [] as RecipeIngredientRow[],
  custom_fields: customBag,
})

const submit = () => form.post(route('pp.recipes.store'))
</script>

<template>
  <AppLayout>
    <PageHeader title="Add recipe" description="Process formula — a new version is created automatically for this product." />

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
          <FormNumberInput v-model="form.batch_size" name="batch_size" label="Batch size" :decimals="4" :error="form.errors.batch_size" required />
          <FormInput v-model="form.uom_code" name="uom_code" label="UoM code" placeholder="e.g. L" :error="form.errors.uom_code" />
        </div>

        <div class="grid grid-cols-2 gap-4">
          <FormNumberInput v-model="form.expected_yield_pct" name="expected_yield_pct" label="Expected yield %" :decimals="2" suffix="%" :error="form.errors.expected_yield_pct" />
          <FormNumberInput v-model="form.expected_waste_pct" name="expected_waste_pct" label="Expected waste %" :decimals="2" suffix="%" :error="form.errors.expected_waste_pct" />
        </div>

        <div class="grid grid-cols-2 gap-4">
          <FormInput v-model="form.effective_from" name="effective_from" label="Effective from" type="date" :error="form.errors.effective_from" required />
          <FormInput v-model="form.effective_to" name="effective_to" label="Effective to" type="date" :error="form.errors.effective_to" />
        </div>

        <FormSwitch v-model="form.is_active" name="is_active" label="Active version" />
        <p class="text-xs text-ink-600">Only one active version per product — marking this active deactivates any other active recipe for the same product.</p>

        <RecipeIngredientListInput v-model="form.ingredients" />
        <p v-if="form.errors.ingredients" class="text-sm text-signal-danger">{{ form.errors.ingredients }}</p>

        <CustomFieldInputs v-model="form.custom_fields" :fields="customFields" :errors="form.errors" />

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <Link
            :href="route('pp.recipes.index')"
            class="inline-flex items-center justify-center rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm font-semibold text-ink-900 shadow-sm transition hover:bg-surface-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            Cancel
          </Link>
          <PrimaryButton type="submit" :disabled="form.processing">Save recipe</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
