<!-- ponytail: Accounting §3G asset detail — commercial and fiscal schedules side by side, so the SPT reconciliation gap between them is visible at a glance. -->
<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import { formatCurrency, formatDate } from '@/Utils/formatters'

type ScheduleRow = { period_no: number; period_end: string; depreciation_amount: number; accumulated_depreciation: number; net_book_value: number; journal_id?: number }

const props = defineProps<{
  asset: {
    id: number
    company_id: number
    asset_no: string
    name: string
    status: string
    asset_group_name: string
    vendor_name: string | null
    acquisition_date: string
    acquisition_cost: number
    asset_gl_account_label: string
    accumulated_depreciation_gl_account_label: string
    depreciation_expense_gl_account_label: string
    commercial_method: string
    commercial_useful_life_months: number
    commercial_declining_rate: number | null
    fiscal_method: string
  }
  commercialSchedule: ScheduleRow[]
  fiscalSchedule: ScheduleRow[]
  disposal: { disposal_date: string; proceeds: number; commercial_nbv_at_disposal: number; gain_loss_amount: number; journal_id: number } | null
}>()

const commercialNbv = props.commercialSchedule.length ? props.commercialSchedule[props.commercialSchedule.length - 1].net_book_value : props.asset.acquisition_cost
const fiscalNbv = props.fiscalSchedule.length ? props.fiscalSchedule[props.fiscalSchedule.length - 1].net_book_value : props.asset.acquisition_cost
</script>

<template>
  <AppLayout>
    <PageHeader :title="`${asset.asset_no} — ${asset.name}`" :description="`${asset.asset_group_name} — Acquired ${formatDate(asset.acquisition_date)} — Cost ${formatCurrency(asset.acquisition_cost)}`">
      <template #actions>
        <div class="flex items-center gap-2">
          <SecondaryButton :href="route('accounting.fixed-assets.index', { company_id: asset.company_id })">
            &larr; Back to Assets
          </SecondaryButton>
          <PrimaryButton v-if="asset.status === 'active'" :href="route('accounting.fixed-assets.dispose.create', asset.id)">
            Dispose Asset
          </PrimaryButton>
        </div>
      </template>
    </PageHeader>

    <div class="mt-6 grid grid-cols-2 gap-4 sm:grid-cols-4">
      <Panel class="p-4">
        <div class="text-xs font-semibold uppercase text-ink-600">Status</div>
        <div class="mt-2"><StatusBadge :status="asset.status" /></div>
      </Panel>
      <Panel class="p-4">
        <div class="text-xs font-semibold uppercase text-ink-600">Commercial NBV</div>
        <div class="mt-2 font-mono text-xl font-bold text-ink-900">{{ formatCurrency(commercialNbv) }}</div>
        <div class="mt-1 text-xs text-ink-600 font-medium">{{ asset.commercial_method === 'declining_balance' ? `Declining ${((asset.commercial_declining_rate ?? 0) * 100).toFixed(2)}%` : 'Straight-line' }} — {{ asset.commercial_useful_life_months }} mo</div>
      </Panel>
      <Panel class="p-4">
        <div class="text-xs font-semibold uppercase text-ink-600">Fiscal NBV</div>
        <div class="mt-2 font-mono text-xl font-bold text-ink-900">{{ formatCurrency(fiscalNbv) }}</div>
        <div class="mt-1 text-xs text-ink-600 font-medium">{{ asset.fiscal_method === 'declining_balance' ? 'Declining-balance' : 'Straight-line' }} (Tax rate)</div>
      </Panel>
      <Panel class="p-4">
        <div class="text-xs font-semibold uppercase text-ink-600">Vendor</div>
        <div class="mt-2 text-sm font-medium text-ink-900">{{ asset.vendor_name ?? '—' }}</div>
      </Panel>
    </div>

    <Panel v-if="disposal" class="mt-6 p-4">
      <div class="text-sm font-semibold text-ink-900">Disposal Record</div>
      <dl class="mt-3 grid grid-cols-2 gap-4 text-sm sm:grid-cols-4">
        <div><dt class="text-ink-600">Date</dt><dd class="font-mono font-medium text-ink-900">{{ formatDate(disposal.disposal_date) }}</dd></div>
        <div><dt class="text-ink-600">Proceeds</dt><dd class="font-mono font-medium text-ink-900">{{ formatCurrency(disposal.proceeds) }}</dd></div>
        <div><dt class="text-ink-600">NBV at Disposal</dt><dd class="font-mono font-medium text-ink-900">{{ formatCurrency(disposal.commercial_nbv_at_disposal) }}</dd></div>
        <div>
          <dt class="text-ink-600">Gain / (Loss)</dt>
          <dd class="font-mono font-bold" :class="disposal.gain_loss_amount >= 0 ? 'text-signal-success' : 'text-signal-danger'">
            {{ formatCurrency(disposal.gain_loss_amount) }}
          </dd>
        </div>
      </dl>
      <div class="mt-3 border-t border-border pt-2">
        <Link :href="route('accounting.journals.show', disposal.journal_id)" class="text-xs font-semibold text-accent hover:underline">
          View Disposal Journal &rarr;
        </Link>
      </div>
    </Panel>

    <Panel class="mt-6 p-4">
      <dl class="grid grid-cols-1 gap-4 text-sm sm:grid-cols-3">
        <div><dt class="text-ink-600 font-medium">Fixed Asset Account</dt><dd class="font-mono text-xs text-ink-900 mt-1">{{ asset.asset_gl_account_label }}</dd></div>
        <div><dt class="text-ink-600 font-medium">Accumulated Depreciation</dt><dd class="font-mono text-xs text-ink-900 mt-1">{{ asset.accumulated_depreciation_gl_account_label }}</dd></div>
        <div><dt class="text-ink-600 font-medium">Depreciation Expense</dt><dd class="font-mono text-xs text-ink-900 mt-1">{{ asset.depreciation_expense_gl_account_label }}</dd></div>
      </dl>
    </Panel>

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
      <Panel title="Commercial Schedule (Posted to GL)">
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead>
              <tr class="border-b border-border bg-surface-50 text-left text-xs uppercase tracking-wider text-ink-600 font-semibold">
                <th class="py-3 px-4">Period</th>
                <th class="py-3 px-4 text-right">Depreciation</th>
                <th class="py-3 px-4 text-right">Accumulated</th>
                <th class="py-3 px-4 text-right">NBV</th>
                <th class="py-3 px-4 text-right">Journal</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-border bg-surface">
              <tr v-for="r in commercialSchedule" :key="r.period_no" class="hover:bg-surface-50/75 transition-colors">
                <td class="py-3 px-4 font-mono text-xs text-ink-700">{{ formatDate(r.period_end) }}</td>
                <td class="py-3 px-4 text-right font-mono text-xs text-ink-900">{{ formatCurrency(r.depreciation_amount) }}</td>
                <td class="py-3 px-4 text-right font-mono text-xs text-ink-700">{{ formatCurrency(r.accumulated_depreciation) }}</td>
                <td class="py-3 px-4 text-right font-mono text-xs font-semibold text-ink-900">{{ formatCurrency(r.net_book_value) }}</td>
                <td class="py-3 px-4 text-right">
                  <Link v-if="r.journal_id" :href="route('accounting.journals.show', r.journal_id)" class="text-xs font-semibold text-accent hover:underline">
                    #{{ r.journal_id }}
                  </Link>
                </td>
              </tr>
              <tr v-if="!commercialSchedule.length"><td colspan="5" class="py-6 text-center text-ink-500">No depreciation periods run yet.</td></tr>
            </tbody>
          </table>
        </div>
      </Panel>

      <Panel title="Fiscal Schedule (SPT Reconciliation Only)">
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead>
              <tr class="border-b border-border bg-surface-50 text-left text-xs uppercase tracking-wider text-ink-600 font-semibold">
                <th class="py-3 px-4">Period</th>
                <th class="py-3 px-4 text-right">Depreciation</th>
                <th class="py-3 px-4 text-right">Accumulated</th>
                <th class="py-3 px-4 text-right">NBV</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-border bg-surface">
              <tr v-for="r in fiscalSchedule" :key="r.period_no" class="hover:bg-surface-50/75 transition-colors">
                <td class="py-3 px-4 font-mono text-xs text-ink-700">{{ formatDate(r.period_end) }}</td>
                <td class="py-3 px-4 text-right font-mono text-xs text-ink-900">{{ formatCurrency(r.depreciation_amount) }}</td>
                <td class="py-3 px-4 text-right font-mono text-xs text-ink-700">{{ formatCurrency(r.accumulated_depreciation) }}</td>
                <td class="py-3 px-4 text-right font-mono text-xs font-semibold text-ink-900">{{ formatCurrency(r.net_book_value) }}</td>
              </tr>
              <tr v-if="!fiscalSchedule.length"><td colspan="4" class="py-6 text-center text-ink-500">No fiscal periods calculated yet.</td></tr>
            </tbody>
          </table>
        </div>
      </Panel>
    </div>
  </AppLayout>
</template>
