<!-- ponytail: Vendor Profile list (§3G) — flat DataTable, same small-dataset
     client-side filter pattern as CRM Leads Index. -->
<script setup lang="ts">
import { ref, computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import DataTable, { type SortState } from '@/Components/tables/DataTable.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import PurchaseSubNav from '@/Components/purchase/PurchaseSubNav.vue'

interface VendorItem {
  id: number
  partner_id: number
  partner_name: string | null
  payment_terms_days: number
  preferred_currency: string | null
  is_preferred: boolean
  onboarding_status: string
}

const props = defineProps<{ vendors: VendorItem[] }>()

const search = ref('')
const sort = ref<SortState>(null)

const columns = [
  { key: 'partner_name', label: 'Vendor', sortable: true },
  { key: 'payment_terms_days', label: 'Payment terms' },
  { key: 'preferred_currency', label: 'Currency' },
  { key: 'onboarding_status', label: 'Status', sortable: true },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

const filteredVendors = computed(() => {
  let list = props.vendors
  if (search.value) {
    const q = search.value.toLowerCase()
    list = list.filter((v) => (v.partner_name ?? '').toLowerCase().includes(q))
  }
  if (sort.value) {
    const { key, direction } = sort.value
    list = [...list].sort((a, b) => {
      const av = String((a as unknown as Record<string, unknown>)[key] ?? '')
      const bv = String((b as unknown as Record<string, unknown>)[key] ?? '')
      return direction === 'asc' ? av.localeCompare(bv) : bv.localeCompare(av)
    })
  }
  return list
})
</script>

<template>
  <AppLayout>
    <PageHeader title="Vendors" description="Vendor profiles — payment terms, banking, onboarding status.">
      <template #actions>
        <PrimaryButton :href="route('purchase.vendors.create')">Add vendor</PrimaryButton>
      </template>
    </PageHeader>

    <div class="mt-4">
      <PurchaseSubNav active="vendors" />
    </div>

    <div class="mt-6">
      <DataTable
        :columns="columns"
        :items="filteredVendors"
        v-model:sort="sort"
        v-model:search="search"
        search-placeholder="Search vendor name…"
        status-rail-key="onboarding_status"
        empty-title="No vendors yet"
        empty-description="Extend a CRM partner with a vendor profile to get started."
      >
        <template #cell-partner_name="{ item }">
          <Link :href="route('purchase.vendors.edit', item.id)" class="text-sm font-medium text-accent hover:underline">
            {{ item.partner_name }}
          </Link>
        </template>
        <template #cell-payment_terms_days="{ item }">
          {{ (item as VendorItem).payment_terms_days }} days
        </template>
        <template #cell-onboarding_status="{ item }">
          <StatusBadge :status="(item as VendorItem).onboarding_status" />
        </template>
        <template #cell-actions="{ item }">
          <Link
            :href="route('purchase.vendors.edit', item.id)"
            class="text-sm font-medium text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            Edit
          </Link>
        </template>
      </DataTable>
    </div>
  </AppLayout>
</template>
