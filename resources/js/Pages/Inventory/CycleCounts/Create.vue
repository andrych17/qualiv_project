<!-- ponytail: New Cycle Count (§3Q) — scoped exactly one of three ways; lines are generated
     from matching stock_balances immediately on submit. -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import { computed, watch } from 'vue'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import FormRadioGroup from '@/Components/forms/FormRadioGroup.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import InventorySubNav from '@/Components/inventory/InventorySubNav.vue'

const props = defineProps<{
  warehouses: Array<{ id: number; name: string }>
  locations: Array<{ id: number; warehouse_id: number; code: string }>
  categories: Array<{ id: number; name: string }>
  assignees: Array<{ id: number; name: string }>
}>()

const form = useForm({
  warehouse_id: null as number | null,
  scope_type: 'location' as 'location' | 'category' | 'abc_class',
  location_id: null as number | null,
  category_id: null as number | null,
  abc_class: '' as string,
  assigned_to: null as number | null,
  scheduled_date: '',
})

const availableLocations = computed(() =>
  form.warehouse_id ? props.locations.filter((l) => l.warehouse_id === form.warehouse_id) : [],
)

watch(() => form.scope_type, () => {
  form.location_id = null
  form.category_id = null
  form.abc_class = ''
})

const submit = () => form.post(route('inventory.cycleCounts.store'))
</script>

<template>
  <AppLayout>
    <PageHeader title="New Cycle Count" description="Lines are generated from current stock balances matching the scope you pick." />

    <InventorySubNav active="cycleCounts" class="mt-6" />

    <Panel class="mt-6 max-w-2xl">
      <form class="space-y-4" @submit.prevent="submit">
        <FormSelect
          v-model="form.warehouse_id"
          name="warehouse_id"
          label="Warehouse"
          placeholder="Select a warehouse…"
          :options="warehouses.map((w) => ({ label: w.name, value: w.id }))"
          :error="form.errors.warehouse_id"
          required
        />

        <FormRadioGroup
          v-model="form.scope_type"
          name="scope_type"
          label="Scope"
          :options="[
            { label: 'By location', value: 'location', description: 'Count every product currently balanced at one bin.' },
            { label: 'By category', value: 'category', description: 'Count one product category across the whole warehouse.' },
            { label: 'By ABC class', value: 'abc_class', description: 'Count every product flagged A, B, or C across the warehouse.' },
          ]"
        />

        <FormSelect
          v-if="form.scope_type === 'location'"
          v-model="form.location_id"
          name="location_id"
          label="Location"
          placeholder="Select a location…"
          :options="availableLocations.map((l) => ({ label: l.code, value: l.id }))"
          :error="form.errors.location_id"
          required
        />
        <FormSelect
          v-if="form.scope_type === 'category'"
          v-model="form.category_id"
          name="category_id"
          label="Category"
          placeholder="Select a category…"
          :options="categories.map((c) => ({ label: c.name, value: c.id }))"
          :error="form.errors.category_id"
          required
        />
        <FormSelect
          v-if="form.scope_type === 'abc_class'"
          v-model="form.abc_class"
          name="abc_class"
          label="ABC class"
          placeholder="Select a class…"
          :options="[{ label: 'A', value: 'A' }, { label: 'B', value: 'B' }, { label: 'C', value: 'C' }]"
          :error="form.errors.abc_class"
          required
        />

        <div class="grid grid-cols-2 gap-4">
          <FormSelect
            v-model="form.assigned_to"
            name="assigned_to"
            label="Assign to"
            placeholder="Unassigned"
            :options="assignees.map((a) => ({ label: a.name, value: a.id }))"
            :error="form.errors.assigned_to"
          />
          <FormInput v-model="form.scheduled_date" name="scheduled_date" type="date" label="Scheduled date" :error="form.errors.scheduled_date" />
        </div>

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <Link
            :href="route('inventory.cycleCounts.index')"
            class="inline-flex items-center justify-center rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm font-semibold text-ink-900 shadow-sm transition hover:bg-surface-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            Cancel
          </Link>
          <PrimaryButton type="submit" :disabled="form.processing">Generate count</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
