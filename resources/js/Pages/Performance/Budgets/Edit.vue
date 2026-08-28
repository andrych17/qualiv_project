<!-- ponytail: Budget detail (§3B) — header/lines editable only while draft; submit/approve/lock walk
     the status ladder; an approved/locked budget offers "New version" instead of editing in place.
     A GL-sourced line's actual is read-only (never mistake it for a manually-entered figure, §3B). -->
<script setup lang="ts">
import { useForm, router, Link } from '@inertiajs/vue3'
import { reactive, watch } from 'vue'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormTextarea from '@/Components/forms/FormTextarea.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import FormRadioGroup from '@/Components/forms/FormRadioGroup.vue'
import FormNumberInput from '@/Components/forms/FormNumberInput.vue'
import BudgetLineListInput, { type BudgetLineRow } from '@/Components/performance/BudgetLineListInput.vue'
import PerformanceSubNav from '@/Components/performance/PerformanceSubNav.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import { formatNumber } from '@/Utils/formatters'
import { useConfirm } from '@/Composables/useConfirmDialog'

interface LineWithVariance extends BudgetLineRow {
  id: number
  period_label: string | null
  manual_actual_value: number | null
  variance: { actual_value: number; variance_pct: number | null; status: string; actual_source: 'gl' | 'manual' } | null
}

const props = defineProps<{
  periods: Array<{ id: number; label: string }>
  orgUnits: Array<{ id: number; name: string }>
  employees: Array<{ id: number; full_name: string; employee_no: string }>
  budget: {
    id: number
    name: string
    subject_type: 'company' | 'org_unit' | 'employee'
    subject_id: number | null
    fiscal_year: number
    fiscal_quarter: number | null
    owner_id: number | null
    notes: string | null
    status: 'draft' | 'submitted' | 'approved' | 'locked'
    version_no: number
    lines: LineWithVariance[]
  }
}>()

const isDraft = props.budget.status === 'draft'

const form = useForm({
  name: props.budget.name,
  subject_type: props.budget.subject_type,
  subject_id: props.budget.subject_id,
  fiscal_year: props.budget.fiscal_year,
  fiscal_quarter: props.budget.fiscal_quarter,
  owner_id: props.budget.owner_id,
  notes: props.budget.notes ?? '',
  lines: props.budget.lines.map((l): BudgetLineRow => ({ category: l.category, period_id: l.period_id, amount_planned: l.amount_planned, notes: l.notes })),
})

watch(() => form.subject_type, () => { form.subject_id = null })

const submit = () => form.put(route('performance.budgets.update', props.budget.id))

const { confirm } = useConfirm()

const confirmDelete = () => {
  confirm({
    title: `Delete "${props.budget.name}"?`,
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () => router.delete(route('performance.budgets.destroy', props.budget.id)),
  })
}

const doSubmit = () => confirm({
  title: 'Submit this budget?',
  description: 'It can no longer be edited directly once submitted — only approved, locked, or revised as a new version.',
  confirmText: 'Submit',
  onConfirm: () => router.patch(route('performance.budgets.submit', props.budget.id)),
})

const doApprove = () => confirm({
  title: 'Approve this budget?',
  confirmText: 'Approve',
  onConfirm: () => router.patch(route('performance.budgets.approve', props.budget.id)),
})

const doLock = () => confirm({
  title: 'Lock this budget?',
  description: 'A locked budget can only be revised by creating a new version.',
  confirmText: 'Lock',
  onConfirm: () => router.patch(route('performance.budgets.lock', props.budget.id)),
})

const doNewVersion = () => confirm({
  title: 'Create a new draft version?',
  description: 'Clones this budget\'s header and lines into a new editable draft. Actuals are not carried over.',
  confirmText: 'Create version',
  onConfirm: () => router.post(route('performance.budgets.newVersion', props.budget.id)),
})

const actualDrafts = reactive<Record<number, number | null>>(
  Object.fromEntries(props.budget.lines.map((l) => [l.id, l.manual_actual_value])),
)

const submitActual = (lineId: number) => {
  router.post(route('performance.budgetLines.actual.store', lineId), { actual_value: actualDrafts[lineId] }, { preserveScroll: true })
}
</script>

<template>
  <AppLayout>
    <PageHeader :title="budget.name" :description="`Version ${budget.version_no}`">
      <template #actions>
        <StatusBadge :status="budget.status" />
      </template>
    </PageHeader>

    <PerformanceSubNav active="budgets" class="mt-6" />

    <Panel class="mt-6 max-w-3xl">
      <form class="space-y-4" @submit.prevent="submit">
        <FormInput v-model="form.name" name="name" label="Name" :disabled="!isDraft" :error="form.errors.name" required />

        <FormRadioGroup
          v-model="form.subject_type"
          name="subject_type"
          label="Subject level"
          inline
          :disabled="!isDraft"
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
          :disabled="!isDraft"
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
          :disabled="!isDraft"
          :error="form.errors.subject_id"
          required
        />

        <div class="grid grid-cols-2 gap-4">
          <FormInput v-model="form.fiscal_year" name="fiscal_year" type="number" label="Fiscal year" :disabled="!isDraft" :error="form.errors.fiscal_year" required />
          <FormSelect
            v-model="form.fiscal_quarter"
            name="fiscal_quarter"
            label="Quarter (optional)"
            placeholder="Whole year"
            :options="[1, 2, 3, 4].map((q) => ({ label: `Q${q}`, value: q }))"
            :disabled="!isDraft"
            :error="form.errors.fiscal_quarter"
          />
        </div>

        <FormTextarea v-model="form.notes" name="notes" label="Notes" :disabled="!isDraft" :error="form.errors.notes" />

        <BudgetLineListInput v-if="isDraft" v-model="form.lines" :periods="periods" />
        <p v-if="form.errors.lines" class="text-sm text-signal-danger">{{ form.errors.lines }}</p>

        <div v-if="isDraft" class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <Link
            :href="route('performance.budgets.index')"
            class="inline-flex items-center justify-center rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm font-semibold text-ink-900 shadow-sm transition hover:bg-surface-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            Cancel
          </Link>
          <SecondaryButton type="button" @click="confirmDelete">Delete</SecondaryButton>
          <PrimaryButton type="submit" :disabled="form.processing">Save</PrimaryButton>
        </div>
      </form>
    </Panel>

    <Panel v-if="!isDraft" class="mt-6 max-w-4xl" title="Lines &amp; actuals">
      <div class="space-y-3">
        <div
          v-for="line in budget.lines"
          :key="line.id"
          class="rounded-md border border-border p-3"
        >
          <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
              <p class="text-sm font-semibold text-ink-900">{{ line.category }}</p>
              <p class="text-xs text-ink-600">{{ line.period_label }} · Planned {{ formatNumber(line.amount_planned ?? 0) }}</p>
            </div>
            <StatusBadge v-if="line.variance" :status="line.variance.status" />
            <span v-else class="text-xs text-ink-600">No actual recorded yet</span>
          </div>

          <div class="mt-3 flex flex-wrap items-end gap-3">
            <div v-if="line.variance?.actual_source === 'gl'" class="text-sm">
              <p class="text-xs font-medium uppercase tracking-wide text-ink-600">Actual (GL-sourced)</p>
              <p class="font-medium text-ink-900">{{ formatNumber(line.variance.actual_value) }}</p>
            </div>
            <template v-else>
              <FormNumberInput v-model="actualDrafts[line.id]" :name="`actual-${line.id}`" label="Actual (manual)" class="w-48" />
              <SecondaryButton type="button" @click="submitActual(line.id)">Save actual</SecondaryButton>
            </template>
          </div>
        </div>

        <p v-if="budget.lines.length === 0" class="text-sm text-ink-600">No lines on this budget.</p>
      </div>
    </Panel>

    <div class="mt-6 flex max-w-3xl items-center justify-end gap-3">
      <PrimaryButton v-if="budget.status === 'draft'" type="button" @click="doSubmit">Submit</PrimaryButton>
      <PrimaryButton v-if="budget.status === 'submitted'" type="button" @click="doApprove">Approve</PrimaryButton>
      <PrimaryButton v-if="budget.status === 'approved'" type="button" @click="doLock">Lock</PrimaryButton>
      <SecondaryButton v-if="budget.status === 'approved' || budget.status === 'locked'" type="button" @click="doNewVersion">
        New version
      </SecondaryButton>
    </div>
  </AppLayout>
</template>
