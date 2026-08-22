<!-- ponytail: Schedule field visit (§3M) -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'

const props = defineProps<{
  visitTypes: Array<{ id: number; name: string }>
  matters: Array<{ id: number; code: string; title: string }>
  landObjects: Array<{ id: number; certificate_number: string }>
  deeds: Array<{ id: number; deed_number: string | null; uuid: string }>
  assignees: Array<{ id: number; name: string }>
}>()

const form = useForm({
  visit_type_id: null as number | null,
  matter_id: null as number | null,
  land_object_id: null as number | null,
  deed_id: null as number | null,
  assigned_to: null as number | null,
  notes: '',
})

const submit = () => form.post(route('legal.fieldVisits.store'))
</script>

<template>
  <AppLayout>
    <PageHeader title="Schedule field visit" description="Calendar linking (Schedule module) lands later — this books the visit record itself." />

    <Panel class="mt-6 max-w-xl">
      <form class="space-y-4" @submit.prevent="submit">
        <FormSelect
          v-model="form.visit_type_id"
          name="visit_type_id"
          label="Visit type"
          placeholder="Select a type"
          :options="visitTypes.map((t) => ({ label: t.name, value: t.id }))"
          :error="form.errors.visit_type_id"
          required
        />
        <FormSelect
          v-model="form.matter_id"
          name="matter_id"
          label="Matter"
          placeholder="None"
          :options="matters.map((m) => ({ label: `${m.code} — ${m.title}`, value: m.id }))"
          :error="form.errors.matter_id"
        />
        <FormSelect
          v-model="form.land_object_id"
          name="land_object_id"
          label="Land object"
          placeholder="None"
          :options="landObjects.map((o) => ({ label: o.certificate_number, value: o.id }))"
          :error="form.errors.land_object_id"
        />
        <FormSelect
          v-model="form.deed_id"
          name="deed_id"
          label="Deed"
          placeholder="None"
          :options="deeds.map((d) => ({ label: d.deed_number || `Deed #${d.id}`, value: d.id }))"
          :error="form.errors.deed_id"
        />
        <FormSelect
          v-model="form.assigned_to"
          name="assigned_to"
          label="Assigned to"
          placeholder="Unassigned"
          :options="assignees.map((a) => ({ label: a.name, value: a.id }))"
          :error="form.errors.assigned_to"
        />
        <div class="space-y-1.5">
          <label class="text-sm font-medium text-ink-900">Notes</label>
          <textarea
            v-model="form.notes"
            rows="3"
            class="w-full rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm text-ink-900 shadow-sm focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/20"
          />
        </div>
        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <Link
            :href="route('legal.fieldVisits.index')"
            class="inline-flex items-center justify-center rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm font-semibold text-ink-900 shadow-sm transition hover:bg-surface-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            Cancel
          </Link>
          <PrimaryButton type="submit" :disabled="form.processing">Schedule</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
