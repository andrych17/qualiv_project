<!-- ponytail: Edit Capacity Plan (PP_SPECS.md §3F) -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormNumberInput from '@/Components/forms/FormNumberInput.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import CapacityTargetInput, { type CapacityTarget } from '@/Components/pp/CapacityTargetInput.vue'

const props = defineProps<{
  plan: {
    id: number
    resource_group_id: number | null
    resource_type: string | null
    resource_ref_id: number | null
    period_start: string
    period_end: string
    required_hours: number
    available_hours: number
  }
  resourceGroupOptions: Array<{ value: number; label: string }>
  resourceOptions: Array<{ value: number; label: string }>
}>()

const form = useForm({
  resource_group_id: props.plan.resource_group_id,
  resource_type: props.plan.resource_type,
  resource_ref_id: props.plan.resource_ref_id,
  period_start: props.plan.period_start,
  period_end: props.plan.period_end,
  required_hours: props.plan.required_hours,
  available_hours: props.plan.available_hours,
})

const target = () => ({
  resource_group_id: form.resource_group_id,
  resource_type: form.resource_type,
  resource_ref_id: form.resource_ref_id,
})
const setTarget = (value: CapacityTarget) => {
  form.resource_group_id = value.resource_group_id
  form.resource_type = value.resource_type
  form.resource_ref_id = value.resource_ref_id
}

const submit = () => form.put(route('pp.capacityPlans.update', props.plan.id))
</script>

<template>
  <AppLayout>
    <PageHeader title="Edit Capacity Plan" :description="`${plan.period_start} – ${plan.period_end}`" />

    <Panel class="mt-6 max-w-2xl">
      <form class="space-y-4" @submit.prevent="submit">
        <CapacityTargetInput
          :model-value="target()"
          :resource-group-options="resourceGroupOptions"
          :resource-options="resourceOptions"
          :errors="{ resource_group_id: form.errors.resource_group_id, resource_type: form.errors.resource_type, resource_ref_id: form.errors.resource_ref_id }"
          @update:model-value="setTarget"
        />

        <div class="grid grid-cols-2 gap-4">
          <FormInput v-model="form.period_start" name="period_start" label="Period start" type="date" :error="form.errors.period_start" required />
          <FormInput v-model="form.period_end" name="period_end" label="Period end" type="date" :error="form.errors.period_end" required />
        </div>

        <div class="grid grid-cols-2 gap-4">
          <FormNumberInput v-model="form.required_hours" name="required_hours" label="Required hours" :decimals="2" suffix="hr" :error="form.errors.required_hours" required />
          <FormNumberInput v-model="form.available_hours" name="available_hours" label="Available hours" :decimals="2" suffix="hr" :error="form.errors.available_hours" required />
        </div>

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <Link
            :href="route('pp.capacityPlans.index')"
            class="inline-flex items-center justify-center rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm font-semibold text-ink-900 shadow-sm transition hover:bg-surface-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            Cancel
          </Link>
          <PrimaryButton type="submit" :disabled="form.processing">Save Capacity Plan</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
