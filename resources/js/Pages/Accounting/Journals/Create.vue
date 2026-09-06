<!-- ponytail: Accounting §3C manual journal entry — header + N balanced lines. Balance is
     checked server-side at post() time (a draft may be transiently unbalanced), but the
     running total is shown live so a user doesn't have to submit to find out. -->
<script setup lang="ts">
import { computed } from 'vue'
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormCurrencyInput from '@/Components/forms/FormCurrencyInput.vue'
import FormSearchableSelect from '@/Components/forms/FormSearchableSelect.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import { formatCurrency } from '@/Utils/formatters'
import { Plus, Trash2 } from 'lucide-vue-next'

type AccountOption = { value: number; label: string; is_control_account: boolean }

const props = defineProps<{
  companies: Array<{ id: number; legal_name: string }>
  selectedCompanyId: number | null
  accounts: AccountOption[]
  costCenters: Array<{ value: number; label: string }>
  fiscalPeriods: Array<{ value: number; label: string }>
  currencies: Array<{ code: string; name: string }>
}>()

const blankLine = () => ({ account_id: null as number | null, cost_center_id: null as number | null, debit: null as number | null, credit: null as number | null, description: '' })

const form = useForm({
  company_id: props.selectedCompanyId,
  fiscal_period_id: props.fiscalPeriods[0]?.value ?? null,
  journal_date: new Date().toISOString().slice(0, 10),
  currency_code: props.currencies[0]?.code ?? 'IDR',
  memo: '',
  lines: [blankLine(), blankLine()],
})

const currencyOptions = props.currencies.map((c) => ({ value: c.code, label: `${c.code} — ${c.name}` }))

const addLine = () => form.lines.push(blankLine())
const removeLine = (i: number) => form.lines.splice(i, 1)

const isControlAccount = (accountId: number | null) => props.accounts.find((a) => a.value === accountId)?.is_control_account ?? false

const totalDebit = computed(() => form.lines.reduce((sum, l) => sum + (Number(l.debit) || 0), 0))
const totalCredit = computed(() => form.lines.reduce((sum, l) => sum + (Number(l.credit) || 0), 0))
const isBalanced = computed(() => form.lines.length > 0 && Math.abs(totalDebit.value - totalCredit.value) < 0.005)

const submit = () => form.transform((data) => ({
  ...data,
  lines: data.lines.map((l) => ({ ...l, debit: Number(l.debit) || 0, credit: Number(l.credit) || 0 })),
})).post(route('accounting.journals.store'))
</script>

<template>
  <AppLayout>
    <PageHeader title="New General Journal" description="Saved as draft — balance and control-account validation rules are enforced when you post it." />

    <Panel class="mt-6">
      <form class="space-y-6" @submit.prevent="submit">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <FormSearchableSelect
            v-model="form.company_id"
            name="company_id"
            label="Company"
            :options="companies.map((c) => ({ value: c.id, label: c.legal_name }))"
            :error="form.errors.company_id"
            required
          />
          <FormSearchableSelect v-model="form.fiscal_period_id" name="fiscal_period_id" label="Fiscal Period" :options="fiscalPeriods" :error="form.errors.fiscal_period_id" required />
          <FormInput v-model="form.journal_date" name="journal_date" type="date" label="Journal Date" :error="form.errors.journal_date" required />
          <FormSearchableSelect v-model="form.currency_code" name="currency_code" label="Currency" :options="currencyOptions" :error="form.errors.currency_code" required />
        </div>

        <FormInput v-model="form.memo" name="memo" label="Memo / Reference Note" placeholder="e.g. Monthly Accrual Adjustments" :error="form.errors.memo" />

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
                  <th class="w-2/5 min-w-[260px] px-3 py-2.5">Account</th>
                  <th class="min-w-[160px] px-3 py-2.5">Cost Center</th>
                  <th class="px-3 py-2.5 text-right">Debit</th>
                  <th class="px-3 py-2.5 text-right">Credit</th>
                  <th class="min-w-[180px] px-3 py-2.5">Line Description</th>
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
          <SecondaryButton :href="route('accounting.journals.index', { company_id: form.company_id })">
            Cancel
          </SecondaryButton>
          <PrimaryButton type="submit" :disabled="form.processing">Save Draft</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
