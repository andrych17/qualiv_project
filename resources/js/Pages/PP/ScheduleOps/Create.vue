<!-- ponytail: Schedule Operation (PP_SPECS.md §3H) -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import FormSearchableSelect from '@/Components/forms/FormSearchableSelect.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'

defineProps<{
  plannedOrderOptions: Array<{ value: number; label: string }>
}>()

const resourceTypeOptions = [
  { value: 'mes_work_center', label: 'Work Center' },
  { value: 'mes_machine', label: 'Machine' },
  { value: 'mes_station', label: 'Station' },
]

const form = useForm({
  planned_order_id: null as number | null,
  seq: 1,
  resource_type: null as string | null,
  resource_ref_id: null as number | null,
  planned_start: '',
  planned_end: '',
  status: 'draft',
})

const submit = () => form.post(route('pp.scheduleOps.store'))
</script>

<template>
  <AppLayout>
    <PageHeader title="Schedule Operation" description="A finite, resource-and-time-specific proposal for one operation of a production planned order (§3H)." />

    <Panel class="mt-6 max-w-2xl">
      <form class="space-y-4" @submit.prevent="submit">
        <FormSearchableSelect
          v-model="form.planned_order_id"
          name="planned_order_id"
          label="Planned order"
          :options="plannedOrderOptions"
          :error="form.errors.planned_order_id"
          required
        />

        <FormInput v-model.number="form.seq" name="seq" type="number" min="1" label="Sequence" :error="form.errors.seq" />

        <div class="grid grid-cols-2 gap-4">
          <FormSelect
            v-model="form.resource_type"
            name="resource_type"
            label="Resource type"
            placeholder="Unassigned"
            :options="resourceTypeOptions"
            :error="form.errors.resource_type"
          />
          <FormInput v-model.number="form.resource_ref_id" name="resource_ref_id" type="number" label="Resource #" :error="form.errors.resource_ref_id" />
        </div>

        <div class="grid grid-cols-2 gap-4">
          <FormInput v-model="form.planned_start" name="planned_start" type="datetime-local" label="Start" :error="form.errors.planned_start" required />
          <FormInput v-model="form.planned_end" name="planned_end" type="datetime-local" label="End" :error="form.errors.planned_end" required />
        </div>

        <FormSelect
          v-model="form.status"
          name="status"
          label="Initial status"
          :options="[{ value: 'draft', label: 'Draft' }, { value: 'committed', label: 'Committed' }]"
          :error="form.errors.status"
        />

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <Link
            :href="route('pp.scheduleOps.index')"
            class="inline-flex items-center justify-center rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm font-semibold text-ink-900 shadow-sm transition hover:bg-surface-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            Cancel
          </Link>
          <PrimaryButton type="submit" :disabled="form.processing">Schedule Operation</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
