<!-- ponytail: POS Reports & Operational Dashboard (POS_SPECS.md §3U) -->
<script setup lang="ts">
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import StatCard from '@/Components/cards/StatCard.vue'
import { formatCurrency, formatNumber } from '@/Utils/formatters'
import { TrendingUp, ShoppingBag, Receipt, Utensils } from 'lucide-vue-next'

interface Metrics {
  today_sales: number
  transaction_count: number
  avg_ticket: number
  occupied_tables: number
  total_tables: number
}

interface PaymentMix {
  method: string
  total: number
}

interface TopProduct {
  description: string
  total_qty: number
  total_amount: number
}

const props = defineProps<{
  metrics: Metrics
  payments: PaymentMix[]
  top_products: TopProduct[]
}>()
</script>

<template>
  <AppLayout>
    <PageHeader
      title="Laporan & Ringkasan Penjualan Kasir"
      description="Metrik performa operasional kasir harian, bauran metode pembayaran, dan produk terlaris."
    />

    <!-- Metric StatCards -->
    <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
      <StatCard
        title="Penjualan Hari Ini"
        :value="formatCurrency(metrics.today_sales)"
        icon="TrendingUp"
        description="Total omset bersih transaksi selesai"
      />
      <StatCard
        title="Total Transaksi"
        :value="formatNumber(metrics.transaction_count)"
        icon="Receipt"
        description="Struk kasir selesai hari ini"
      />
      <StatCard
        title="Rata-rata Struk (Basket)"
        :value="formatCurrency(metrics.avg_ticket)"
        icon="ShoppingBag"
        description="Nilai transaksi rata-rata per pelanggan"
      />
      <StatCard
        title="Okupansi Meja"
        :value="`${metrics.occupied_tables} / ${metrics.total_tables}`"
        icon="Utensils"
        description="Meja aktif terisi vs total kapasitas"
      />
    </div>

    <!-- Data Panels -->
    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
      <!-- Payment Mix -->
      <Panel title="Bauran Metode Pembayaran" subtitle="Rincian penerimaan kas berdasarkan metode pembayaran hari ini">
        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-surface-200">
            <thead class="bg-surface-50 text-left text-xs font-semibold text-ink-700">
              <tr>
                <th class="py-3 pl-4 pr-3">Metode</th>
                <th class="py-3 pl-3 pr-4 text-right">Total Nominal</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-surface-200 bg-white text-sm">
              <tr v-for="p in payments" :key="p.method" class="hover:bg-surface-50/60">
                <td class="py-3 pl-4 pr-3 font-semibold uppercase text-ink-900">
                  {{ p.method }}
                </td>
                <td class="py-3 pl-3 pr-4 text-right font-mono font-bold text-primary-700">
                  {{ formatCurrency(p.total) }}
                </td>
              </tr>
              <tr v-if="!payments || payments.length === 0">
                <td colspan="2" class="py-6 text-center text-xs text-ink-400">
                  Belum ada pembayaran yang masuk hari ini.
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </Panel>

      <!-- Top Selling Products -->
      <Panel title="10 Produk Terlaris Hari Ini" subtitle="Item dengan kuantitas dan nominal penjualan tertinggi">
        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-surface-200">
            <thead class="bg-surface-50 text-left text-xs font-semibold text-ink-700">
              <tr>
                <th class="py-3 pl-4 pr-3">Nama Produk</th>
                <th class="px-3 py-3 text-right">Qty Terjual</th>
                <th class="py-3 pl-3 pr-4 text-right">Total Penjualan</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-surface-200 bg-white text-sm">
              <tr v-for="item in top_products" :key="item.description" class="hover:bg-surface-50/60">
                <td class="py-3 pl-4 pr-3 font-medium text-ink-900">
                  {{ item.description }}
                </td>
                <td class="px-3 py-3 text-right font-mono font-semibold text-ink-800">
                  {{ formatNumber(item.total_qty) }}
                </td>
                <td class="py-3 pl-3 pr-4 text-right font-mono font-bold text-primary-700">
                  {{ formatCurrency(item.total_amount) }}
                </td>
              </tr>
              <tr v-if="!top_products || top_products.length === 0">
                <td colspan="3" class="py-6 text-center text-xs text-ink-400">
                  Belum ada transaksi produk hari ini.
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </Panel>
    </div>
  </AppLayout>
</template>
