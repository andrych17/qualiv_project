<!-- ponytail: Products/Categories/UoMs/Warehouses/Receipts/Issues/Transfers/Adjustments
     switcher — one INVENTORY menu row covers all Inventory routes, so the second-level nav
     lives here (same pattern as CrmSubNav). -->
<script setup lang="ts">
import { Link } from '@inertiajs/vue3'

defineProps<{
  active: 'products' | 'categories' | 'uoms' | 'warehouses' | 'goodsReceipts' | 'goodsIssues' | 'transfers' | 'adjustments' | 'adjustmentReasons' | 'stockCard' | 'valuation' | 'batches' | 'serials' | 'reservations' | 'pickLists' | 'packLists' | 'shipments' | 'cycleCounts' | 'putawayRules'
}>()
</script>

<template>
  <div class="flex items-center gap-1 border-b border-border" role="tablist">
    <Link
      :href="route('inventory.products.index')"
      role="tab"
      class="border-b-2 px-3 py-2 text-sm font-medium transition"
      :class="active === 'products' ? 'border-accent text-ink-900' : 'border-transparent text-ink-600 hover:text-ink-900'"
    >
      Products
    </Link>
    <Link
      :href="route('inventory.categories.index')"
      role="tab"
      class="border-b-2 px-3 py-2 text-sm font-medium transition"
      :class="active === 'categories' ? 'border-accent text-ink-900' : 'border-transparent text-ink-600 hover:text-ink-900'"
    >
      Categories
    </Link>
    <Link
      :href="route('inventory.uoms.index')"
      role="tab"
      class="border-b-2 px-3 py-2 text-sm font-medium transition"
      :class="active === 'uoms' ? 'border-accent text-ink-900' : 'border-transparent text-ink-600 hover:text-ink-900'"
    >
      Units of Measure
    </Link>
    <Link
      :href="route('inventory.warehouses.index')"
      role="tab"
      class="border-b-2 px-3 py-2 text-sm font-medium transition"
      :class="active === 'warehouses' ? 'border-accent text-ink-900' : 'border-transparent text-ink-600 hover:text-ink-900'"
    >
      Warehouses
    </Link>
    <Link
      :href="route('inventory.goodsReceipts.index')"
      role="tab"
      class="border-b-2 px-3 py-2 text-sm font-medium transition"
      :class="active === 'goodsReceipts' ? 'border-accent text-ink-900' : 'border-transparent text-ink-600 hover:text-ink-900'"
    >
      Goods Receipts
    </Link>
    <Link
      :href="route('inventory.goodsIssues.index')"
      role="tab"
      class="border-b-2 px-3 py-2 text-sm font-medium transition"
      :class="active === 'goodsIssues' ? 'border-accent text-ink-900' : 'border-transparent text-ink-600 hover:text-ink-900'"
    >
      Goods Issues
    </Link>
    <Link
      :href="route('inventory.transfers.index')"
      role="tab"
      class="border-b-2 px-3 py-2 text-sm font-medium transition"
      :class="active === 'transfers' ? 'border-accent text-ink-900' : 'border-transparent text-ink-600 hover:text-ink-900'"
    >
      Transfers
    </Link>
    <Link
      :href="route('inventory.adjustments.index')"
      role="tab"
      class="border-b-2 px-3 py-2 text-sm font-medium transition"
      :class="active === 'adjustments' ? 'border-accent text-ink-900' : 'border-transparent text-ink-600 hover:text-ink-900'"
    >
      Adjustments
    </Link>
    <Link
      :href="route('inventory.adjustmentReasons.index')"
      role="tab"
      class="border-b-2 px-3 py-2 text-sm font-medium transition"
      :class="active === 'adjustmentReasons' ? 'border-accent text-ink-900' : 'border-transparent text-ink-600 hover:text-ink-900'"
    >
      Reasons
    </Link>
    <Link
      :href="route('inventory.batches.index')"
      role="tab"
      class="border-b-2 px-3 py-2 text-sm font-medium transition"
      :class="active === 'batches' ? 'border-accent text-ink-900' : 'border-transparent text-ink-600 hover:text-ink-900'"
    >
      Batches
    </Link>
    <Link
      :href="route('inventory.serials.index')"
      role="tab"
      class="border-b-2 px-3 py-2 text-sm font-medium transition"
      :class="active === 'serials' ? 'border-accent text-ink-900' : 'border-transparent text-ink-600 hover:text-ink-900'"
    >
      Serials
    </Link>
    <Link
      :href="route('inventory.reservations.index')"
      role="tab"
      class="border-b-2 px-3 py-2 text-sm font-medium transition"
      :class="active === 'reservations' ? 'border-accent text-ink-900' : 'border-transparent text-ink-600 hover:text-ink-900'"
    >
      Reservations
    </Link>
    <Link
      :href="route('inventory.pickLists.index')"
      role="tab"
      class="border-b-2 px-3 py-2 text-sm font-medium transition"
      :class="active === 'pickLists' ? 'border-accent text-ink-900' : 'border-transparent text-ink-600 hover:text-ink-900'"
    >
      Pick Lists
    </Link>
    <Link
      :href="route('inventory.packLists.index')"
      role="tab"
      class="border-b-2 px-3 py-2 text-sm font-medium transition"
      :class="active === 'packLists' ? 'border-accent text-ink-900' : 'border-transparent text-ink-600 hover:text-ink-900'"
    >
      Pack Lists
    </Link>
    <Link
      :href="route('inventory.shipments.index')"
      role="tab"
      class="border-b-2 px-3 py-2 text-sm font-medium transition"
      :class="active === 'shipments' ? 'border-accent text-ink-900' : 'border-transparent text-ink-600 hover:text-ink-900'"
    >
      Shipments
    </Link>
    <Link
      :href="route('inventory.cycleCounts.index')"
      role="tab"
      class="border-b-2 px-3 py-2 text-sm font-medium transition"
      :class="active === 'cycleCounts' ? 'border-accent text-ink-900' : 'border-transparent text-ink-600 hover:text-ink-900'"
    >
      Cycle Counts
    </Link>
    <Link
      :href="route('inventory.putawayRules.index')"
      role="tab"
      class="border-b-2 px-3 py-2 text-sm font-medium transition"
      :class="active === 'putawayRules' ? 'border-accent text-ink-900' : 'border-transparent text-ink-600 hover:text-ink-900'"
    >
      Put-away Rules
    </Link>
    <Link
      :href="route('inventory.stockCard.index')"
      role="tab"
      class="border-b-2 px-3 py-2 text-sm font-medium transition"
      :class="active === 'stockCard' ? 'border-accent text-ink-900' : 'border-transparent text-ink-600 hover:text-ink-900'"
    >
      Stock Card
    </Link>
    <Link
      :href="route('inventory.valuation.index')"
      role="tab"
      class="border-b-2 px-3 py-2 text-sm font-medium transition"
      :class="active === 'valuation' ? 'border-accent text-ink-900' : 'border-transparent text-ink-600 hover:text-ink-900'"
    >
      Valuation
    </Link>
  </div>
</template>
