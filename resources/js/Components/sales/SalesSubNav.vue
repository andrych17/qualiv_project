<!-- Sales Module Sub-Navigation -->
<script setup lang="ts">
import { Link } from '@inertiajs/vue3'

defineProps<{
  active: 'dashboard' | 'opportunities' | 'quotations' | 'orders' | 'deliveries' | 'contracts' | 'returns' | 'commissions' | 'master'
}>()
</script>

<template>
  <div class="flex items-center gap-1 border-b border-border overflow-x-auto" role="tablist">
    <Link
      :href="route('sales.dashboard')"
      role="tab"
      class="border-b-2 px-3 py-2 text-sm font-medium transition whitespace-nowrap"
      :class="active === 'dashboard' ? 'border-accent text-ink-900 font-semibold' : 'border-transparent text-ink-600 hover:text-ink-900'"
    >
      Dashboard
    </Link>
    <Link
      :href="route('sales.opportunities.index')"
      role="tab"
      class="border-b-2 px-3 py-2 text-sm font-medium transition whitespace-nowrap"
      :class="active === 'opportunities' ? 'border-accent text-ink-900 font-semibold' : 'border-transparent text-ink-600 hover:text-ink-900'"
    >
      Opportunities
    </Link>
    <Link
      :href="route('sales.quotations.index')"
      role="tab"
      class="border-b-2 px-3 py-2 text-sm font-medium transition whitespace-nowrap"
      :class="active === 'quotations' ? 'border-accent text-ink-900 font-semibold' : 'border-transparent text-ink-600 hover:text-ink-900'"
    >
      Quotations
    </Link>
    <Link
      :href="route('sales.orders.index')"
      role="tab"
      class="border-b-2 px-3 py-2 text-sm font-medium transition whitespace-nowrap"
      :class="active === 'orders' ? 'border-accent text-ink-900 font-semibold' : 'border-transparent text-ink-600 hover:text-ink-900'"
    >
      Sales Orders
    </Link>
    <Link
      :href="route('sales.deliveries.index')"
      role="tab"
      class="border-b-2 px-3 py-2 text-sm font-medium transition whitespace-nowrap"
      :class="active === 'deliveries' ? 'border-accent text-ink-900 font-semibold' : 'border-transparent text-ink-600 hover:text-ink-900'"
    >
      Deliveries
    </Link>
    <Link
      :href="route('sales.contracts.index')"
      role="tab"
      class="border-b-2 px-3 py-2 text-sm font-medium transition whitespace-nowrap"
      :class="active === 'contracts' ? 'border-accent text-ink-900 font-semibold' : 'border-transparent text-ink-600 hover:text-ink-900'"
    >
      Contracts & Recurring
    </Link>
    <Link
      :href="route('sales.returns.index')"
      role="tab"
      class="border-b-2 px-3 py-2 text-sm font-medium transition whitespace-nowrap"
      :class="active === 'returns' ? 'border-accent text-ink-900 font-semibold' : 'border-transparent text-ink-600 hover:text-ink-900'"
    >
      Returns
    </Link>
    <Link
      :href="route('sales.commissions.index')"
      role="tab"
      class="border-b-2 px-3 py-2 text-sm font-medium transition whitespace-nowrap"
      :class="active === 'commissions' ? 'border-accent text-ink-900 font-semibold' : 'border-transparent text-ink-600 hover:text-ink-900'"
    >
      Commissions
    </Link>
    <Link
      :href="route('sales.master.price-lists.index')"
      role="tab"
      class="border-b-2 px-3 py-2 text-sm font-medium transition whitespace-nowrap"
      :class="active === 'master' ? 'border-accent text-ink-900 font-semibold' : 'border-transparent text-ink-600 hover:text-ink-900'"
    >
      Master Config
    </Link>
  </div>
</template>
