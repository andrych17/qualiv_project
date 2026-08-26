<!-- Price Lists Index (§3B) -->
<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SalesSubNav from '@/Components/sales/SalesSubNav.vue'
import SalesMasterSubNav from '@/Components/sales/SalesMasterSubNav.vue'
import { useConfirm } from '@/Composables/useConfirmDialog'

interface PriceListItem {
  id: number
  name: string
  currency: string
  customer_segment: string | null
  is_tenant_default: boolean
  is_active: boolean
  territory?: { name: string }
  lines_count?: number
}

const props = defineProps<{
  priceLists: PriceListItem[]
}>()

const { confirm } = useConfirm()

const deletePriceList = (id: number) => {
  confirm({
    title: 'Delete Price List?',
    description: 'Are you sure you want to delete this price list?',
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () => router.delete(route('sales.master.price-lists.destroy', id)),
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader
      title="Sales Master Configuration"
      description="Manage price lists, pricing matrices, sales territories, rep teams, and promo codes."
    >
      <template #actions>
        <PrimaryButton :href="route('sales.master.price-lists.create')">New Price List</PrimaryButton>
      </template>
    </PageHeader>

    <div class="mt-4">
      <SalesSubNav active="master" />
    </div>

    <div class="mt-4">
      <SalesMasterSubNav active="price-lists" />
    </div>

    <div class="mt-6 rounded-lg border border-border bg-surface-0 overflow-x-auto">
      <table class="w-full text-left text-sm">
        <thead class="bg-surface-50 text-xs text-ink-500 uppercase border-b border-border">
          <tr>
            <th class="py-3 px-4">Price List Name</th>
            <th class="py-3 px-4">Currency</th>
            <th class="py-3 px-4">Territory</th>
            <th class="py-3 px-4">Customer Segment</th>
            <th class="py-3 px-4">Default</th>
            <th class="py-3 px-4">Status</th>
            <th class="py-3 px-4 text-right">Action</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-border">
          <tr v-for="pl in props.priceLists" :key="pl.id" class="hover:bg-surface-50">
            <td class="py-3 px-4 font-semibold text-ink-900">{{ pl.name }}</td>
            <td class="py-3 px-4 font-mono text-xs">{{ pl.currency }}</td>
            <td class="py-3 px-4 text-ink-600">{{ pl.territory?.name ?? 'All Territories' }}</td>
            <td class="py-3 px-4 text-ink-600 capitalize">{{ pl.customer_segment ?? 'General' }}</td>
            <td class="py-3 px-4">
              <span v-if="pl.is_tenant_default" class="text-xs font-semibold text-accent bg-accent/10 px-2 py-0.5 rounded">
                Tenant Default
              </span>
              <span v-else class="text-ink-400 text-xs">-</span>
            </td>
            <td class="py-3 px-4 text-xs font-semibold" :class="pl.is_active ? 'text-emerald-600' : 'text-ink-400'">
              {{ pl.is_active ? 'Active' : 'Inactive' }}
            </td>
            <td class="py-3 px-4 text-right space-x-2">
              <Link :href="route('sales.master.price-lists.edit', pl.id)" class="text-xs font-medium text-accent hover:underline">
                Edit
              </Link>
              <button
                type="button"
                @click="deletePriceList(pl.id)"
                class="text-xs font-medium text-rose-600 hover:underline"
              >
                Delete
              </button>
            </td>
          </tr>
          <tr v-if="props.priceLists.length === 0">
            <td colspan="7" class="py-8 text-center text-ink-500">No price lists configured.</td>
          </tr>
        </tbody>
      </table>
    </div>
  </AppLayout>
</template>
