<!-- Sales Master Data Sub-Navigation -->
<script setup lang="ts">
import { Link } from '@inertiajs/vue3'

defineProps<{
  active: 'price-lists' | 'teams' | 'territories' | 'promo-codes' | 'commission-plans' | 'customers'
}>()
</script>

<template>
  <div class="flex items-center gap-1 border-b border-border overflow-x-auto" role="tablist">
    <Link
      :href="route('sales.master.price-lists.index')"
      role="tab"
      class="border-b-2 px-3 py-1.5 text-xs font-medium transition whitespace-nowrap"
      :class="active === 'price-lists' ? 'border-ink-900 text-ink-900 font-bold' : 'border-transparent text-ink-500 hover:text-ink-900'"
    >
      Price Lists
    </Link>
    <Link
      :href="route('sales.master.teams.index')"
      role="tab"
      class="border-b-2 px-3 py-1.5 text-xs font-medium transition whitespace-nowrap"
      :class="active === 'teams' ? 'border-ink-900 text-ink-900 font-bold' : 'border-transparent text-ink-500 hover:text-ink-900'"
    >
      Sales Teams
    </Link>
    <Link
      :href="route('sales.master.territories.index')"
      role="tab"
      class="border-b-2 px-3 py-1.5 text-xs font-medium transition whitespace-nowrap"
      :class="active === 'territories' ? 'border-ink-900 text-ink-900 font-bold' : 'border-transparent text-ink-500 hover:text-ink-900'"
    >
      Territories
    </Link>
    <Link
      :href="route('sales.master.promo-codes.index')"
      role="tab"
      class="border-b-2 px-3 py-1.5 text-xs font-medium transition whitespace-nowrap"
      :class="active === 'promo-codes' ? 'border-ink-900 text-ink-900 font-bold' : 'border-transparent text-ink-500 hover:text-ink-900'"
    >
      Promo Codes
    </Link>
    <Link
      :href="route('sales.master.commission-plans.index')"
      role="tab"
      class="border-b-2 px-3 py-1.5 text-xs font-medium transition whitespace-nowrap"
      :class="active === 'commission-plans' ? 'border-ink-900 text-ink-900 font-bold' : 'border-transparent text-ink-500 hover:text-ink-900'"
    >
      Commission Plans
    </Link>
    <Link
      :href="route('sales.master.customers.index')"
      role="tab"
      class="border-b-2 px-3 py-1.5 text-xs font-medium transition whitespace-nowrap"
      :class="active === 'customers' ? 'border-ink-900 text-ink-900 font-bold' : 'border-transparent text-ink-500 hover:text-ink-900'"
    >
      Customer Profiles & Credit
    </Link>
  </div>
</template>
