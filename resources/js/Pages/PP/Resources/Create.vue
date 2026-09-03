<!-- ponytail: Add Resource (PP_SPECS.md §3E) -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import FormSwitch from '@/Components/forms/FormSwitch.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'

const typeOptions = [
  { value: 'tool', label: 'Tool' },
  { value: 'tank', label: 'Tank' },
  { value: 'utility', label: 'Utility' },
  { value: 'warehouse', label: 'Warehouse' },
]

const form = useForm({
  type: 'tool',
  code: '',
  name: '',
  capacity: null as number | null,
  uom_code: '',
  external_type: '',
  external_id: null as number | null,
  is_active: true,
})

const submit = () => form.post(route('pp.resources.store'))
</script>

<template>
  <AppLayout>
    <PageHeader title="Add Resource" description="A resource type no other Core module owns yet — tool, tank, utility, or warehouse capacity." />

    <Panel class="mt-6 max-w-2xl">
      <form class="space-y-4" @submit.prevent="submit">
        <FormSelect v-model="form.type" name="type" label="Type" :options="typeOptions" :error="form.errors.type" required />

        <div class="grid grid-cols-2 gap-4">
          <FormInput v-model="form.code" name="code" label="Code" :error="form.errors.code" required />
          <FormInput v-model="form.name" name="name" label="Name" :error="form.errors.name" required />
        </div>

        <div class="grid grid-cols-2 gap-4">
          <FormInput v-model="form.capacity" name="capacity" label="Capacity" type="number" :error="form.errors.capacity" />
          <FormInput v-model="form.uom_code" name="uom_code" label="UoM code" placeholder="e.g. HOURS, KG, L" :error="form.errors.uom_code" />
        </div>

        <FormSwitch v-model="form.is_active" name="is_active" label="Active" />

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <Link
            :href="route('pp.resources.index')"
            class="inline-flex items-center justify-center rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm font-semibold text-ink-900 shadow-sm transition hover:bg-surface-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            Cancel
          </Link>
          <PrimaryButton type="submit" :disabled="form.processing">Save Resource</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
