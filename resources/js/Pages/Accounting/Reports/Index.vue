<!-- ponytail: Accounting §3N reporting hub — links out to the four statutory reports built
     here plus the existing AR/AP aging engines (§3D/§3E), not duplicating them. -->
<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'

const reports = [
  { title: 'Trial Balance', description: 'Every account, net debit or credit, through a selected period — column totals prove every posted journal balanced.', href: route('accounting.reports.trial-balance') },
  { title: 'Balance Sheet', description: 'Neraca — assets, liabilities, and equity as of a period-end, with a prior-period comparison.', href: route('accounting.reports.balance-sheet') },
  { title: 'Profit & Loss', description: 'Laporan Laba Rugi — revenue, COGS, and expense for a single period, with a prior-period comparison.', href: route('accounting.reports.profit-loss') },
  { title: 'Cash Flow Statement', description: 'Indirect method, derived from balance sheet movement and P&L — no separate cash-flow entry.', href: route('accounting.reports.cash-flow') },
  { title: 'Budget vs. Actual', description: 'Variance by account, cost center, and period against the annual Budget — drill into any cell for the GL detail behind it.', href: route('accounting.reports.budget-vs-actual') },
  { title: 'AR Aging', description: 'Open customer invoices by age bucket.', href: route('accounting.ar-aging.index') },
  { title: 'AP Aging', description: 'Open vendor bills by age bucket.', href: route('accounting.ap-aging.index') },
  { title: 'AR/AP Control Reconciliation', description: 'Control account GL balance vs. sum of open subledger items — a trust check.', href: route('accounting.control-reconciliation.index') },
  { title: 'Audit Trail', description: 'Every journal posting, period lock, invoice/bill/payment posting, tax document issuance, and master-data change — append-only.', href: route('accounting.audit-log.index') },
]
</script>

<template>
  <AppLayout>
    <PageHeader title="Financial Reports" description="Every statutory report here is generated only from posted GL data — a draft or unapproved journal never affects these numbers." />

    <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
      <Panel v-for="r in reports" :key="r.title" class="p-4">
        <Link :href="r.href" class="text-sm font-semibold text-accent hover:underline">{{ r.title }}</Link>
        <p class="mt-1 text-sm text-ink-600">{{ r.description }}</p>
      </Panel>
    </div>
  </AppLayout>
</template>
