<!-- ponytail: New Objective (§3E) -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import { watch } from 'vue'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormTextarea from '@/Components/forms/FormTextarea.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import FormRadioGroup from '@/Components/forms/FormRadioGroup.vue'
import KeyResultListInput, { type KeyResultRow } from '@/Components/performance/KeyResultListInput.vue'
import PerformanceSubNav from '@/Components/performance/PerformanceSubNav.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'

const props = defineProps<{
  cycles: Array<{ id: number; label: string }>
  orgUnits: Array<{ id: number; name: string }>
  employees: Array<{ id: number; full_name: string; employee_no: string }>
  parentOptions: Array<{ id: number; objective_text: string }>
}>()

const form = useForm({
  cycle_id: null as number | null,
  subject_type: 'company' as 'company' | 'org_unit' | 'employee',
  subject_id: null as number | null,
  objective_text: '',
  parent_okr_id: null as number | null,
  key_results: [] as KeyResultRow[],
})

watch(() => form.subject_type, () => { form.subject_id = null })

const submit = () => form.post(route('performance.okrObjectives.store'))
</script>

<template>
  <AppLayout>
    <PageHeader title="New Objective" />

    <PerformanceSubNav active="okrObjectives" class="mt-6" />

    <Panel class="mt-6 max-w-3xl">
      <form class="space-y-4" @submit.prevent="submit">
        <FormSelect
          v-model="form.cycle_id"
          name="cycle_id"
          label="Cycle"
          placeholder="Select a cycle…"
          :options="cycles.map((c) => ({ label: c.label, value: c.id }))"
          :error="form.errors.cycle_id"
          required
        />

        <FormTextarea v-model="form.objective_text" name="objective_text" label="Objective" placeholder="e.g. Delight our customers" :error="form.errors.objective_text" required />

        <FormRadioGroup
          v-model="form.subject_type"
          name="subject_type"
          label="Owner level"
          inline
          :options="[
            { label: 'Company', value: 'company' },
            { label: 'Org Unit', value: 'org_unit' },
            { label: 'Employee', value: 'employee' },
          ]"
        />

        <FormSelect
          v-if="form.subject_type === 'org_unit'"
          v-model="form.subject_id"
          name="subject_id"
          label="Org Unit"
          placeholder="Select an org unit…"
          :options="orgUnits.map((o) => ({ label: o.name, value: o.id }))"
          :error="form.errors.subject_id"
          required
        />
        <FormSelect
          v-else-if="form.subject_type === 'employee'"
          v-model="form.subject_id"
          name="subject_id"
          label="Employee"
          placeholder="Select an employee…"
          :options="employees.map((e) => ({ label: `${e.employee_no} — ${e.full_name}`, value: e.id }))"
          :error="form.errors.subject_id"
          required
        />

        <FormSelect
          v-model="form.parent_okr_id"
          name="parent_okr_id"
          label="Aligned to (optional parent objective)"
          placeholder="None — top-level objective"
          :options="parentOptions.map((p) => ({ label: p.objective_text, value: p.id }))"
          :error="form.errors.parent_okr_id"
        />

        <KeyResultListInput v-model="form.key_results" />
        <p v-if="form.errors.key_results" class="text-sm text-signal-danger">{{ form.errors.key_results }}</p>

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <Link
            :href="route('performance.okrObjectives.index')"
            class="inline-flex items-center justify-center rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm font-semibold text-ink-900 shadow-sm transition hover:bg-surface-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            Cancel
          </Link>
          <PrimaryButton type="submit" :disabled="form.processing">Save objective</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
