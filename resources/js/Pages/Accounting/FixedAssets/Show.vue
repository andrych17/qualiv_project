<!-- ponytail: Accounting §3G asset detail — commercial and fiscal schedules side by side, so the SPT reconciliation gap between them is visible at a glance. -->
<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'

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
    <PageHeader :title="`${asset.asset_no} — ${asset.name}`" :description="`${asset.asset_group_name} — acquired ${asset.acquisition_date} — cost ${asset.acquisition_cost.toFixed(2)}`">
      <template #actions>
        <Link :href="route('accounting.fixed-assets.index', { company_id: asset.company_id })" class="mr-4 text-sm font-medium text-accent hover:underline">← Back to assets</Link>
        <PrimaryButton v-if="asset.status === 'active'" :href="route('accounting.fixed-assets.dispose.create', asset.id)">Dispose</PrimaryButton>
      </template>
    </PageHeader>

    <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
      <Panel class="p-4">
        <div class="text-xs uppercase text-ink-600">Status</div>
        <div class="mt-1"><StatusBadge :status="asset.status" /></div>
      </Panel>
      <Panel class="p-4">
        <div class="text-xs uppercase text-ink-600">Commercial NBV</div>
        <div class="mt-1 text-lg font-semibold text-ink-900">{{ commercialNbv.toFixed(2) }}</div>
        <div class="text-xs text-ink-600">{{ asset.commercial_method === 'declining_balance' ? `Declining ${((asset.commercial_declining_rate ?? 0) * 100).toFixed(2)}%` : 'Straight-line' }} — {{ asset.commercial_useful_life_months }} mo</div>
      </Panel>
      <Panel class="p-4">
        <div class="text-xs uppercase text-ink-600">Fiscal NBV</div>
        <div class="mt-1 text-lg font-semibold text-ink-900">{{ fiscalNbv.toFixed(2) }}</div>
        <div class="text-xs text-ink-600">{{ asset.fiscal_method === 'declining_balance' ? 'Declining-balance' : 'Straight-line' }} (group rate)</div>
      </Panel>
      <Panel class="p-4">
        <div class="text-xs uppercase text-ink-600">Vendor</div>
        <div class="mt-1 text-sm text-ink-900">{{ asset.vendor_name ?? '—' }}</div>
      </Panel>
    </div>

    <Panel v-if="disposal" class="mt-4 p-4">
      <div class="text-sm font-semibold text-ink-900">Disposal</div>
      <dl class="mt-2 grid grid-cols-2 gap-2 text-sm sm:grid-cols-4">
        <div><dt class="text-ink-600">Date</dt><dd class="text-ink-900">{{ disposal.disposal_date }}</dd></div>
        <div><dt class="text-ink-600">Proceeds</dt><dd class="text-ink-900">{{ disposal.proceeds.toFixed(2) }}</dd></div>
        <div><dt class="text-ink-600">NBV at disposal</dt><dd class="text-ink-900">{{ disposal.commercial_nbv_at_disposal.toFixed(2) }}</dd></div>
        <div><dt class="text-ink-600">Gain/(loss)</dt><dd :class="disposal.gain_loss_amount >= 0 ? 'text-signal-success' : 'text-signal-danger'">{{ disposal.gain_loss_amount.toFixed(2) }}</dd></div>
      </dl>
      <Link :href="route('accounting.journals.show', disposal.journal_id)" class="mt-2 inline-block text-sm font-medium text-accent hover:underline">View disposal journal</Link>
    </Panel>

    <Panel class="mt-4 p-4">
      <dl class="grid grid-cols-1 gap-2 text-sm sm:grid-cols-3">
        <div><dt class="text-ink-600">Fixed asset account</dt><dd class="text-ink-900">{{ asset.asset_gl_account_label }}</dd></div>
        <div><dt class="text-ink-600">Accumulated depreciation account</dt><dd class="text-ink-900">{{ asset.accumulated_depreciation_gl_account_label }}</dd></div>
        <div><dt class="text-ink-600">Depreciation expense account</dt><dd class="text-ink-900">{{ asset.depreciation_expense_gl_account_label }}</dd></div>
      </dl>
    </Panel>

    <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
      <Panel>
        <div class="border-b border-border px-4 py-3 text-sm font-semibold text-ink-900">Commercial schedule (posted to GL)</div>
        <table class="w-full text-sm">
          <thead>
            <tr class="border-b border-border text-left text-xs uppercase text-ink-600">
              <th class="px-4 py-2">Period</th>
              <th class="px-4 py-2 text-right">Amount</th>
              <th class="px-4 py-2 text-right">Accumulated</th>
              <th class="px-4 py-2 text-right">NBV</th>
              <th class="px-4 py-2"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in commercialSchedule" :key="r.period_no" class="border-b border-border">
              <td class="px-4 py-2 text-ink-700">{{ r.period_end }}</td>
              <td class="px-4 py-2 text-right text-ink-900">{{ r.depreciation_amount.toFixed(2) }}</td>
              <td class="px-4 py-2 text-right text-ink-700">{{ r.accumulated_depreciation.toFixed(2) }}</td>
              <td class="px-4 py-2 text-right font-medium text-ink-900">{{ r.net_book_value.toFixed(2) }}</td>
              <td class="px-4 py-2 text-right">
                <Link v-if="r.journal_id" :href="route('accounting.journals.show', r.journal_id)" class="text-xs text-accent hover:underline">Journal</Link>
              </td>
            </tr>
            <tr v-if="!commercialSchedule.length"><td colspan="5" class="px-4 py-6 text-center text-ink-600">No periods run yet.</td></tr>
          </tbody>
        </table>
      </Panel>

      <Panel>
        <div class="border-b border-border px-4 py-3 text-sm font-semibold text-ink-900">Fiscal schedule (SPT reconciliation only — never posted)</div>
        <table class="w-full text-sm">
          <thead>
            <tr class="border-b border-border text-left text-xs uppercase text-ink-600">
              <th class="px-4 py-2">Period</th>
              <th class="px-4 py-2 text-right">Amount</th>
              <th class="px-4 py-2 text-right">Accumulated</th>
              <th class="px-4 py-2 text-right">NBV</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in fiscalSchedule" :key="r.period_no" class="border-b border-border">
              <td class="px-4 py-2 text-ink-700">{{ r.period_end }}</td>
              <td class="px-4 py-2 text-right text-ink-900">{{ r.depreciation_amount.toFixed(2) }}</td>
              <td class="px-4 py-2 text-right text-ink-700">{{ r.accumulated_depreciation.toFixed(2) }}</td>
              <td class="px-4 py-2 text-right font-medium text-ink-900">{{ r.net_book_value.toFixed(2) }}</td>
            </tr>
            <tr v-if="!fiscalSchedule.length"><td colspan="4" class="px-4 py-6 text-center text-ink-600">No periods run yet.</td></tr>
          </tbody>
        </table>
      </Panel>
    </div>
  </AppLayout>
</template>
