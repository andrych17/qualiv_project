<!-- ponytail: Clean ERP Dashboard with simple cards, recent activity logs, and dynamic Lucide icons -->
<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import * as icons from 'lucide-vue-next'

const summaryCards = [
  { title: 'Total Inventory Items', value: '1,248', description: '+12% from last month', icon: 'Boxes' },
  { title: 'Low Stock Items', value: '18', description: 'Need attention', icon: 'TriangleAlert' },
  { title: 'Active Modules', value: '17', description: 'System modules ready', icon: 'LayoutGrid' },
  { title: 'Pending Approvals', value: '9', description: 'Waiting for review', icon: 'Clock' },
]

const recentActivities = [
  { id: 1, module: 'Inventory', action: 'Created item RAW-001', user: 'Admin User', time: '5 minutes ago' },
  { id: 2, module: 'Sales', action: 'Updated sales order SO-2026-001', user: 'Sales User', time: '20 minutes ago' },
  { id: 3, module: 'Accounting', action: 'Posted journal entry JE-1001', user: 'Finance User', time: '1 hour ago' },
  { id: 4, module: 'Workflow', action: 'Approved purchase request PR-5501', user: 'Manager User', time: '2 hours ago' },
]

const getIcon = (name: string) => {
  return (icons as Record<string, any>)[name] || icons.HelpCircle
}
</script>

<template>
  <Head title="Dashboard" />

  <AppLayout>
    <PageHeader 
      title="Dashboard" 
      description="Welcome to NusaEvo ERP dashboard overview."
    />

    <div class="mt-6 space-y-6">
      <!-- Summary Cards Grid -->
      <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div 
          v-for="card in summaryCards" 
          :key="card.title" 
          class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm flex items-center justify-between"
        >
          <div class="space-y-1">
            <p class="text-sm font-medium text-gray-500">{{ card.title }}</p>
            <p class="text-3xl font-semibold text-gray-900">{{ card.value }}</p>
            <p class="text-xs text-gray-400">{{ card.description }}</p>
          </div>
          <div class="h-12 w-12 rounded-lg bg-gray-50 border border-gray-100 flex items-center justify-center text-gray-600">
            <component :is="getIcon(card.icon)" class="h-6 w-6" />
          </div>
        </div>
      </div>

      <!-- Recent Activities and Module Shortcuts -->
      <div class="grid gap-6 md:grid-cols-3">
        <!-- Recent Activities Table -->
        <div class="md:col-span-2 rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
          <div class="flex items-center justify-between border-b border-gray-100 pb-4 mb-4">
            <h2 class="text-lg font-semibold text-gray-900">Recent Activities</h2>
            <span class="text-xs text-gray-400">Live logs</span>
          </div>
          
          <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-100 text-sm">
              <thead>
                <tr class="text-left text-xs font-semibold text-gray-400 uppercase tracking-wider">
                  <th class="py-2">Module</th>
                  <th class="py-2">Action</th>
                  <th class="py-2">User</th>
                  <th class="py-2 text-right">Time</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-gray-50 text-gray-700">
                <tr v-for="act in recentActivities" :key="act.id" class="hover:bg-gray-50/55 transition-colors">
                  <td class="py-3 font-medium">
                    <span class="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-800">
                      {{ act.module }}
                    </span>
                  </td>
                  <td class="py-3">{{ act.action }}</td>
                  <td class="py-3 text-gray-500">{{ act.user }}</td>
                  <td class="py-3 text-right text-gray-400">{{ act.time }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Quick Access Shortcuts -->
        <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
          <div class="flex items-center justify-between border-b border-gray-100 pb-4 mb-4">
            <h2 class="text-lg font-semibold text-gray-900">Quick Actions</h2>
          </div>
          
          <div class="grid gap-3">
            <Link 
              :href="route('inventory.items.index')"
              class="flex items-center justify-between rounded-lg border border-gray-100 p-3 hover:bg-gray-50 transition-colors"
            >
              <div class="flex items-center gap-3">
                <icons.Boxes class="h-5 w-5 text-gray-600" />
                <span class="text-sm font-medium text-gray-700">Manage Inventory</span>
              </div>
              <icons.ChevronRight class="h-4 w-4 text-gray-400" />
            </Link>
          </div>
        </div>
      </div>
    </div>
  </AppLayout>
</template>
