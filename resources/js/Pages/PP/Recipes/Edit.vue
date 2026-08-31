<!-- ponytail: Edit Recipe (PP_SPECS.md §3D) -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormNumberInput from '@/Components/forms/FormNumberInput.vue'
import FormSwitch from '@/Components/forms/FormSwitch.vue'
import CustomFieldInputs, { type CustomFieldDef } from '@/Components/forms/CustomFieldInputs.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import RecipeIngredientListInput, { type RecipeIngredientRow } from '@/Components/pp/RecipeIngredientListInput.vue'

const props = defineProps<{
  recipe: {
    id: number
    product_id: number
    product_label: string | null
    version: number
    batch_size: number
    uom_code: string | null
    expected_yield_pct: number
    expected_waste_pct: number
    effective_from: string
    effective_to: string | null
    is_active: boolean
    ingredients: RecipeIngredientRow[]
  }
  customFields: CustomFieldDef[]
}>()

const customBag: Record<string, string> = {}
for (const f of props.customFields) {
  customBag[f.code] = f.value ?? ''
}

const form = useForm({
  batch_size: props.recipe.batch_size,
  uom_code: props.recipe.uom_code ?? '',
  expected_yield_pct: props.recipe.expected_yield_pct,
  expected_waste_pct: props.recipe.expected_waste_pct,
  effective_from: props.recipe.effective_from,
  effective_to: props.recipe.effective_to ?? '',
  is_active: props.recipe.is_active,
  ingredients: props.recipe.ingredients,
  custom_fields: customBag,
})

const submit = () => form.put(route('pp.recipes.update', props.recipe.id))
</script>

<template>
  <AppLayout>
    <PageHeader title="Edit recipe" :description="recipe.product_label ? `${recipe.product_label} — v${recipe.version}` : undefined" />

    <Panel class="mt-6 max-w-3xl">
      <form class="space-y-4" @submit.prevent="submit">
        <FormInput :model-value="recipe.product_label ?? ''" name="product_label" label="Product" disabled />

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
