<!-- ponytail: Purchase module second-level subnav -->
<script setup lang="ts">
import { Link } from '@inertiajs/vue3'

defineProps<{
  active: 'dashboard' | 'requisitions' | 'sourcing' | 'orders' | 'receipts' | 'invoices' | 'catalog' | 'contracts' | 'exceptions' | 'vendors' | 'analytics_spend' | 'analytics_esg'
}>()
</script>

<template>
  <div class="flex items-center gap-1 border-b border-border overflow-x-auto" role="tablist">
    <Link
      :href="route('purchase.dashboard')"
      role="tab"
      class="border-b-2 px-3 py-2 text-sm font-medium whitespace-nowrap transition"
      :class="active === 'dashboard' ? 'border-accent text-ink-900 font-semibold' : 'border-transparent text-ink-600 hover:text-ink-900'"
    >
      Dashboard
    </Link>
    <Link
      :href="route('purchase.requisitions.index')"
      role="tab"
      class="border-b-2 px-3 py-2 text-sm font-medium whitespace-nowrap transition"
      :class="active === 'requisitions' ? 'border-accent text-ink-900 font-semibold' : 'border-transparent text-ink-600 hover:text-ink-900'"
    >
      Requisitions (PR)
    </Link>
    <Link
      :href="route('purchase.sourcing.index')"
      role="tab"
      class="border-b-2 px-3 py-2 text-sm font-medium whitespace-nowrap transition"
      :class="active === 'sourcing' ? 'border-accent text-ink-900 font-semibold' : 'border-transparent text-ink-600 hover:text-ink-900'"
    >
      Sourcing (RFQ)
    </Link>
    <Link
      :href="route('purchase.orders.index')"
      role="tab"
      class="border-b-2 px-3 py-2 text-sm font-medium whitespace-nowrap transition"
      :class="active === 'orders' ? 'border-accent text-ink-900 font-semibold' : 'border-transparent text-ink-600 hover:text-ink-900'"
    >
      Purchase Orders (PO)
    </Link>
    <Link
      :href="route('purchase.receipts.index')"
      role="tab"
      class="border-b-2 px-3 py-2 text-sm font-medium whitespace-nowrap transition"
      :class="active === 'receipts' ? 'border-accent text-ink-900 font-semibold' : 'border-transparent text-ink-600 hover:text-ink-900'"
    >
      Goods Receipts (GR)
    </Link>
    <Link
      :href="route('purchase.invoices.index')"
      role="tab"
      class="border-b-2 px-3 py-2 text-sm font-medium whitespace-nowrap transition"
      :class="active === 'invoices' ? 'border-accent text-ink-900 font-semibold' : 'border-transparent text-ink-600 hover:text-ink-900'"
    >
      Invoices (3-Way Match)
    </Link>
    <Link
      :href="route('purchase.catalog.index')"
      role="tab"
      class="border-b-2 px-3 py-2 text-sm font-medium whitespace-nowrap transition"
      :class="active === 'catalog' ? 'border-accent text-ink-900 font-semibold' : 'border-transparent text-ink-600 hover:text-ink-900'"
    >
      Item Catalog
    </Link>
    <Link
      :href="route('purchase.contracts.index')"
      role="tab"
      class="border-b-2 px-3 py-2 text-sm font-medium whitespace-nowrap transition"
      :class="active === 'contracts' ? 'border-accent text-ink-900 font-semibold' : 'border-transparent text-ink-600 hover:text-ink-900'"
    >
      Contracts
    </Link>
    <Link
      :href="route('purchase.analytics.spend')"
      role="tab"
      class="border-b-2 px-3 py-2 text-sm font-medium whitespace-nowrap transition"
      :class="active === 'analytics_spend' ? 'border-accent text-ink-900 font-semibold' : 'border-transparent text-ink-600 hover:text-ink-900'"
    >
      Spend Analytics
    </Link>
    <Link
      :href="route('purchase.analytics.esg')"
      role="tab"
      class="border-b-2 px-3 py-2 text-sm font-medium whitespace-nowrap transition"
      :class="active === 'analytics_esg' ? 'border-accent text-ink-900 font-semibold' : 'border-transparent text-ink-600 hover:text-ink-900'"
    >
      ESG & TKDN
    </Link>
    <Link
      :href="route('purchase.exceptions.index')"
      role="tab"
      class="border-b-2 px-3 py-2 text-sm font-medium whitespace-nowrap transition"
      :class="active === 'exceptions' ? 'border-accent text-ink-900 font-semibold' : 'border-transparent text-ink-600 hover:text-ink-900'"
    >
      Exceptions
    </Link>
    <Link
      :href="route('purchase.vendors.index')"
      role="tab"
      class="border-b-2 px-3 py-2 text-sm font-medium whitespace-nowrap transition"
      :class="active === 'vendors' ? 'border-accent text-ink-900 font-semibold' : 'border-transparent text-ink-600 hover:text-ink-900'"
    >
      Vendors
    </Link>
  </div>
</template>
