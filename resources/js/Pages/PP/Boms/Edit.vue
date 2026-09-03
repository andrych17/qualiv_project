<!-- ponytail: Edit BOM (PP_SPECS.md §3D) -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSwitch from '@/Components/forms/FormSwitch.vue'
import CustomFieldInputs, { type CustomFieldDef } from '@/Components/forms/CustomFieldInputs.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import BomLineListInput, { type BomLineRow } from '@/Components/pp/BomLineListInput.vue'

const props = defineProps<{
  bom: {
    id: number
    product_id: number
    product_label: string | null
    version: number
    effective_from: string
    effective_to: string | null
    is_active: boolean
    lines: BomLineRow[]
  }
  customFields: CustomFieldDef[]
}>()

const customBag: Record<string, string> = {}
for (const f of props.customFields) {
  customBag[f.code] = f.value ?? ''
}

const form = useForm({
  effective_from: props.bom.effective_from,
  effective_to: props.bom.effective_to ?? '',
  is_active: props.bom.is_active,
  lines: props.bom.lines,
  custom_fields: customBag,
})

const submit = () => form.put(route('pp.boms.update', props.bom.id))
</script>

<template>
  <AppLayout>
    <PageHeader title="Edit BOM" :description="bom.product_label ? `${bom.product_label} — v${bom.version}` : undefined" />

    <Panel class="mt-6 max-w-3xl">
      <form class="space-y-4" @submit.prevent="submit">
        <FormInput :model-value="bom.product_label ?? ''" name="product_label" label="Product" disabled />

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
