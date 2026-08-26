<!-- ponytail: Accounting §3P recurring journal template edit — same shape as Create.vue, plus
     an upcoming-occurrences preview (pure read from RecurrenceService, nothing generated here)
     and pause/resume/delete row actions. -->
<script setup lang="ts">
import { computed } from 'vue'
import { useForm, router, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormCurrencyInput from '@/Components/forms/FormCurrencyInput.vue'
import FormSearchableSelect from '@/Components/forms/FormSearchableSelect.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import DangerButton from '@/Components/DangerButton.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import { Plus, Trash2 } from 'lucide-vue-next'
import { useConfirm } from '@/Composables/useConfirmDialog'
import { formatCurrency, formatDate } from '@/Utils/formatters'

type AccountOption = { value: number; label: string; is_control_account: boolean }
type TemplateLine = { account_id: number | null; cost_center_id: number | null; debit: number; credit: number; description: string | null }

const props = defineProps<{
  template: {
    id: number; company_id: number; name: string; memo: string | null; currency_code: string
    recurrence_rule: string; anchor_date: string; next_run_date: string | null; last_run_date: string | null
    is_active: boolean; lines: TemplateLine[]
  }
  upcomingRunDates: string[]
  accounts: AccountOption[]
  costCenters: Array<{ value: number; label: string }>
  currencies: Array<{ code: string; name: string }>
}>()

const form = useForm({
  name: props.template.name,
  memo: props.template.memo ?? '',
  currency_code: props.template.currency_code,
  recurrence_rule: props.template.recurrence_rule,
  anchor_date: props.template.anchor_date,
  lines: props.template.lines.map((l) => ({ ...l, debit: Number(l.debit) || null, credit: Number(l.credit) || null })),
})

const currencyOptions = props.currencies.map((c) => ({ value: c.code, label: `${c.code} — ${c.name}` }))

const addLine = () => form.lines.push({ account_id: null, cost_center_id: null, debit: null, credit: null, description: '' })
const removeLine = (i: number) => form.lines.splice(i, 1)

const isControlAccount = (accountId: number | null) => props.accounts.find((a) => a.value === accountId)?.is_control_account ?? false

const totalDebit = computed(() => form.lines.reduce((sum, l) => sum + (Number(l.debit) || 0), 0))
const totalCredit = computed(() => form.lines.reduce((sum, l) => sum + (Number(l.credit) || 0), 0))
const isBalanced = computed(() => form.lines.length > 0 && Math.abs(totalDebit.value - totalCredit.value) < 0.005)

const submit = () => form.transform((data) => ({
  ...data,
  lines: data.lines.map((l) => ({ ...l, debit: Number(l.debit) || 0, credit: Number(l.credit) || 0 })),
})).put(route('accounting.recurring-journal-templates.update', props.template.id))

const toggleActive = () => router.post(route('accounting.recurring-journal-templates.set-active', props.template.id), { is_active: !props.template.is_active }, { preserveScroll: true })

const { confirm } = useConfirm()

const destroy = () => {
  confirm({
    title: 'Delete Recurring Template?',
    description: `Delete recurring template "${props.template.name}"? This does not affect journals already generated.`,
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () => router.delete(route('accounting.recurring-journal-templates.destroy', props.template.id)),
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader :title="template.name" description="Editing the rule or anchor date recomputes the next occurrence from scratch.">
      <template #actions>
        <div class="flex items-center gap-2">
          <SecondaryButton type="button" @click="toggleActive">
            {{ template.is_active ? 'Pause Template' : 'Resume Template' }}
          </SecondaryButton>
          <DangerButton type="button" @click="destroy">
            Delete
          </DangerButton>
          <SecondaryButton :href="route('accounting.recurring-journal-templates.index', { company_id: template.company_id })">
            &larr; Templates
          </SecondaryButton>
        </div>
      </template>
    </PageHeader>

    <div class="mt-6 grid grid-cols-1 gap-4 lg:grid-cols-3">
      <Panel class="p-4">
        <div class="text-xs font-semibold uppercase text-ink-600">Status</div>
        <div class="mt-2"><StatusBadge :status="template.is_active ? 'active' : 'paused'" /></div>
      </Panel>
      <Panel class="p-4">
        <div class="text-xs font-semibold uppercase text-ink-600">Last Run</div>
        <div class="mt-2 text-sm font-medium text-ink-900">{{ template.last_run_date ? formatDate(template.last_run_date) : 'Never' }}</div>
      </Panel>
      <Panel class="p-4">
        <div class="text-xs font-semibold uppercase text-ink-600">Next Run</div>
        <div class="mt-2 text-sm font-medium text-ink-900">{{ template.next_run_date ? formatDate(template.next_run_date) : 'Exhausted' }}</div>
      </Panel>
    </div>

    <Panel v-if="upcomingRunDates.length" class="mt-4 p-4">
      <div class="text-xs font-semibold uppercase text-ink-600">Upcoming Occurrences (Preview)</div>
      <div class="mt-2 flex flex-wrap gap-2">
        <span v-for="d in upcomingRunDates" :key="d" class="rounded-md border border-border bg-surface-50 px-2.5 py-1 text-xs font-mono text-ink-700">
          {{ formatDate(d) }}
        </span>
      </div>
    </Panel>

    <Panel class="mt-6">
      <form class="space-y-6" @submit.prevent="submit">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <FormInput v-model="form.name" name="name" label="Template Name" :error="form.errors.name" required />
          <FormSearchableSelect v-model="form.currency_code" name="currency_code" label="Currency" :options="currencyOptions" :error="form.errors.currency_code" required />
          <FormInput v-model="form.anchor_date" name="anchor_date" type="date" label="First Occurrence" :error="form.errors.anchor_date" required />
          <FormInput
            v-model="form.recurrence_rule"
            name="recurrence_rule"
            label="Recurrence Rule (iCal RRule)"
            placeholder="e.g. FREQ=MONTHLY;BYMONTHDAY=1"
            :error="form.errors.recurrence_rule"
            required
          />
        </div>

        <FormInput v-model="form.memo" name="memo" label="Memo (Used on Generated Journals)" :error="form.errors.memo" />

        <div>
          <div class="mb-3 flex items-center justify-between">
            <h3 class="text-sm font-semibold text-ink-900">Journal Lines</h3>
            <button type="button" class="inline-flex items-center gap-1 text-xs font-semibold text-accent hover:underline" @click="addLine">
              <Plus class="h-4 w-4" /> Add Line
            </button>
          </div>

          <div class="overflow-x-auto rounded-lg border border-border">
            <table class="w-full text-sm">
              <thead>
                <tr class="border-b border-border bg-surface-50 text-left text-xs uppercase tracking-wider text-ink-600 font-semibold">
                  <th class="w-2/5 px-3 py-2.5">Account</th>
                  <th class="px-3 py-2.5">Cost Center</th>
                  <th class="px-3 py-2.5 text-right">Debit</th>
                  <th class="px-3 py-2.5 text-right">Credit</th>
                  <th class="px-3 py-2.5">Line Description</th>
                  <th class="px-3 py-2.5"></th>
                </tr>
              </thead>
              <tbody class="divide-y divide-border bg-surface">
                <tr v-for="(line, i) in form.lines" :key="i" class="align-top hover:bg-surface-50/50 transition-colors">
                  <td class="px-3 py-2">
                    <FormSearchableSelect v-model="line.account_id" :name="`lines.${i}.account_id`" :options="accounts" :error="(form.errors as any)[`lines.${i}.account_id`]" />
                    <p v-if="isControlAccount(line.account_id)" class="mt-1 text-xs text-signal-danger">Control account — will be rejected on post.</p>
                  </td>
                  <td class="px-3 py-2">
                    <FormSearchableSelect v-model="line.cost_center_id" :name="`lines.${i}.cost_center_id`" placeholder="None" :options="costCenters" />
                  </td>
                  <td class="px-3 py-2">
                    <FormCurrencyInput v-model="line.debit" :name="`lines.${i}.debit`" prefix="" :decimals="2" class="w-32" />
                  </td>
                  <td class="px-3 py-2">
                    <FormCurrencyInput v-model="line.credit" :name="`lines.${i}.credit`" prefix="" :decimals="2" class="w-32" />
                  </td>
                  <td class="px-3 py-2">
                    <input v-model="line.description" type="text" placeholder="Line note" class="w-full rounded-md border border-border bg-surface-0 px-2.5 py-1.5 text-sm focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent" />
                  </td>
                  <td class="px-3 py-2 text-right pt-3">
                    <button type="button" class="text-ink-400 hover:text-signal-danger transition-colors" :disabled="form.lines.length <= 1" @click="removeLine(i)">
                      <Trash2 class="h-4 w-4" />
                    </button>
                  </td>
                </tr>
              </tbody>
              <tfoot>
                <tr class="border-t-2 border-border bg-surface-100/75 font-semibold text-xs">
                  <td class="px-4 py-3 text-ink-900" colspan="2">Total</td>
                  <td class="px-4 py-3 text-right font-mono text-xs font-bold text-ink-900">{{ formatCurrency(totalDebit, form.currency_code) }}</td>
                  <td class="px-4 py-3 text-right font-mono text-xs font-bold text-ink-900">{{ formatCurrency(totalCredit, form.currency_code) }}</td>
                  <td class="px-4 py-3 font-semibold" :class="isBalanced ? 'text-signal-success' : 'text-signal-danger'" colspan="2">
                    {{ isBalanced ? '✓ Balanced' : '⚠ Out of Balance' }}
                  </td>
                </tr>
              </tfoot>
            </table>
          </div>
          <p v-if="(form.errors as any).lines" class="mt-2 text-sm text-signal-danger">{{ (form.errors as any).lines }}</p>
        </div>

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <PrimaryButton type="submit" :disabled="form.processing">Save Changes</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
