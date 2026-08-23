<!-- ponytail: Accounting §3B/§3I cost centers — edit form. -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSearchableSelect from '@/Components/forms/FormSearchableSelect.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'

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
    <PageHeader title="Edit cost center" :description="`${costCenter.code} — ${costCenter.name}`" />

    <Panel class="mt-6 max-w-xl">
      <form class="space-y-4" @submit.prevent="submit">
        <FormInput v-model="form.code" name="code" label="Code" :error="form.errors.code" required />
        <FormInput v-model="form.name" name="name" label="Name" :error="form.errors.name" required />
        <FormSearchableSelect v-model="form.parent_cost_center_id" name="parent_cost_center_id" label="Parent cost center" placeholder="No parent (top-level)" :options="parents" :error="form.errors.parent_cost_center_id" />

        <label class="flex items-center gap-2 text-sm text-ink-900">
          <input v-model="form.is_active" type="checkbox" class="rounded border-border" />
          Active
        </label>

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <Link
            :href="route('accounting.cost-centers.index', { company_id: costCenter.company_id })"
            class="inline-flex items-center justify-center rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm font-semibold text-ink-900 shadow-sm transition hover:bg-surface-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            Cancel
          </Link>
          <PrimaryButton type="submit" :disabled="form.processing">Save changes</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
