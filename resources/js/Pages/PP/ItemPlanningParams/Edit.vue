<!-- ponytail: Edit Item Planning Parameters (PP_SPECS.md §3A) -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormNumberInput from '@/Components/forms/FormNumberInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import CustomFieldInputs, { type CustomFieldDef } from '@/Components/forms/CustomFieldInputs.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'

interface ParamFormData {
  id: number
  product_id: number
  product_label: string | null
  make_type: 'mts' | 'mto'
  min_lot_qty: number | null
  max_lot_qty: number | null
  fixed_lot_qty: number | null
  economic_lot_qty: number | null
  safety_stock_qty: number
  lead_time_days: number
  planning_lead_time_days: number
  order_multiple: number | null
  scrap_pct: number
  yield_pct_override: number | null
  production_calendar_ref: string | null
  preferred_line_ref_id: number | null
  alternate_line_ref_id: number | null
  planning_fence_days: number
}

const props = defineProps<{
  param: ParamFormData
  customFields: CustomFieldDef[]
}>()

const customBag: Record<string, string> = {}
for (const f of props.customFields) {
  customBag[f.code] = f.value ?? ''
}

const form = useForm({
  product_id: props.param.product_id,
  make_type: props.param.make_type,
  min_lot_qty: props.param.min_lot_qty,
  max_lot_qty: props.param.max_lot_qty,
  fixed_lot_qty: props.param.fixed_lot_qty,
  economic_lot_qty: props.param.economic_lot_qty,
  safety_stock_qty: props.param.safety_stock_qty,
  lead_time_days: props.param.lead_time_days,
  planning_lead_time_days: props.param.planning_lead_time_days,
  order_multiple: props.param.order_multiple,
  scrap_pct: props.param.scrap_pct,
  yield_pct_override: props.param.yield_pct_override,
  production_calendar_ref: props.param.production_calendar_ref ?? '',
  preferred_line_ref_id: props.param.preferred_line_ref_id,
  alternate_line_ref_id: props.param.alternate_line_ref_id,
  planning_fence_days: props.param.planning_fence_days,
  custom_fields: customBag,
})

const submit = () => form.put(route('pp.itemPlanningParams.update', props.param.id))
</script>

<template>
  <AppLayout>
    <PageHeader title="Edit planning parameters" :description="param.product_label ?? undefined" />

    <Panel class="mt-6 max-w-2xl">
      <form class="space-y-4" @submit.prevent="submit">
        <FormInput
          :model-value="param.product_label ?? ''"
          name="product_label"
          label="Product"
          disabled
        />

        <FormSelect
          v-model="form.make_type"
          name="make_type"
          label="Make type"
          :options="[
            { label: 'Make-to-Stock (MTS)', value: 'mts' },
            { label: 'Make-to-Order (MTO)', value: 'mto' },
          ]"
          :error="form.errors.make_type"
          required
        />

        <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
          <FormNumberInput v-model="form.min_lot_qty" name="min_lot_qty" label="Min lot qty" :decimals="4" :error="form.errors.min_lot_qty" />
          <FormNumberInput v-model="form.max_lot_qty" name="max_lot_qty" label="Max lot qty" :decimals="4" :error="form.errors.max_lot_qty" />
          <FormNumberInput v-model="form.fixed_lot_qty" name="fixed_lot_qty" label="Fixed lot qty" :decimals="4" :error="form.errors.fixed_lot_qty" />
          <FormNumberInput v-model="form.economic_lot_qty" name="economic_lot_qty" label="Economic lot qty" :decimals="4" :error="form.errors.economic_lot_qty" />
        </div>

        <div class="grid grid-cols-2 gap-4">
          <FormNumberInput v-model="form.safety_stock_qty" name="safety_stock_qty" label="Safety stock qty" :decimals="4" :error="form.errors.safety_stock_qty" required />
          <FormNumberInput v-model="form.order_multiple" name="order_multiple" label="Order multiple" :decimals="4" :error="form.errors.order_multiple" />
        </div>

        <div class="grid grid-cols-2 gap-4">
          <FormNumberInput v-model="form.lead_time_days" name="lead_time_days" label="Lead time (days)" :error="form.errors.lead_time_days" />
          <FormNumberInput v-model="form.planning_lead_time_days" name="planning_lead_time_days" label="Planning lead time (days)" :error="form.errors.planning_lead_time_days" />
        </div>

        <div class="grid grid-cols-2 gap-4">
          <FormNumberInput v-model="form.scrap_pct" name="scrap_pct" label="Scrap %" :decimals="2" suffix="%" :error="form.errors.scrap_pct" />
          <FormNumberInput v-model="form.yield_pct_override" name="yield_pct_override" label="Yield % override" :decimals="2" suffix="%" :error="form.errors.yield_pct_override" />
        </div>

        <FormInput
          v-model="form.production_calendar_ref"
          name="production_calendar_ref"
          label="Production calendar ref"
          placeholder="Informational — SCHEDULE resource/calendar code"
          :error="form.errors.production_calendar_ref"
        />

        <div class="grid grid-cols-2 gap-4">
          <FormNumberInput
            v-model="form.preferred_line_ref_id"
            name="preferred_line_ref_id"
            label="Preferred production line ref"
            :error="form.errors.preferred_line_ref_id"
          />
          <FormNumberInput
            v-model="form.alternate_line_ref_id"
            name="alternate_line_ref_id"
            label="Alternate production line ref"
            :error="form.errors.alternate_line_ref_id"
          />
        </div>
        <p class="text-xs text-ink-600">Production line refs are informational (MES.mes_work_centers) until MES ships.</p>

        <FormNumberInput v-model="form.planning_fence_days" name="planning_fence_days" label="Planning fence (days)" :error="form.errors.planning_fence_days" />

        <CustomFieldInputs
          v-model="form.custom_fields"
          :fields="customFields"
          :errors="form.errors"
        />

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <Link
            :href="route('pp.itemPlanningParams.index')"
            class="inline-flex items-center justify-center rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm font-semibold text-ink-900 shadow-sm transition hover:bg-surface-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            Cancel
          </Link>
          <PrimaryButton type="submit" :disabled="form.processing">Save parameters</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
