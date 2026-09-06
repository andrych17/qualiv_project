<!-- Purchase Spend Analytics (§3J) -->
<script setup lang="ts">
import { ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import PurchaseSubNav from '@/Components/purchase/PurchaseSubNav.vue'
import { formatCurrency } from '@/Utils/formatters'

interface KpiData {
  total_spend: number
  pos_count: number
  avg_po_value: number
  direct_spend: number
  direct_spend_pct: number
  indirect_spend: number
  indirect_spend_pct: number
  capex_spend: number
  capex_spend_pct: number
  opex_spend: number
  opex_spend_pct: number
  on_catalog_spend: number
  on_catalog_pct: number
  off_catalog_spend: number
  off_catalog_pct: number
  contract_covered_spend: number
  contract_coverage_pct: number
}

interface ConcentrationData {
  top_5_share_pct: number
  top_10_share_pct: number
  high_risk_flag: boolean
  top_supplier_name: string | null
  top_supplier_share_pct: number
}

interface SupplierSpend {
  id: number
  name: string
  po_count: number
  total_spend: number
  share_pct: number
  currency_code: string
}

interface CategorySpend {
  id: number | null
  code: string
  name: string
  spend_type: string
  capex_opex: string
  total_spend: number
  line_count: number
  share_pct: number
}

interface CostCenterSpend {
  id: number | null
  code: string
  name: string
  total_spend: number
  po_count: number
  budget_amount: number
  budget_consumed_pct: number
  remaining_budget: number | null
}

interface MonthlyTrend {
  period: string
  total_spend: number
  direct_spend: number
  indirect_spend: number
  po_count: number
}

interface ContractUtil {
  id: number
  title: string
  type: string
  supplier_name: string
  contract_value: number
  spend_amount: number
  utilization_pct: number
  remaining_headroom: number
  health_status: 'normal' | 'warning' | 'exceeded'
  end_date: string
}

interface FilterOptions {
  categories: Array<{ id: number; code: string; name: string }>
  cost_centers: Array<{ id: number; code: string; name: string }>
}

interface ActiveFilters {
  date_range: string
  start_date: string | null
  end_date: string | null
  supplier_id: number | null
  category_id: number | null
  cost_center_id: number | null
}

const props = defineProps<{
  kpis: KpiData
  supplier_concentration: ConcentrationData
  spend_by_supplier: SupplierSpend[]
  spend_by_category: CategorySpend[]
  spend_by_cost_center: CostCenterSpend[]
  monthly_trend: MonthlyTrend[]
  contract_utilization: ContractUtil[]
  filter_options: FilterOptions
  active_filters: ActiveFilters
}>()

const formFilters = ref({
  date_range: props.active_filters.date_range || 'all',
  start_date: props.active_filters.start_date || '',
  end_date: props.active_filters.end_date || '',
  category_id: props.active_filters.category_id || '',
  cost_center_id: props.active_filters.cost_center_id || '',
})

const applyFilters = () => {
  const query: Record<string, any> = {}
  if (formFilters.value.date_range) query.date_range = formFilters.value.date_range
  if (formFilters.value.date_range === 'custom') {
    if (formFilters.value.start_date) query.start_date = formFilters.value.start_date
    if (formFilters.value.end_date) query.end_date = formFilters.value.end_date
  }
  if (formFilters.value.category_id) query.category_id = formFilters.value.category_id
  if (formFilters.value.cost_center_id) query.cost_center_id = formFilters.value.cost_center_id

  router.get(route('purchase.analytics.spend'), query, {
    preserveState: true,
    preserveScroll: true,
  })
}

const resetFilters = () => {
  formFilters.value = {
    date_range: 'all',
    start_date: '',
    end_date: '',
    category_id: '',
    cost_center_id: '',
  }
  applyFilters()
}
</script>

<template>
  <AppLayout>
    <PageHeader
      title="Spend Analytics"
      description="Multi-dimensional spend breakdowns, supplier concentration, budget variances, and master contract utilization (§3J)."
    />

    <div class="mt-4">
      <PurchaseSubNav active="analytics_spend" />
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
          <label class="block text-xs font-semibold text-ink-700 mb-1">Spend Category</label>
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

        <div>
          <label class="block text-xs font-semibold text-ink-700 mb-1">Cost Center</label>
          <select
            v-model="formFilters.cost_center_id"
            class="w-full text-sm rounded-md border-border bg-surface text-ink-900 focus:border-accent focus:ring-accent"
            @change="applyFilters"
          >
            <option value="">All Cost Centers</option>
            <option v-for="cc in filter_options.cost_centers" :key="cc.id" :value="cc.id">
              {{ cc.name }} ({{ cc.code }})
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

    <!-- Concentration Risk Alert Banner -->
    <div
      v-if="supplier_concentration.high_risk_flag"
      class="mt-4 p-4 rounded-lg bg-signal-warning/10 border border-signal-warning/25 text-signal-warning flex items-start gap-3"
    >
      <span class="text-xl">⚠️</span>
      <div>
        <div class="font-bold text-sm">High Supplier Concentration Risk Detected</div>
        <div class="text-xs text-signal-warning mt-0.5">
          <strong>{{ supplier_concentration.top_supplier_name }}</strong> represents
          <strong>{{ supplier_concentration.top_supplier_share_pct }}%</strong> of total spend. It is recommended to diversify supply sources or negotiate multi-year framework pricing.
        </div>
      </div>
    </div>

    <!-- KPI Summary Stat Cards -->
    <div class="mt-6 grid grid-cols-2 md:grid-cols-4 gap-4">
      <div class="p-4 bg-surface rounded-lg border border-border shadow-sm">
        <span class="text-xs font-medium text-ink-500 uppercase tracking-wider">Total Evaluated Spend</span>
        <div class="mt-2 text-2xl font-bold text-ink-900">{{ formatCurrency(kpis.total_spend) }}</div>
        <div class="mt-1 text-xs text-ink-500">{{ kpis.pos_count }} POs • Avg {{ formatCurrency(kpis.avg_po_value) }}/PO</div>
      </div>

      <div class="p-4 bg-surface rounded-lg border border-border shadow-sm">
        <span class="text-xs font-medium text-ink-500 uppercase tracking-wider">Direct vs Indirect</span>
        <div class="mt-2 flex items-baseline justify-between text-sm">
          <span class="font-bold text-signal-success">Direct: {{ kpis.direct_spend_pct }}%</span>
          <span class="text-ink-500">Indirect: {{ kpis.indirect_spend_pct }}%</span>
        </div>
        <div class="mt-2 w-full bg-surface-100 rounded-full h-2 overflow-hidden flex">
          <div class="bg-signal-success h-full" :style="{ width: `${kpis.direct_spend_pct}%` }"></div>
          <div class="bg-signal-info h-full" :style="{ width: `${kpis.indirect_spend_pct}%` }"></div>
        </div>
        <div class="mt-1 text-xs text-ink-500">{{ formatCurrency(kpis.direct_spend) }} direct spend</div>
      </div>

      <div class="p-4 bg-surface rounded-lg border border-border shadow-sm">
        <span class="text-xs font-medium text-ink-500 uppercase tracking-wider">Catalog Compliance</span>
        <div class="mt-2 flex items-baseline justify-between text-sm">
          <span class="font-bold text-signal-info">Catalog: {{ kpis.on_catalog_pct }}%</span>
          <span :class="kpis.off_catalog_pct > 20 ? 'text-signal-danger font-semibold' : 'text-ink-500'">
            Off-Catalog: {{ kpis.off_catalog_pct }}%
          </span>
        </div>
        <div class="mt-2 w-full bg-surface-100 rounded-full h-2 overflow-hidden flex">
          <div class="bg-signal-info h-full" :style="{ width: `${kpis.on_catalog_pct}%` }"></div>
          <div class="bg-signal-danger h-full" :style="{ width: `${kpis.off_catalog_pct}%` }"></div>
        </div>
        <div class="mt-1 text-xs text-ink-500">
          {{ kpis.off_catalog_pct > 20 ? '⚠️ Maverick spend exceeds 20%' : 'Compliant purchasing behavior' }}
        </div>
      </div>

      <div class="p-4 bg-surface rounded-lg border border-border shadow-sm">
        <span class="text-xs font-medium text-ink-500 uppercase tracking-wider">Contract Coverage</span>
        <div class="mt-2 text-2xl font-bold text-signal-info">{{ kpis.contract_coverage_pct }}%</div>
        <div class="mt-1 text-xs text-ink-500">{{ formatCurrency(kpis.contract_covered_spend) }} under master contracts</div>
      </div>
    </div>

    <!-- Two Column Grid: Top Suppliers & Category Breakdown -->
    <div class="mt-6 grid grid-cols-1 lg:grid-cols-2 gap-6">
      <!-- Top Suppliers & Concentration -->
      <Panel title="Supplier Concentration & Ranking">
        <div class="mb-4 flex items-center justify-between text-xs bg-surface-sunken p-2.5 rounded border border-border">
          <div>Top 5 Share: <strong class="text-ink-900">{{ supplier_concentration.top_5_share_pct }}%</strong></div>
          <div>Top 10 Share: <strong class="text-ink-900">{{ supplier_concentration.top_10_share_pct }}%</strong></div>
          <div>Total Suppliers: <strong class="text-ink-900">{{ spend_by_supplier.length }}</strong></div>
        </div>

        <div v-if="spend_by_supplier.length > 0" class="overflow-x-auto">
          <table class="w-full text-left text-sm">
            <thead class="bg-surface-sunken text-xs uppercase text-ink-500 border-b border-border">
              <tr>
                <th class="px-3 py-2">Supplier</th>
                <th class="px-3 py-2 text-center">POs</th>
                <th class="px-3 py-2 text-right">Total Spend</th>
                <th class="px-3 py-2 text-right">Share %</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-border">
              <tr v-for="(s, idx) in spend_by_supplier.slice(0, 8)" :key="s.id" class="hover:bg-surface-sunken/40">
                <td class="px-3 py-2.5">
                  <div class="font-medium text-ink-900 flex items-center gap-1.5">
                    <span class="text-xs text-ink-400 font-mono">#{{ idx + 1 }}</span>
                    {{ s.name }}
                  </div>
                </td>
                <td class="px-3 py-2.5 text-center text-ink-600">{{ s.po_count }}</td>
                <td class="px-3 py-2.5 text-right font-semibold text-ink-900">{{ formatCurrency(s.total_spend) }}</td>
                <td class="px-3 py-2.5 text-right">
                  <div class="flex items-center justify-end gap-2">
                    <span class="font-semibold text-ink-800 text-xs">{{ s.share_pct }}%</span>
                    <div class="w-12 bg-surface-100 rounded-full h-1.5 overflow-hidden">
                      <div class="bg-accent h-full" :style="{ width: `${Math.min(100, s.share_pct)}%` }"></div>
                    </div>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-else class="text-sm text-ink-500 py-4 text-center">No supplier spend data for selected filters.</div>
      </Panel>

      <!-- Spend by Category -->
      <Panel title="Spend by Category & Classification">
        <div v-if="spend_by_category.length > 0" class="overflow-x-auto">
          <table class="w-full text-left text-sm">
            <thead class="bg-surface-sunken text-xs uppercase text-ink-500 border-b border-border">
              <tr>
                <th class="px-3 py-2">Category</th>
                <th class="px-3 py-2">Type</th>
                <th class="px-3 py-2 text-right">Total Spend</th>
                <th class="px-3 py-2 text-right">Share %</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-border">
              <tr v-for="cat in spend_by_category" :key="cat.name" class="hover:bg-surface-sunken/40">
                <td class="px-3 py-2.5">
                  <div class="font-medium text-ink-900">{{ cat.name }}</div>
                </td>
                <td class="px-3 py-2.5">
                  <span
                    class="inline-flex items-center px-1.5 py-0.5 rounded text-2xs font-semibold uppercase"
                    :class="cat.spend_type === 'direct' ? 'bg-signal-success/10 text-signal-success' : 'bg-surface-100 text-ink-700'"
                  >
                    {{ cat.spend_type }}
                  </span>
                  <span
                    class="inline-flex items-center px-1.5 py-0.5 rounded text-2xs font-semibold uppercase ml-1"
                    :class="cat.capex_opex === 'capex' ? 'bg-signal-info/10 text-signal-info' : 'bg-signal-info/10 text-signal-info'"
                  >
                    {{ cat.capex_opex }}
                  </span>
                </td>
                <td class="px-3 py-2.5 text-right font-semibold text-ink-900">{{ formatCurrency(cat.total_spend) }}</td>
                <td class="px-3 py-2.5 text-right">
                  <div class="flex items-center justify-end gap-2">
                    <span class="font-semibold text-ink-800 text-xs">{{ cat.share_pct }}%</span>
                    <div class="w-12 bg-surface-100 rounded-full h-1.5 overflow-hidden">
                      <div class="bg-signal-info h-full" :style="{ width: `${Math.min(100, cat.share_pct)}%` }"></div>
                    </div>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-else class="text-sm text-ink-500 py-4 text-center">No category spend data available.</div>
      </Panel>
    </div>

    <!-- Cost Center Spend vs Soft Budgets -->
    <div class="mt-6">
      <Panel title="Cost Center / Department Spend vs Soft Budgets">
        <div v-if="spend_by_cost_center.length > 0" class="overflow-x-auto">
          <table class="w-full text-left text-sm">
            <thead class="bg-surface-sunken text-xs uppercase text-ink-500 border-b border-border">
              <tr>
                <th class="px-3 py-2">Cost Center</th>
                <th class="px-3 py-2 text-right">Total Spend</th>
                <th class="px-3 py-2 text-right">Budget Allocation</th>
                <th class="px-3 py-2 text-center">Consumed %</th>
                <th class="px-3 py-2 text-right">Remaining Headroom</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-border">
              <tr v-for="cc in spend_by_cost_center" :key="cc.code" class="hover:bg-surface-sunken/40">
                <td class="px-3 py-2.5">
                  <div class="font-medium text-ink-900">{{ cc.name }}</div>
                  <div class="text-xs text-ink-400 font-mono">{{ cc.code }}</div>
                </td>
                <td class="px-3 py-2.5 text-right font-semibold text-ink-900">{{ formatCurrency(cc.total_spend) }}</td>
                <td class="px-3 py-2.5 text-right text-ink-600">
                  {{ cc.budget_amount > 0 ? formatCurrency(cc.budget_amount) : 'Uncapped' }}
                </td>
                <td class="px-3 py-2.5 text-center">
                  <span
                    v-if="cc.budget_amount > 0"
                    class="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold"
                    :class="{
                      'bg-signal-danger/10 text-signal-danger': cc.budget_consumed_pct > 100,
                      'bg-signal-warning/10 text-signal-warning': cc.budget_consumed_pct >= 80 && cc.budget_consumed_pct <= 100,
                      'bg-signal-success/10 text-signal-success': cc.budget_consumed_pct < 80,
                    }"
                  >
                    {{ cc.budget_consumed_pct }}%
                  </span>
                  <span v-else class="text-xs text-ink-400">—</span>
                </td>
                <td class="px-3 py-2.5 text-right font-medium">
                  <span v-if="cc.remaining_budget !== null" :class="cc.remaining_budget < 0 ? 'text-signal-danger font-bold' : 'text-signal-success'">
                    {{ formatCurrency(cc.remaining_budget) }}
                  </span>
                  <span v-else class="text-ink-400">—</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-else class="text-sm text-ink-500 py-4 text-center">No cost center spend data.</div>
      </Panel>
    </div>

    <!-- Master Contract Utilization Rollup (§3H & §3J) -->
    <div class="mt-6">
      <Panel title="Active Master Contracts Rollup & Headroom (§3H / §3J)">
        <div v-if="contract_utilization.length > 0" class="overflow-x-auto">
          <table class="w-full text-left text-sm">
            <thead class="bg-surface-sunken text-xs uppercase text-ink-500 border-b border-border">
              <tr>
                <th class="px-3 py-2">Contract Title</th>
                <th class="px-3 py-2">Supplier</th>
                <th class="px-3 py-2 text-right">Ceiling Value</th>
                <th class="px-3 py-2 text-right">Committed Spend</th>
                <th class="px-3 py-2 text-center">Utilization</th>
                <th class="px-3 py-2 text-right">Remaining Headroom</th>
                <th class="px-3 py-2 text-center">Expiry</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-border">
              <tr v-for="c in contract_utilization" :key="c.id" class="hover:bg-surface-sunken/40">
                <td class="px-3 py-2.5">
                  <Link :href="route('purchase.contracts.show', c.id)" class="font-semibold text-accent hover:underline">
                    {{ c.title }}
                  </Link>
                  <div class="text-xs text-ink-400 capitalize">{{ c.type }} agreement</div>
                </td>
                <td class="px-3 py-2.5 text-ink-800">{{ c.supplier_name }}</td>
                <td class="px-3 py-2.5 text-right font-medium text-ink-900">{{ formatCurrency(c.contract_value) }}</td>
                <td class="px-3 py-2.5 text-right font-semibold text-ink-900">{{ formatCurrency(c.spend_amount) }}</td>
                <td class="px-3 py-2.5 text-center">
                  <div class="inline-flex items-center gap-1.5">
                    <span
                      class="px-2 py-0.5 rounded text-xs font-bold"
                      :class="{
                        'bg-signal-danger/10 text-signal-danger': c.health_status === 'exceeded',
                        'bg-signal-warning/10 text-signal-warning': c.health_status === 'warning',
                        'bg-signal-success/10 text-signal-success': c.health_status === 'normal',
                      }"
                    >
                      {{ c.utilization_pct }}%
                    </span>
                  </div>
                </td>
                <td class="px-3 py-2.5 text-right font-semibold" :class="c.remaining_headroom <= 0 ? 'text-signal-danger' : 'text-ink-800'">
                  {{ formatCurrency(c.remaining_headroom) }}
                </td>
                <td class="px-3 py-2.5 text-center text-xs text-ink-500">{{ c.end_date }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-else class="text-sm text-ink-500 py-4 text-center">No active master contracts with ceilings.</div>
      </Panel>
    </div>

    <!-- Monthly Spend Trend -->
    <div class="mt-6">
      <Panel title="Historical Spend Trend by Month">
        <div v-if="monthly_trend.length > 0" class="overflow-x-auto">
          <table class="w-full text-left text-sm">
            <thead class="bg-surface-sunken text-xs uppercase text-ink-500 border-b border-border">
              <tr>
                <th class="px-3 py-2">Period</th>
                <th class="px-3 py-2 text-center">POs Issued</th>
                <th class="px-3 py-2 text-right">Direct Spend</th>
                <th class="px-3 py-2 text-right">Indirect Spend</th>
                <th class="px-3 py-2 text-right">Total Monthly Spend</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-border">
              <tr v-for="m in monthly_trend" :key="m.period" class="hover:bg-surface-sunken/40">
                <td class="px-3 py-2.5 font-bold font-mono text-ink-900">{{ m.period }}</td>
                <td class="px-3 py-2.5 text-center text-ink-600">{{ m.po_count }}</td>
                <td class="px-3 py-2.5 text-right text-signal-success">{{ formatCurrency(m.direct_spend) }}</td>
                <td class="px-3 py-2.5 text-right text-signal-info">{{ formatCurrency(m.indirect_spend) }}</td>
                <td class="px-3 py-2.5 text-right font-bold text-ink-900">{{ formatCurrency(m.total_spend) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-else class="text-sm text-ink-500 py-4 text-center">No monthly trend data available.</div>
      </Panel>
    </div>
  </AppLayout>
</template>
