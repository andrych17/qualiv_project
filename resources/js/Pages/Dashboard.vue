<!-- ponytail: Dashboard from live tenant stats (Inventory + Legal + plan modules) -->
<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import * as icons from 'lucide-vue-next'

type Card = {
  title: string
  value: string
  description: string
  icon: string
  href: string | null
}

type Activity = {
  id: string
  module: string
  action: string
  user: string
  time: string
}

type Shortcut = {
  label: string
  href: string
  icon: string
}

defineProps<{
  firm: string
  cards: Card[]
  activities: Activity[]
  shortcuts: Shortcut[]
}>()

const getIcon = (name: string) => {
  return (icons as Record<string, unknown>)[name] as typeof icons.HelpCircle || icons.HelpCircle
}
</script>

<template>
  <Head title="Dashboard" />

  <AppLayout>
    <PageHeader
      title="Dashboard"
      :description="firm"
    />

    <div class="mt-6 space-y-6">
      <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <component
          :is="card.href ? Link : 'div'"
          v-for="card in cards"
          :key="card.title"
          :href="card.href || undefined"
          class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm flex items-center justify-between"
          :class="card.href ? 'hover:bg-gray-50 transition-colors' : ''"
        >
          <div class="space-y-1">
            <p class="text-sm font-medium text-gray-500">{{ card.title }}</p>
            <p class="text-3xl font-semibold text-gray-900">{{ card.value }}</p>
            <p class="text-xs text-gray-400">{{ card.description }}</p>
          </div>
          <div class="h-12 w-12 rounded-lg bg-gray-50 border border-gray-100 flex items-center justify-center text-gray-600">
            <component :is="getIcon(card.icon)" class="h-6 w-6" />
          </div>
        </component>
      </div>

      <div class="grid gap-6 md:grid-cols-3">
        <div class="md:col-span-2 rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
          <div class="flex items-center justify-between border-b border-gray-100 pb-4 mb-4">
            <h2 class="text-lg font-semibold text-gray-900">Recent Activities</h2>
            <span class="text-xs text-gray-400">From this tenant</span>
          </div>

          <div v-if="activities.length === 0" class="py-8 text-center text-sm text-gray-400">
            No recent inventory or case activity yet.
          </div>

          <div v-else class="overflow-x-auto">
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
                <tr v-for="act in activities" :key="act.id" class="hover:bg-gray-50/55 transition-colors">
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

        <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
          <div class="flex items-center justify-between border-b border-gray-100 pb-4 mb-4">
            <h2 class="text-lg font-semibold text-gray-900">Quick Actions</h2>
          </div>

          <div class="grid gap-3">
            <Link
              v-for="s in shortcuts"
              :key="s.href"
              :href="s.href"
              class="flex items-center justify-between rounded-lg border border-gray-100 p-3 hover:bg-gray-50 transition-colors"
            >
              <div class="flex items-center gap-3">
                <component :is="getIcon(s.icon)" class="h-5 w-5 text-gray-600" />
                <span class="text-sm font-medium text-gray-700">{{ s.label }}</span>
              </div>
              <icons.ChevronRight class="h-4 w-4 text-gray-400" />
            </Link>
          </div>
        </div>
      </div>
    </div>
  </AppLayout>
</template>
