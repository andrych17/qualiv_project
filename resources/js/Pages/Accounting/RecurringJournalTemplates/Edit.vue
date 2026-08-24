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
import FormSearchableSelect from '@/Components/forms/FormSearchableSelect.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import { Plus, Trash2 } from 'lucide-vue-next'

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
  lines: props.template.lines.map((l) => ({ ...l, debit: String(l.debit), credit: String(l.credit) })),
})

const currencyOptions = props.currencies.map((c) => ({ value: c.code, label: `${c.code} — ${c.name}` }))

const addLine = () => form.lines.push({ account_id: null, cost_center_id: null, debit: '', credit: '', description: '' })
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

const destroy = () => {
  if (confirm(`Delete recurring template "${props.template.name}"? This does not affect journals already generated.`)) {
    router.delete(route('accounting.recurring-journal-templates.destroy', props.template.id))
  }
}
</script>

<template>
  <AppLayout>
    <PageHeader :title="template.name" description="Editing the rule or anchor date recomputes the next occurrence from scratch.">
      <template #actions>
        <button type="button" class="mr-4 text-sm font-medium text-accent hover:underline" @click="toggleActive">{{ template.is_active ? 'Pause' : 'Resume' }}</button>
        <button type="button" class="mr-4 text-sm font-medium text-signal-danger hover:underline" @click="destroy">Delete</button>
        <Link :href="route('accounting.recurring-journal-templates.index', { company_id: template.company_id })" class="text-sm font-medium text-accent hover:underline">← Templates</Link>
      </template>
    </PageHeader>

    <div class="mt-6 grid grid-cols-1 gap-4 lg:grid-cols-3">
      <Panel class="p-4">
        <div class="text-xs uppercase text-ink-600">Status</div>
        <div class="mt-1"><StatusBadge :status="template.is_active ? 'active' : 'paused'" /></div>
      </Panel>
      <Panel class="p-4">
        <div class="text-xs uppercase text-ink-600">Last run</div>
        <div class="mt-1 text-sm text-ink-900">{{ template.last_run_date ?? 'Never' }}</div>
      </Panel>
      <Panel class="p-4">
        <div class="text-xs uppercase text-ink-600">Next run</div>
        <div class="mt-1 text-sm text-ink-900">{{ template.next_run_date ?? 'Exhausted — rule has no further occurrences' }}</div>
      </Panel>
    </div>

    <Panel v-if="upcomingRunDates.length" class="mt-4 p-4">
      <div class="text-xs uppercase text-ink-600">Upcoming occurrences (preview)</div>
      <div class="mt-2 flex flex-wrap gap-2">
        <span v-for="d in upcomingRunDates" :key="d" class="rounded-full bg-surface-50 px-2.5 py-0.5 text-xs text-ink-700">{{ d }}</span>
      </div>
    </Panel>

    <Panel class="mt-4">
      <form class="space-y-6" @submit.prevent="submit">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <FormInput v-model="form.name" name="name" label="Template name" :error="form.errors.name" required />
          <FormSearchableSelect v-model="form.currency_code" name="currency_code" label="Currency" :options="currencyOptions" :error="form.errors.currency_code" required />
          <FormInput v-model="form.anchor_date" name="anchor_date" type="date" label="First occurrence" :error="form.errors.anchor_date" required />
          <FormInput
            v-model="form.recurrence_rule"
            name="recurrence_rule"
            label="Recurrence rule"
            placeholder="e.g. FREQ=MONTHLY;BYMONTHDAY=1"
            :error="form.errors.recurrence_rule"
            required
          />
        </div>

        <FormInput v-model="form.memo" name="memo" label="Memo (used on every generated journal)" :error="form.errors.memo" />

        <div>
          <div class="mb-2 flex items-center justify-between">
            <h3 class="text-sm font-semibold text-ink-900">Lines</h3>
            <button type="button" class="inline-flex items-center gap-1 text-sm font-medium text-accent hover:underline" @click="addLine">
              <Plus class="h-4 w-4" /> Add line
            </button>
          </div>

          <div class="overflow-x-auto rounded-sm border border-border">
            <table class="w-full text-sm">
              <thead>
                <tr class="border-b border-border bg-surface-50 text-left text-xs uppercase text-ink-600">
                  <th class="w-2/5 px-3 py-2">Account</th>
                  <th class="px-3 py-2">Cost center</th>
                  <th class="px-3 py-2 text-right">Debit</th>
                  <th class="px-3 py-2 text-right">Credit</th>
                  <th class="px-3 py-2">Description</th>
                  <th class="px-3 py-2"></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(line, i) in form.lines" :key="i" class="border-b border-border last:border-b-0">
                  <td class="px-3 py-2">
                    <FormSearchableSelect v-model="line.account_id" :name="`lines.${i}.account_id`" :options="accounts" :error="(form.errors as any)[`lines.${i}.account_id`]" />
                    <p v-if="isControlAccount(line.account_id)" class="mt-1 text-xs text-signal-danger">Control account — will be rejected on post.</p>
                  </td>
                  <td class="px-3 py-2">
                    <FormSearchableSelect v-model="line.cost_center_id" :name="`lines.${i}.cost_center_id`" placeholder="None" :options="costCenters" />
                  </td>
                  <td class="px-3 py-2">
                    <input v-model="line.debit" type="number" step="0.01" min="0" class="w-28 rounded-sm border border-border bg-surface-0 px-2 py-1.5 text-right text-sm" />
                  </td>
                  <td class="px-3 py-2">
                    <input v-model="line.credit" type="number" step="0.01" min="0" class="w-28 rounded-sm border border-border bg-surface-0 px-2 py-1.5 text-right text-sm" />
                  </td>
                  <td class="px-3 py-2">
                    <input v-model="line.description" type="text" class="w-full rounded-sm border border-border bg-surface-0 px-2 py-1.5 text-sm" />
                  </td>
                  <td class="px-3 py-2 text-right">
                    <button type="button" class="text-ink-600 hover:text-signal-danger" :disabled="form.lines.length <= 1" @click="removeLine(i)">
                      <Trash2 class="h-4 w-4" />
                    </button>
                  </td>
                </tr>
              </tbody>
              <tfoot>
                <tr class="border-t border-border bg-surface-50 font-semibold">
                  <td class="px-3 py-2" colspan="2">Total</td>
                  <td class="px-3 py-2 text-right">{{ totalDebit.toFixed(2) }}</td>
                  <td class="px-3 py-2 text-right">{{ totalCredit.toFixed(2) }}</td>
                  <td class="px-3 py-2" colspan="2">
                    <span :class="isBalanced ? 'text-signal-success' : 'text-signal-danger'">{{ isBalanced ? 'Balanced' : 'Not balanced' }}</span>
                  </td>
                </tr>
              </tfoot>
            </table>
          </div>
          <p v-if="(form.errors as any).lines" class="mt-2 text-sm text-signal-danger">{{ (form.errors as any).lines }}</p>
        </div>

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <PrimaryButton type="submit" :disabled="form.processing">Save changes</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
