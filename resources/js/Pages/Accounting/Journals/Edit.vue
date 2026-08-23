<!-- ponytail: Accounting §3C manual journal entry — edit form, only reachable while draft (JournalService enforces). -->
<script setup lang="ts">
import { computed } from 'vue'
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSearchableSelect from '@/Components/forms/FormSearchableSelect.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import { Plus, Trash2 } from 'lucide-vue-next'

type AccountOption = { value: number; label: string; is_control_account: boolean }

interface LineData {
  account_id: number | null
  cost_center_id: number | null
  debit: number
  credit: number
  description: string | null
}

const props = defineProps<{
  journal: {
    id: number
    company_id: number
    fiscal_period_id: number
    journal_date: string
    currency_code: string
    memo: string | null
    lines: LineData[]
  }
  accounts: AccountOption[]
  costCenters: Array<{ value: number; label: string }>
  fiscalPeriods: Array<{ value: number; label: string }>
  currencies: Array<{ code: string; name: string }>
}>()

const blankLine = () => ({ account_id: null as number | null, cost_center_id: null as number | null, debit: '' as number | string, credit: '' as number | string, description: '' })

const form = useForm({
  fiscal_period_id: props.journal.fiscal_period_id,
  journal_date: props.journal.journal_date,
  currency_code: props.journal.currency_code,
  memo: props.journal.memo ?? '',
  lines: props.journal.lines.length
    ? props.journal.lines.map((l) => ({ ...l, debit: l.debit || '', credit: l.credit || '', description: l.description ?? '' }))
    : [blankLine(), blankLine()],
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
})).put(route('accounting.journals.update', props.journal.id))
</script>

<template>
  <AppLayout>
    <PageHeader title="Edit journal" description="Still a draft — balance and control-account rules are enforced when you post it." />

    <Panel class="mt-6">
      <form class="space-y-6" @submit.prevent="submit">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <FormSearchableSelect v-model="form.fiscal_period_id" name="fiscal_period_id" label="Fiscal period" :options="fiscalPeriods" :error="form.errors.fiscal_period_id" required />
          <FormInput v-model="form.journal_date" name="journal_date" type="date" label="Journal date" :error="form.errors.journal_date" required />
          <FormSearchableSelect v-model="form.currency_code" name="currency_code" label="Currency" :options="currencyOptions" :error="form.errors.currency_code" required />
        </div>

        <FormInput v-model="form.memo" name="memo" label="Memo" :error="form.errors.memo" />

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
          <Link
            :href="route('accounting.journals.show', journal.id)"
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
