<!-- ponytail: Dashboard — DESIGN.md tokens + StatCard / Panel / StatusBadge -->
<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import StatCard from '@/Components/cards/StatCard.vue'
import Panel from '@/Components/cards/Panel.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
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
    <PageHeader title="Dashboard" :description="firm" />

    <div class="mt-6 space-y-6">
      <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <StatCard
          v-for="card in cards"
          :key="card.title"
          :title="card.title"
          :value="card.value"
          :description="card.description"
          :icon="card.icon"
          :href="card.href"
        />
      </div>

      <div class="grid gap-6 md:grid-cols-3">
        <Panel title="Recent activity" subtitle="From this tenant" class="md:col-span-2">
          <div v-if="activities.length === 0" class="py-8 text-center">
            <p class="text-sm font-medium text-ink-900">No recent activity</p>
            <p class="mt-1 text-sm text-ink-600">Open a case or update inventory to see activity here.</p>
          </div>

          <div v-else class="overflow-x-auto">
            <table class="min-w-full divide-y divide-border text-sm">
              <thead>
                <tr class="text-left text-xs font-semibold uppercase tracking-wide text-ink-600">
                  <th class="py-2 pr-3">Module</th>
                  <th class="py-2 pr-3">Action</th>
                  <th class="py-2 pr-3">User</th>
                  <th class="py-2 text-right font-mono text-[11px] normal-case tracking-normal">Time</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-border text-ink-900">
                <tr v-for="act in activities" :key="act.id" class="hover:bg-surface-50">
                  <td class="py-3 pr-3">
                    <StatusBadge :status="act.module === 'Legal' ? 'open' : 'active'" :label="act.module" />
                  </td>
                  <td class="py-3 pr-3">{{ act.action }}</td>
                  <td class="py-3 pr-3 text-ink-600">{{ act.user }}</td>
                  <td class="py-3 text-right font-mono text-xs text-ink-600">{{ act.time }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </Panel>

        <Panel title="Quick actions">
          <div class="grid gap-2">
            <Link
              v-for="s in shortcuts"
              :key="s.href"
              :href="s.href"
              class="flex items-center justify-between rounded-sm border border-border px-3 py-2.5 transition-colors hover:bg-surface-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
            >
              <div class="flex items-center gap-3">
                <component :is="getIcon(s.icon)" class="h-5 w-5 text-ink-600" />
                <span class="text-sm font-medium text-ink-900">{{ s.label }}</span>
              </div>
              <icons.ChevronRight class="h-4 w-4 text-ink-600" />
            </Link>
          </div>
        </Panel>
      </div>
    </div>
  </AppLayout>
</template>
