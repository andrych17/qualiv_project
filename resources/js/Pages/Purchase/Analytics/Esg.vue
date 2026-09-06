<!-- Purchase ESG & TKDN Tracking (§3M) -->
<script setup lang="ts">
import { ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import PurchaseSubNav from '@/Components/purchase/PurchaseSubNav.vue'
import { formatCurrency } from '@/Utils/formatters'

interface TkdnSummary {
  weighted_average_pct: number
  unweighted_average_pct: number
  total_spend: number
  total_declared_spend: number
  total_local_content_value: number
  tkdn_coverage_pct: number
  total_lines: number
  declared_lines: number
  compliant_target_met: boolean
}

interface TierItem {
  key: string
  label: string
  min: number
  count: number
  spend: number
  color: string
  share_pct: number
  count_share_pct: number
}

interface CategoryTkdn {
  id: number | null
  code: string
  name: string
  spend_type: string
  total_spend: number
  declared_spend: number
  avg_tkdn_pct: number
  high_tkdn_spend: number
  high_tkdn_pct: number
  coverage_pct: number
}

interface SupplierTkdn {
  id: number
  name: string
  total_spend: number
  declared_spend: number
  total_lines: number
  declared_lines: number
  avg_tkdn_pct: number
  local_content_value: number
  coverage_pct: number
  rating: 'high' | 'medium' | 'low' | 'unrated'
}

interface VendorDocSummary {
  total_vendors: number
  compliant_vendors_count: number
  expiring_soon_vendors_count: number
  expired_vendors_count: number
  doc_valid_count: number
  doc_expiring_soon_count: number
  doc_expired_count: number
}

interface ExpiringDoc {
  id: number
  vendor_profile_id: number
  vendor_name: string
  doc_type: string
  expiry_date: string | null
  days_remaining: number | null
  status: string
}

interface TkdnLineItem {
  id: number
  po_id: number
  po_no: string
  po_date: string | null
  supplier_name: string
  description: string
  category_name: string
  qty_ordered: number
  unit_price: number
  line_total: number
  local_content_pct: number | null
  local_content_value: number
  is_compliant: boolean
}

interface FilterOptions {
  categories: Array<{ id: number; code: string; name: string }>
  suppliers: Array<{ id: number; name: string }>
}

interface ActiveFilters {
  date_range: string
  start_date: string | null
  end_date: string | null
  supplier_id: number | null
  category_id: number | null
}

const props = defineProps<{
  tkdn_summary: TkdnSummary
  tier_distribution: TierItem[]
  tkdn_by_category: CategoryTkdn[]
  tkdn_by_supplier: SupplierTkdn[]
  vendor_compliance_summary: VendorDocSummary
  expiring_documents: ExpiringDoc[]
  line_items: TkdnLineItem[]
  filter_options: FilterOptions
  active_filters: ActiveFilters
}>()

const formFilters = ref({
  date_range: props.active_filters.date_range || 'all',
  start_date: props.active_filters.start_date || '',
  end_date: props.active_filters.end_date || '',
  supplier_id: props.active_filters.supplier_id || '',
  category_id: props.active_filters.category_id || '',
})

const applyFilters = () => {
  const query: Record<string, any> = {}
  if (formFilters.value.date_range) query.date_range = formFilters.value.date_range
  if (formFilters.value.date_range === 'custom') {
    if (formFilters.value.start_date) query.start_date = formFilters.value.start_date
    if (formFilters.value.end_date) query.end_date = formFilters.value.end_date
  }
  if (formFilters.value.supplier_id) query.supplier_id = formFilters.value.supplier_id
  if (formFilters.value.category_id) query.category_id = formFilters.value.category_id

  router.get(route('purchase.analytics.esg'), query, {
    preserveState: true,
    preserveScroll: true,
  })
}

const resetFilters = () => {
  formFilters.value = {
    date_range: 'all',
    start_date: '',
    end_date: '',
    supplier_id: '',
    category_id: '',
  }
  applyFilters()
}
</script>

<template>
  <AppLayout>
    <PageHeader
      title="ESG & TKDN Tracking"
      description="Indonesian local content (TKDN) reporting, regulatory compliance tier distribution, and vendor document audit (§3M)."
    />

    <div class="mt-4">
      <PurchaseSubNav active="analytics_esg" />
    </div>

    <!-- Filter Bar -->
    <div class="mt-6 bg-surface p-4 rounded-lg border border-border shadow-sm">
      <div class="grid grid-cols-1 md:grid-cols-5 gap-3 items-end">
        <div>
          <label class="block text-xs font-semibold text-ink-700 mb-1">Time Period</label>
          <select
            v-model="formFilters.date_range"
            class="w-full text-sm rounded-md border-border bg-surface text-ink-900 focus:border-accent focus:ring-accent"
            @change="applyFilters"
          >
            <option value="all">All Time</option>
            <option value="this_month">This Month</option>
            <option value="last_30_days">Last 30 Days</option>
            <option value="this_quarter">This Quarter</option>
            <option value="ytd">Year to Date (YTD)</option>
            <option value="last_12_months">Last 12 Months</option>
            <option value="custom">Custom Date Range</option>
          </select>
        </div>

        <div v-if="formFilters.date_range === 'custom'">
          <label class="block text-xs font-semibold text-ink-700 mb-1">Start Date</label>
          <input
            v-model="formFilters.start_date"
            type="date"
            class="w-full text-sm rounded-md border-border bg-surface text-ink-900 focus:border-accent focus:ring-accent"
            @change="applyFilters"
          />
        </div>

        <div v-if="formFilters.date_range === 'custom'">
          <label class="block text-xs font-semibold text-ink-700 mb-1">End Date</label>
          <input
            v-model="formFilters.end_date"
            type="date"
            class="w-full text-sm rounded-md border-border bg-surface text-ink-900 focus:border-accent focus:ring-accent"
            @change="applyFilters"
          />
        </div>

        <div>
          <label class="block text-xs font-semibold text-ink-700 mb-1">Supplier</label>
          <select
            v-model="formFilters.supplier_id"
            class="w-full text-sm rounded-md border-border bg-surface text-ink-900 focus:border-accent focus:ring-accent"
            @change="applyFilters"
          >
            <option value="">All Suppliers</option>
            <option v-for="s in filter_options.suppliers" :key="s.id" :value="s.id">
              {{ s.name }}
            </option>
          </select>
        </div>

        <div>
          <label class="block text-xs font-semibold text-ink-700 mb-1">Category</label>
          <select
            v-model="formFilters.category_id"
            class="w-full text-sm rounded-md border-border bg-surface text-ink-900 focus:border-accent focus:ring-accent"
            @change="applyFilters"
          >
            <option value="">All Categories</option>
            <option v-for="c in filter_options.categories" :key="c.id" :value="c.id">
              {{ c.name }}
            </option>
          </select>
        </div>

        <div class="flex items-center gap-2">
          <button
            type="button"
            class="px-3 py-2 text-xs font-medium text-ink-600 bg-surface hover:bg-surface-sunken border border-border rounded-md transition"
            @click="resetFilters"
          >
            Reset Filters
          </button>
        </div>
      </div>
    </div>

    <!-- TKDN Headline KPI Stat Cards -->
    <div class="mt-6 grid grid-cols-2 md:grid-cols-4 gap-4">
      <div class="p-4 bg-surface rounded-lg border border-border shadow-sm">
        <span class="text-xs font-medium text-ink-500 uppercase tracking-wider">Weighted TKDN %</span>
        <div class="mt-2 flex items-baseline gap-2">
          <div class="text-3xl font-extrabold" :class="tkdn_summary.compliant_target_met ? 'text-signal-success' : 'text-signal-warning'">
            {{ tkdn_summary.weighted_average_pct }}%
          </div>
          <span
            class="text-xs px-2 py-0.5 rounded font-bold"
            :class="tkdn_summary.compliant_target_met ? 'bg-signal-success/10 text-signal-success' : 'bg-signal-warning/10 text-signal-warning'"
          >
            {{ tkdn_summary.compliant_target_met ? 'Target Met (≥40%)' : 'Below 40% Target' }}
          </span>
        </div>
        <div class="mt-1 text-xs text-ink-500">Unweighted avg: {{ tkdn_summary.unweighted_average_pct }}%</div>
      </div>

      <div class="p-4 bg-surface rounded-lg border border-border shadow-sm">
        <span class="text-xs font-medium text-ink-500 uppercase tracking-wider">Local Content Value (IDR)</span>
        <div class="mt-2 text-2xl font-bold text-ink-900">{{ formatCurrency(tkdn_summary.total_local_content_value) }}</div>
        <div class="mt-1 text-xs text-ink-500">From {{ formatCurrency(tkdn_summary.total_declared_spend) }} evaluated spend</div>
      </div>

      <div class="p-4 bg-surface rounded-lg border border-border shadow-sm">
        <span class="text-xs font-medium text-ink-500 uppercase tracking-wider">TKDN Data Coverage</span>
        <div class="mt-2 text-2xl font-bold text-signal-info">{{ tkdn_summary.tkdn_coverage_pct }}%</div>
        <div class="mt-1 text-xs text-ink-500">{{ tkdn_summary.declared_lines }} of {{ tkdn_summary.total_lines }} lines declared</div>
      </div>

      <div class="p-4 bg-surface rounded-lg border border-border shadow-sm">
        <span class="text-xs font-medium text-ink-500 uppercase tracking-wider">Vendor Compliance Health</span>
        <div class="mt-2 text-2xl font-bold" :class="vendor_compliance_summary.expired_vendors_count > 0 ? 'text-signal-danger' : 'text-signal-success'">
          {{ vendor_compliance_summary.compliant_vendors_count }} / {{ vendor_compliance_summary.total_vendors }}
        </div>
        <div class="mt-1 text-xs text-ink-500">
          <span v-if="vendor_compliance_summary.expiring_soon_vendors_count > 0" class="text-signal-warning font-semibold">
            {{ vendor_compliance_summary.expiring_soon_vendors_count }} expiring soon
          </span>
          <span v-else-if="vendor_compliance_summary.expired_vendors_count > 0" class="text-signal-danger font-semibold">
            {{ vendor_compliance_summary.expired_vendors_count }} expired certs
          </span>
          <span v-else>All active vendors valid</span>
        </div>
      </div>
    </div>

    <!-- TKDN Compliance Tier Breakdown Cards -->
    <div class="mt-6">
      <div class="text-xs font-semibold uppercase tracking-wider text-ink-500 mb-3">TKDN Compliance Tier Distribution</div>
      <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <div
          v-for="tier in tier_distribution"
          :key="tier.key"
          class="p-4 rounded-lg border shadow-sm bg-surface"
          :class="{
            'border-signal-success/25 bg-signal-success/10/20': tier.key === 'high',
            'border-signal-warning/25 bg-signal-warning/10/20': tier.key === 'medium',
            'border-signal-info/25 bg-signal-info/10/20': tier.key === 'low',
            'border-border': tier.key === 'undeclared',
          }"
        >
          <div class="text-xs font-bold text-ink-700">{{ tier.label }}</div>
          <div class="mt-2 text-xl font-extrabold text-ink-900">{{ formatCurrency(tier.spend) }}</div>
          <div class="mt-1 text-xs text-ink-500 flex items-center justify-between">
            <span>{{ tier.count }} lines ({{ tier.count_share_pct }}%)</span>
            <span class="font-bold text-ink-700">{{ tier.share_pct }}% of spend</span>
          </div>
          <div class="mt-2 w-full bg-surface-100 rounded-full h-1.5 overflow-hidden">
            <div
              class="h-full"
              :class="{
                'bg-signal-success': tier.key === 'high',
                'bg-signal-warning': tier.key === 'medium',
                'bg-signal-info': tier.key === 'low',
                'bg-ink-400': tier.key === 'undeclared',
              }"
              :style="{ width: `${tier.share_pct}%` }"
            ></div>
          </div>
        </div>
      </div>
    </div>

    <!-- Expiring Vendor Documents Register (§3G / §3M) -->
    <div v-if="expiring_documents.length > 0" class="mt-6">
      <Panel title="Expiring & Non-Compliant Vendor Documents Alert (§3G / §3M)">
        <div class="overflow-x-auto">
          <table class="w-full text-left text-sm">
            <thead class="bg-surface-sunken text-xs uppercase text-ink-500 border-b border-border">
              <tr>
                <th class="px-3 py-2">Vendor Name</th>
                <th class="px-3 py-2">Document Type</th>
                <th class="px-3 py-2">Expiry Date</th>
                <th class="px-3 py-2 text-center">Days Remaining</th>
                <th class="px-3 py-2 text-center">Status</th>
                <th class="px-3 py-2 text-right">Action</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-border">
              <tr v-for="doc in expiring_documents" :key="doc.id" class="hover:bg-surface-sunken/40">
                <td class="px-3 py-2.5 font-medium text-ink-900">{{ doc.vendor_name }}</td>
                <td class="px-3 py-2.5 capitalize text-ink-700">{{ doc.doc_type.replace(/_/g, ' ') }}</td>
                <td class="px-3 py-2.5 text-ink-600">{{ doc.expiry_date ?? '—' }}</td>
                <td class="px-3 py-2.5 text-center font-semibold">
                  <span v-if="doc.days_remaining !== null" :class="doc.days_remaining < 0 ? 'text-signal-danger' : 'text-signal-warning'">
                    {{ doc.days_remaining < 0 ? `${Math.abs(doc.days_remaining)} days expired` : `${doc.days_remaining} days left` }}
                  </span>
                  <span v-else class="text-ink-400">—</span>
                </td>
                <td class="px-3 py-2.5 text-center">
                  <span
                    class="px-2 py-0.5 rounded text-xs font-bold capitalize"
                    :class="doc.status === 'expired' ? 'bg-signal-danger/10 text-signal-danger' : 'bg-signal-warning/10 text-signal-warning'"
                  >
                    {{ doc.status.replace(/_/g, ' ') }}
                  </span>
                </td>
                <td class="px-3 py-2.5 text-right">
                  <Link :href="route('purchase.vendors.edit', doc.vendor_profile_id)" class="text-xs font-semibold text-accent hover:underline">
                    Manage Docs →
                  </Link>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </Panel>
    </div>

    <!-- Two Column Grid: Supplier TKDN Performance & Category Breakdown -->
    <div class="mt-6 grid grid-cols-1 lg:grid-cols-2 gap-6">
      <!-- Supplier TKDN Performance -->
      <Panel title="Supplier Local Content & TKDN Ratings">
        <div v-if="tkdn_by_supplier.length > 0" class="overflow-x-auto">
          <table class="w-full text-left text-sm">
            <thead class="bg-surface-sunken text-xs uppercase text-ink-500 border-b border-border">
              <tr>
                <th class="px-3 py-2">Supplier</th>
                <th class="px-3 py-2 text-right">Total Spend</th>
                <th class="px-3 py-2 text-center">Avg TKDN %</th>
                <th class="px-3 py-2 text-right">Local Value (IDR)</th>
                <th class="px-3 py-2 text-center">Rating</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-border">
              <tr v-for="sup in tkdn_by_supplier" :key="sup.id" class="hover:bg-surface-sunken/40">
                <td class="px-3 py-2.5">
                  <div class="font-medium text-ink-900">{{ sup.name }}</div>
                  <div class="text-xs text-ink-400">{{ sup.declared_lines }} of {{ sup.total_lines }} lines declared</div>
                </td>
                <td class="px-3 py-2.5 text-right font-medium text-ink-900">{{ formatCurrency(sup.total_spend) }}</td>
                <td class="px-3 py-2.5 text-center font-bold text-ink-900">{{ sup.avg_tkdn_pct }}%</td>
                <td class="px-3 py-2.5 text-right font-semibold text-signal-success">{{ formatCurrency(sup.local_content_value) }}</td>
                <td class="px-3 py-2.5 text-center">
                  <span
                    class="px-2 py-0.5 rounded text-2xs font-extrabold uppercase tracking-wide"
                    :class="{
                      'bg-signal-success/10 text-signal-success': sup.rating === 'high',
                      'bg-signal-warning/10 text-signal-warning': sup.rating === 'medium',
                      'bg-signal-info/10 text-signal-info': sup.rating === 'low',
                      'bg-surface-100 text-ink-600': sup.rating === 'unrated',
                    }"
                  >
                    {{ sup.rating }}
                  </span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-else class="text-sm text-ink-500 py-4 text-center">No supplier TKDN data available.</div>
      </Panel>

      <!-- Category TKDN Performance -->
      <Panel title="Category TKDN & High Local Content Share">
        <div v-if="tkdn_by_category.length > 0" class="overflow-x-auto">
          <table class="w-full text-left text-sm">
            <thead class="bg-surface-sunken text-xs uppercase text-ink-500 border-b border-border">
              <tr>
                <th class="px-3 py-2">Category</th>
                <th class="px-3 py-2 text-right">Total Spend</th>
                <th class="px-3 py-2 text-center">Avg TKDN</th>
                <th class="px-3 py-2 text-right">High TKDN (≥40%)</th>
                <th class="px-3 py-2 text-center">Coverage</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-border">
              <tr v-for="cat in tkdn_by_category" :key="cat.name" class="hover:bg-surface-sunken/40">
                <td class="px-3 py-2.5">
                  <div class="font-medium text-ink-900">{{ cat.name }}</div>
                </td>
                <td class="px-3 py-2.5 text-right font-medium text-ink-900">{{ formatCurrency(cat.total_spend) }}</td>
                <td class="px-3 py-2.5 text-center font-bold text-ink-900">{{ cat.avg_tkdn_pct }}%</td>
                <td class="px-3 py-2.5 text-right font-semibold text-signal-success">
                  {{ cat.high_tkdn_pct }}%
                  <div class="text-2xs text-ink-400 font-normal">{{ formatCurrency(cat.high_tkdn_spend) }}</div>
                </td>
                <td class="px-3 py-2.5 text-center text-xs text-ink-600">{{ cat.coverage_pct }}%</td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-else class="text-sm text-ink-500 py-4 text-center">No category TKDN data available.</div>
      </Panel>
    </div>

    <!-- PO Line Item TKDN Audit Register -->
    <div class="mt-6">
      <Panel title="Purchase Order Line Items TKDN Audit Register">
        <div v-if="line_items.length > 0" class="overflow-x-auto">
          <table class="w-full text-left text-sm">
            <thead class="bg-surface-sunken text-xs uppercase text-ink-500 border-b border-border">
              <tr>
                <th class="px-3 py-2">PO No & Date</th>
                <th class="px-3 py-2">Supplier</th>
                <th class="px-3 py-2">Item Description</th>
                <th class="px-3 py-2">Category</th>
                <th class="px-3 py-2 text-right">Line Total</th>
                <th class="px-3 py-2 text-center">TKDN %</th>
                <th class="px-3 py-2 text-right">Local Value (IDR)</th>
                <th class="px-3 py-2 text-center">Compliant</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-border">
              <tr v-for="l in line_items" :key="l.id" class="hover:bg-surface-sunken/40">
                <td class="px-3 py-2.5">
                  <Link :href="route('purchase.orders.show', l.po_id)" class="font-semibold text-accent hover:underline">
                    {{ l.po_no }}
                  </Link>
                  <div class="text-xs text-ink-400">{{ l.po_date }}</div>
                </td>
                <td class="px-3 py-2.5 text-ink-800">{{ l.supplier_name }}</td>
                <td class="px-3 py-2.5 font-medium text-ink-900">{{ l.description }}</td>
                <td class="px-3 py-2.5 text-xs text-ink-600">{{ l.category_name }}</td>
                <td class="px-3 py-2.5 text-right font-medium text-ink-900">{{ formatCurrency(l.line_total) }}</td>
                <td class="px-3 py-2.5 text-center font-bold">
                  <span v-if="l.local_content_pct !== null" :class="l.local_content_pct >= 40 ? 'text-signal-success' : 'text-signal-warning'">
                    {{ l.local_content_pct }}%
                  </span>
                  <span v-else class="text-ink-400">—</span>
                </td>
                <td class="px-3 py-2.5 text-right font-semibold text-signal-success">{{ formatCurrency(l.local_content_value) }}</td>
                <td class="px-3 py-2.5 text-center">
                  <span
                    v-if="l.local_content_pct !== null"
                    class="px-2 py-0.5 rounded text-2xs font-extrabold uppercase"
                    :class="l.is_compliant ? 'bg-signal-success/10 text-signal-success' : 'bg-surface-100 text-ink-700'"
                  >
                    {{ l.is_compliant ? 'Compliant' : 'Below 40%' }}
                  </span>
                  <span v-else class="text-xs text-ink-400">Unspecified</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-else class="text-sm text-ink-500 py-4 text-center">No line item data matching current filters.</div>
      </Panel>
    </div>
  </AppLayout>
</template>
