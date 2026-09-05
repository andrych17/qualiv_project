<!-- ponytail: Dashboard — UI/UX Pro Max Executive Cockpit & Modular App Launcher -->
<script setup lang="ts">
import { ref, computed } from 'vue'
import { Head, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
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

type AppItem = {
  code: string
  title: string
  description: string
  icon: string
  href: string
  badge: string
  badgeColor?: string
  links: Array<{ label: string; href: string }>
}

type AppSection = {
  title: string
  description: string
  apps: AppItem[]
}

const props = defineProps<{
  firm: string
  plan?: string
  cards: Card[]
  appSections?: AppSection[]
  activities: Activity[]
  shortcuts: Shortcut[]
}>()

const searchQuery = ref('')

const filteredAppSections = computed(() => {
  if (!props.appSections) return []
  if (!searchQuery.value.trim()) return props.appSections

  const q = searchQuery.value.toLowerCase().trim()
  return props.appSections
    .map(section => {
      const matchingApps = section.apps.filter(app =>
        app.title.toLowerCase().includes(q) ||
        app.description.toLowerCase().includes(q) ||
        app.links.some(l => l.label.toLowerCase().includes(q))
      )
      return {
        ...section,
        apps: matchingApps,
      }
    })
    .filter(section => section.apps.length > 0)
})

const getIcon = (name: string) => {
  return (icons as Record<string, unknown>)[name] as typeof icons.HelpCircle || icons.HelpCircle
}

const getBadgeClasses = (color?: string) => {
  switch (color) {
    case 'indigo':
      return 'bg-indigo-50 text-indigo-700 ring-1 ring-inset ring-indigo-600/20'
    case 'purple':
      return 'bg-purple-50 text-purple-700 ring-1 ring-inset ring-purple-600/20'
    case 'blue':
      return 'bg-blue-50 text-blue-700 ring-1 ring-inset ring-blue-600/20'
    case 'sky':
      return 'bg-sky-50 text-sky-700 ring-1 ring-inset ring-sky-600/20'
    case 'emerald':
      return 'bg-emerald-50 text-emerald-700 ring-1 ring-inset ring-emerald-600/20'
    case 'amber':
      return 'bg-amber-50 text-amber-700 ring-1 ring-inset ring-amber-600/20'
    case 'teal':
      return 'bg-teal-50 text-teal-700 ring-1 ring-inset ring-teal-600/20'
    case 'cyan':
      return 'bg-cyan-50 text-cyan-700 ring-1 ring-inset ring-cyan-600/20'
    case 'rose':
      return 'bg-rose-50 text-rose-700 ring-1 ring-inset ring-rose-600/20'
    case 'violet':
      return 'bg-violet-50 text-violet-700 ring-1 ring-inset ring-violet-600/20'
    case 'orange':
      return 'bg-orange-50 text-orange-700 ring-1 ring-inset ring-orange-600/20'
    default:
      return 'bg-surface-100 text-ink-700 ring-1 ring-inset ring-border'
  }
}

const getIconBgClasses = (color?: string) => {
  switch (color) {
    case 'indigo':
      return 'bg-indigo-500/10 text-indigo-600 group-hover:bg-indigo-600 group-hover:text-white'
    case 'purple':
      return 'bg-purple-500/10 text-purple-600 group-hover:bg-purple-600 group-hover:text-white'
    case 'blue':
      return 'bg-blue-500/10 text-blue-600 group-hover:bg-blue-600 group-hover:text-white'
    case 'sky':
      return 'bg-sky-500/10 text-sky-600 group-hover:bg-sky-600 group-hover:text-white'
    case 'emerald':
      return 'bg-emerald-500/10 text-emerald-600 group-hover:bg-emerald-600 group-hover:text-white'
    case 'amber':
      return 'bg-amber-500/10 text-amber-600 group-hover:bg-amber-600 group-hover:text-white'
    case 'teal':
      return 'bg-teal-500/10 text-teal-600 group-hover:bg-teal-600 group-hover:text-white'
    case 'cyan':
      return 'bg-cyan-500/10 text-cyan-600 group-hover:bg-cyan-600 group-hover:text-white'
    case 'rose':
      return 'bg-rose-500/10 text-rose-600 group-hover:bg-rose-600 group-hover:text-white'
    case 'violet':
      return 'bg-violet-500/10 text-violet-600 group-hover:bg-violet-600 group-hover:text-white'
    case 'orange':
      return 'bg-orange-500/10 text-orange-600 group-hover:bg-orange-600 group-hover:text-white'
    default:
      return 'bg-surface-100 text-ink-700 group-hover:bg-ink-900 group-hover:text-surface-0'
  }
}
</script>

<template>
  <Head title="Dashboard" />

  <AppLayout>
    <!-- Executive Workspace Banner -->
    <div class="rounded-xl border border-border bg-gradient-to-r from-surface-0 via-surface-50 to-surface-0 p-6 shadow-xs sm:p-8">
      <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
        <div>
          <div class="flex items-center gap-2">
            <h1 class="text-2xl font-bold tracking-tight text-ink-900 sm:text-3xl">
              {{ firm }}
            </h1>
            <span class="inline-flex items-center rounded-full bg-indigo-50 px-2.5 py-0.5 text-xs font-semibold text-indigo-700 ring-1 ring-inset ring-indigo-600/20">
              {{ (plan || 'Enterprise').toUpperCase() }} SUITE
            </span>
          </div>
          <p class="mt-1 text-sm text-ink-600">
            Pusat operasional manajemen klien, pengadaan vendor AI & server, finansial, dan sprint project.
          </p>
        </div>

        <!-- Quick Module Search Filter -->
        <div class="relative w-full max-w-xs">
          <icons.Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-ink-400" />
          <input
            v-model="searchQuery"
            type="text"
            placeholder="Cari modul atau menu…"
            class="w-full rounded-lg border border-border bg-surface-0 py-2 pl-9 pr-3 text-sm text-ink-900 placeholder-ink-400 shadow-xs transition-colors focus:border-accent focus:outline-hidden focus:ring-1 focus:ring-accent"
          />
        </div>
      </div>
    </div>

    <div class="mt-6 space-y-8">
      <!-- Top Bento KPI Summary Cards -->
      <div>
        <div class="mb-3 flex items-center justify-between">
          <h2 class="text-xs font-bold uppercase tracking-wider text-ink-500">Ringkasan Operasional & Finansial</h2>
        </div>
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <component
            :is="card.href ? Link : 'div'"
            v-for="card in cards"
            :key="card.title"
            :href="card.href || undefined"
            class="group relative flex flex-col justify-between overflow-hidden rounded-xl border border-border bg-surface-0 p-5 shadow-xs transition-all duration-200 hover:-translate-y-0.5 hover:border-accent/40 hover:shadow-md"
            :class="card.href ? 'cursor-pointer' : ''"
          >
            <div class="flex items-start justify-between">
              <div>
                <p class="text-xs font-medium text-ink-500">{{ card.title }}</p>
                <p class="mt-1.5 font-serif text-3xl font-bold tracking-tight text-ink-900">{{ card.value }}</p>
              </div>
              <div class="flex h-11 w-11 items-center justify-center rounded-lg border border-border/80 bg-surface-50 text-ink-600 transition-colors group-hover:bg-accent group-hover:text-white">
                <component :is="getIcon(card.icon)" class="h-5 w-5" />
              </div>
            </div>
            <div class="mt-3 flex items-center justify-between border-t border-border/60 pt-3">
              <span class="truncate text-xs text-ink-500">{{ card.description }}</span>
              <span v-if="card.href" class="inline-flex items-center text-xs font-semibold text-accent group-hover:underline">
                Buka
                <icons.ArrowRight class="ml-1 h-3 w-3" />
              </span>
            </div>
          </component>
        </div>
      </div>

      <!-- App Launcher & Modular Cards Suite -->
      <div v-if="filteredAppSections.length > 0" class="space-y-8">
        <div v-for="section in filteredAppSections" :key="section.title" class="space-y-3">
          <div class="border-b border-border/70 pb-2">
            <h2 class="text-sm font-bold text-ink-900">{{ section.title }}</h2>
            <p class="text-xs text-ink-500">{{ section.description }}</p>
          </div>

          <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <div
              v-for="app in section.apps"
              :key="app.code"
              class="group relative flex flex-col justify-between rounded-xl border border-border bg-surface-0 p-5 shadow-xs transition-all duration-200 hover:border-accent/50 hover:shadow-md"
            >
              <div>
                <!-- Card Header -->
                <div class="flex items-start justify-between gap-3">
                  <div class="flex items-center gap-3">
                    <div
                      class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg transition-colors"
                      :class="getIconBgClasses(app.badgeColor)"
                    >
                      <component :is="getIcon(app.icon)" class="h-5 w-5" />
                    </div>
                    <div>
                      <Link :href="app.href" class="text-base font-semibold text-ink-900 group-hover:text-accent focus:outline-hidden">
                        {{ app.title }}
                      </Link>
                    </div>
                  </div>
                  <span
                    class="inline-flex shrink-0 items-center rounded-full px-2 py-0.5 text-[11px] font-medium"
                    :class="getBadgeClasses(app.badgeColor)"
                  >
                    {{ app.badge }}
                  </span>
                </div>

                <!-- Description -->
                <p class="mt-3 text-xs leading-relaxed text-ink-600">
                  {{ app.description }}
                </p>
              </div>

              <!-- Quick Links Footer -->
              <div class="mt-4 border-t border-border/70 pt-3">
                <div class="flex flex-wrap items-center gap-1.5">
                  <span class="text-[11px] font-medium text-ink-400">Pintas:</span>
                  <Link
                    v-for="link in app.links"
                    :key="link.href"
                    :href="link.href"
                    class="inline-flex items-center rounded-md bg-surface-50 px-2 py-1 text-xs font-medium text-ink-700 transition-colors hover:bg-surface-150 hover:text-ink-900"
                  >
                    {{ link.label }}
                  </Link>
                  <Link
                    :href="app.href"
                    class="ml-auto inline-flex items-center text-xs font-semibold text-accent hover:underline"
                  >
                    Masuk
                    <icons.ChevronRight class="ml-0.5 h-3.5 w-3.5" />
                  </Link>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Bottom Layout: Recent Activities & Fast Actions -->
      <div class="grid gap-6 lg:grid-cols-3">
        <!-- Recent Activities Feed -->
        <Panel title="Aktivitas & Transaksi Terkini" subtitle="Log perubahan real-time tenant Qualiv" class="lg:col-span-2">
          <div v-if="activities.length === 0" class="py-12 text-center">
            <icons.Inbox class="mx-auto h-8 w-8 text-ink-400" />
            <p class="mt-2 text-sm font-medium text-ink-900">Belum ada aktivitas terbaru</p>
            <p class="text-xs text-ink-500">Transaksi tagihan atau task project akan muncul di sini.</p>
          </div>

          <div v-else class="overflow-x-auto">
            <table class="min-w-full divide-y divide-border text-sm">
              <thead>
                <tr class="text-left text-xs font-semibold uppercase tracking-wide text-ink-500">
                  <th class="py-2.5 pr-3">Modul</th>
                  <th class="py-2.5 pr-3">Rincian Aktivitas</th>
                  <th class="py-2.5 pr-3">Pelaksana</th>
                  <th class="py-2.5 text-right font-mono text-[11px] normal-case tracking-normal">Waktu</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-border text-ink-900">
                <tr v-for="act in activities" :key="act.id" class="transition-colors hover:bg-surface-50">
                  <td class="py-3 pr-3">
                    <StatusBadge
                      :status="act.module === 'Accounting' ? 'active' : (act.module === 'Projects' ? 'open' : 'info')"
                      :label="act.module"
                    />
                  </td>
                  <td class="py-3 pr-3 text-xs font-medium text-ink-900">{{ act.action }}</td>
                  <td class="py-3 pr-3 text-xs text-ink-500">{{ act.user }}</td>
                  <td class="py-3 text-right font-mono text-xs text-ink-500">{{ act.time }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </Panel>

        <!-- Fast Actions -->
        <Panel title="Aksi Cepat (Quick Actions)" subtitle="Akses langsung transaksi umum">
          <div class="grid gap-2.5">
            <Link
              v-for="s in shortcuts"
              :key="s.href"
              :href="s.href"
              class="group flex items-center justify-between rounded-lg border border-border bg-surface-0 px-3.5 py-3 shadow-2xs transition-all hover:border-accent/40 hover:bg-surface-50 hover:shadow-xs focus-visible:outline focus-visible:outline-2 focus-visible:outline-accent"
            >
              <div class="flex items-center gap-3">
                <div class="flex h-8 w-8 items-center justify-center rounded-md bg-surface-100 text-ink-600 group-hover:bg-accent group-hover:text-white transition-colors">
                  <component :is="getIcon(s.icon)" class="h-4 w-4" />
                </div>
                <span class="text-sm font-medium text-ink-900 group-hover:text-accent">{{ s.label }}</span>
              </div>
              <icons.ChevronRight class="h-4 w-4 text-ink-400 group-hover:translate-x-0.5 transition-transform group-hover:text-accent" />
            </Link>
          </div>
        </Panel>
      </div>
    </div>
  </AppLayout>
</template>
