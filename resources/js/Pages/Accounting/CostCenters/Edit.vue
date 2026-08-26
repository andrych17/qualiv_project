<!-- ponytail: Accounting §3B/§3I cost centers — edit form. -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSearchableSelect from '@/Components/forms/FormSearchableSelect.vue'
import FormSwitch from '@/Components/forms/FormSwitch.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'

const props = defineProps<{
  costCenter: {
    id: number
    company_id: number
    code: string
    name: string
    parent_cost_center_id: number | null
    is_active: boolean
  }
  parents: Array<{ value: number; label: string }>
}>()

const form = useForm({
  code: props.costCenter.code,
  name: props.costCenter.name,
  parent_cost_center_id: props.costCenter.parent_cost_center_id,
  is_active: props.costCenter.is_active,
})

const submit = () => form.put(route('accounting.cost-centers.update', props.costCenter.id))
</script>

<template>
  <AppLayout>
    <PageHeader title="Edit Cost Center" :description="`${costCenter.code} — ${costCenter.name}`" />

    <Panel class="mt-6 max-w-xl">
      <form class="space-y-4" @submit.prevent="submit">
        <FormInput v-model="form.code" name="code" label="Cost Center Code" :error="form.errors.code" required />
        <FormInput v-model="form.name" name="name" label="Cost Center Name" :error="form.errors.name" required />
        <FormSearchableSelect v-model="form.parent_cost_center_id" name="parent_cost_center_id" label="Parent Cost Center" placeholder="No parent (top-level)" :options="parents" :error="form.errors.parent_cost_center_id" />

        <FormSwitch
          v-model="form.is_active"
          name="is_active"
          label="Active Status"
        />

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <SecondaryButton :href="route('accounting.cost-centers.index', { company_id: costCenter.company_id })">
            Cancel
          </SecondaryButton>
          <PrimaryButton type="submit" :disabled="form.processing">Save Changes</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
