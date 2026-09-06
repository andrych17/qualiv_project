<!-- ponytail: Dashboard — Executive KPI summary, Adaptive Dynamic Modular Launcher from SysConfig, and real-time activity feed -->
<script setup lang="ts">
import { ref, computed } from 'vue'
import { Head, Link, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import Panel from '@/Components/cards/Panel.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import DataTable from '@/Components/tables/DataTable.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import EmptyState from '@/Components/feedback/EmptyState.vue'
import { useI18n } from '@/Composables/useI18n'
import * as icons from 'lucide-vue-next'

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

type NavMenuChild = {
  code: string
  label: string
  href: string
  icon: string | null
  seq: number
  children?: NavMenuChild[]
}

type NavMenuItem = {
  code: string
  label: string
  href: string
  icon: string | null
  header: string | null
  seq: number
  children?: NavMenuChild[]
}

defineProps<{
  firm: string
  plan?: string
  cards?: unknown[]
  totalModules?: number
  activities: Activity[]
  shortcuts: Shortcut[]
}>()

const page = usePage()
const { t } = useI18n()
const searchQuery = ref('')
const selectedCategory = ref<string>('ALL')

const activityColumns = computed(() => [
  { key: 'module', label: t('dashboard.col_module') },
  { key: 'action', label: t('dashboard.col_action') },
  { key: 'user', label: t('dashboard.col_user') },
  { key: 'time', label: t('dashboard.col_time'), align: 'right' as const },
])

const menuLabel = (code: string, fallback: string) => {
  const key = `menu.${code}`
  const translated = t(key)
  return translated !== key ? translated : fallback
}

const sectionHeader = (header: string) => {
  const map: Record<string, string> = {
    Main: 'nav.main',
    Core: 'nav.core',
    Operations: 'nav.operations',
    People: 'nav.people',
    System: 'nav.system',
    Vertical: 'nav.vertical',
  }
  const key = map[header] || `nav.${header.toLowerCase()}`
  const translated = t(key)
  return translated !== key ? translated : header
}

const getIcon = (name: string | null | undefined) => {
  if (!name) return icons.LayoutGrid
  const iconDict = icons as Record<string, unknown>
  if (iconDict[name]) return iconDict[name] as typeof icons.HelpCircle

  const pascal = name
    .split(/[-_]/)
    .map((s) => s.charAt(0).toUpperCase() + s.slice(1).toLowerCase())
    .join('')
  return (iconDict[pascal] as typeof icons.HelpCircle) || icons.LayoutGrid
}

const moduleStatus = (module: string) => {
  switch (module) {
    case 'Accounting':
      return 'active'
    case 'Projects':
      return 'open'
    case 'CRM':
      return 'info'
    default:
      return 'draft'
  }
}

/**
 * 100% Dynamic Module Resolution from PostgreSQL SYSCONFIG.config_menus via navMenus.
 * Respects tenant plan entitlement, role-based access permissions, and custom menu ordering.
 */
const dynamicModules = computed(() => {
  const rawMenus = (page.props.navMenus || []) as NavMenuItem[]

  return rawMenus
    .filter(m => m.code !== 'DASHBOARD')
    .map(m => {
      // Flatten direct submenus (L2 and L3) to produce actionable quick deep-links
      const quickLinks: Array<{ label: string; href: string }> = []

      if (m.children && m.children.length > 0) {
        for (const child of m.children) {
          if (child.href && child.href !== '#' && child.href !== m.href) {
            quickLinks.push({ label: menuLabel(child.code, child.label), href: child.href })
          } else if (child.children && child.children.length > 0) {
            for (const subChild of child.children) {
              if (subChild.href && subChild.href !== '#') {
                quickLinks.push({ label: menuLabel(subChild.code, subChild.label), href: subChild.href })
              }
            }
          }
        }
      }

      return {
        code: m.code,
        name: menuLabel(m.code, m.label),
        href: m.href || '#',
        icon: m.icon,
        category: sectionHeader(m.header || 'Main'),
        quickLinks: quickLinks.slice(0, 3), // Top 3 quick shortcuts
      }
    })
})

/**
 * Dynamically discover unique category groups from database headers.
 */
const availableCategories = computed(() => {
  const map = new Map<string, number>()

  for (const mod of dynamicModules.value) {
    const cat = mod.category
    map.set(cat, (map.get(cat) || 0) + 1)
  }

  return Array.from(map.entries()).map(([name, count]) => ({
    name,
    count,
  }))
})

/**
 * Filtered and Clustered Module Groups based on active category & instant search query.
 */
const dynamicClusters = computed(() => {
  const query = searchQuery.value.trim().toLowerCase()

  const filtered = dynamicModules.value.filter(mod => {
    if (selectedCategory.value !== 'ALL' && mod.category !== selectedCategory.value) {
      return false
    }

    if (!query) return true

    return (
      mod.name.toLowerCase().includes(query) ||
      mod.code.toLowerCase().includes(query) ||
      mod.category.toLowerCase().includes(query) ||
      mod.quickLinks.some(ql => ql.label.toLowerCase().includes(query))
    )
  })

  // Group by category header
  const groups: Record<string, typeof filtered> = {}
  for (const mod of filtered) {
    if (!groups[mod.category]) {
      groups[mod.category] = []
    }
    groups[mod.category].push(mod)
  }

  return Object.entries(groups).map(([name, items]) => ({
    name,
    modules: items,
  }))
})

const isSingleOrFewModules = computed(() => {
  return dynamicModules.value.length <= 4
})
</script>

<template>
  <Head title="Dashboard" />

  <AppLayout>
    <!-- Executive Hero Banner -->
    <div class="relative overflow-hidden rounded-xl border border-border bg-surface-0 p-6 shadow-xs sm:p-8">
      <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <div class="flex flex-wrap items-center gap-2.5">
            <h1 class="text-2xl font-bold tracking-tight text-ink-900 sm:text-3xl">
              {{ firm }}
            </h1>
            <span class="inline-flex items-center rounded-full border border-border bg-surface-50 px-2.5 py-0.5 text-xs font-semibold text-ink-600">
              {{ (plan || 'Enterprise').toUpperCase() }} {{ t('dashboard.suite') }}
            </span>
            <span class="inline-flex items-center rounded-full border border-accent/20 bg-accent/5 px-2.5 py-0.5 text-xs font-semibold text-accent">
              {{ t('dashboard.modules_installed', { count: dynamicModules.length }) }}
            </span>
          </div>
          <p class="mt-1.5 text-sm text-ink-600">
            {{ t('dashboard.subtitle') }}
          </p>
        </div>

        <!-- Quick Search Hint / Global Action Badge -->
        <div class="flex items-center gap-2 self-start rounded-lg border border-border bg-surface-50 px-3 py-2 text-xs text-ink-600 sm:self-auto">
          <icons.Command class="h-4 w-4 text-accent shrink-0" />
          <span>{{ t('dashboard.nav_shortcut') }}</span>
          <kbd class="rounded border border-border bg-surface-0 px-1.5 py-0.5 font-mono text-[11px] font-semibold text-ink-900 shadow-2xs">Ctrl</kbd>
          <span>+</span>
          <kbd class="rounded border border-border bg-surface-0 px-1.5 py-0.5 font-mono text-[11px] font-semibold text-ink-900 shadow-2xs">Space</kbd>
        </div>
      </div>
    </div>

    <div class="mt-6 space-y-8">
      <!-- Dynamic Modular App Launcher (Clustered from Database SYSCONFIG) -->
      <div v-if="dynamicModules.length > 0" class="space-y-4">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <h2 class="text-xs font-bold uppercase tracking-wider text-ink-500">
              {{ t('dashboard.catalog_title') }}
            </h2>
            <p class="text-xs text-ink-600 mt-0.5">
              {{ t('dashboard.catalog_subtitle') }}
            </p>
          </div>

          <!-- Search Filter Bar -->
          <div class="w-full sm:w-72">
            <FormInput
              v-model="searchQuery"
              name="module-search"
              :placeholder="t('dashboard.search_placeholder')"
            />
          </div>
        </div>

        <!-- Dynamic Category Filter Chips (Hidden when in few-modules mode) -->
        <div v-if="!isSingleOrFewModules && availableCategories.length > 1" class="flex items-center gap-1.5 overflow-x-auto pb-1 text-xs">
          <button
            type="button"
            class="rounded-lg px-3 py-1.5 font-medium transition-colors shrink-0"
            :class="selectedCategory === 'ALL' ? 'bg-accent text-accent-text shadow-xs' : 'bg-surface-0 border border-border text-ink-700 hover:bg-surface-100'"
            @click="selectedCategory = 'ALL'"
          >
            {{ t('dashboard.all_modules') }} ({{ dynamicModules.length }})
          </button>
          <button
            v-for="c in availableCategories"
            :key="c.name"
            type="button"
            class="rounded-lg px-3 py-1.5 font-medium transition-colors shrink-0"
            :class="selectedCategory === c.name ? 'bg-accent text-accent-text shadow-xs' : 'bg-surface-0 border border-border text-ink-700 hover:bg-surface-100'"
            @click="selectedCategory = c.name"
          >
            {{ c.name }} ({{ c.count }})
          </button>
        </div>

        <!-- Clustered Grid Content -->
        <div v-if="dynamicClusters.length > 0" class="space-y-6">
          <div
            v-for="cluster in dynamicClusters"
            :key="cluster.name"
            class="space-y-3"
          >
            <div class="flex items-center gap-2">
              <span class="h-1.5 w-1.5 rounded-full bg-accent"></span>
              <h3 class="text-xs font-bold uppercase tracking-wider text-ink-600">
                {{ cluster.name }}
              </h3>
              <span class="text-[11px] font-medium text-ink-500">
                ({{ cluster.modules.length }})
              </span>
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-3">
              <div
                v-for="mod in cluster.modules"
                :key="mod.code"
                class="group relative flex flex-col justify-between rounded-xl border border-border bg-surface-0 p-4 shadow-2xs transition-all duration-150 hover:border-accent/40 hover:bg-surface-50 hover:shadow-xs"
              >
                <!-- Card Top: Icon, Name & Direct Jump -->
                <div>
                  <div class="flex items-start justify-between gap-3">
                    <div class="flex items-center gap-3">
                      <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-surface-100 text-ink-700 shadow-2xs transition-colors duration-150 group-hover:bg-accent group-hover:text-accent-text">
                        <component :is="getIcon(mod.icon)" class="h-5 w-5" />
                      </div>
                      <div>
                        <Link
                          :href="mod.href"
                          class="font-semibold text-ink-900 group-hover:text-accent transition-colors flex items-center gap-1.5"
                        >
                          <span>{{ mod.name }}</span>
                        </Link>
                        <span class="font-mono text-[10px] uppercase tracking-wider text-ink-500">
                          {{ mod.code }}
                        </span>
                      </div>
                    </div>

                    <Link
                      :href="mod.href"
                      class="text-ink-400 group-hover:text-accent transition-all group-hover:translate-x-0.5 group-hover:-translate-y-0.5 p-1"
                      :aria-label="t('dashboard.open_module', { name: mod.name })"
                    >
                      <icons.ArrowUpRight class="h-4 w-4" />
                    </Link>
                  </div>
                </div>

                <!-- Card Bottom: Quick Sub-Action Links -->
                <div v-if="mod.quickLinks && mod.quickLinks.length > 0" class="mt-3.5 pt-2.5 border-t border-border flex items-center gap-1.5 flex-wrap">
                  <span class="text-[10px] uppercase font-semibold text-ink-500 mr-1">{{ t('dashboard.actions') }}</span>
                  <Link
                    v-for="ql in mod.quickLinks"
                    :key="ql.href"
                    :href="ql.href"
                    class="inline-flex items-center gap-1 rounded bg-surface-100 hover:bg-surface-200 px-2 py-0.5 text-[11px] font-medium text-ink-700 hover:text-accent transition-colors"
                  >
                    <span>{{ ql.label }}</span>
                  </Link>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Empty State for Filter Search -->
        <EmptyState
          v-else
          :title="t('dashboard.empty_modules_title')"
          :description="t('dashboard.empty_modules_desc')"
          :action-label="t('common.reset_filter')"
          @action="searchQuery = ''; selectedCategory = 'ALL'"
        />
      </div>

      <!-- Activity Feed & Quick Actions (Bottom Section) -->
      <div class="grid gap-6 lg:grid-cols-3">
        <Panel
          :title="t('dashboard.recent_activities')"
          :subtitle="t('dashboard.recent_activities_sub')"
          class="lg:col-span-2"
        >
          <DataTable
            :columns="activityColumns"
            :items="activities"
            row-key="id"
            :empty-title="t('dashboard.no_activities_title')"
            :empty-description="t('dashboard.no_activities_desc')"
          >
            <template #cell-module="{ item }">
              <StatusBadge :status="moduleStatus(item.module)" :label="item.module" />
            </template>
            <template #cell-time="{ item }">
              <span class="font-mono text-xs text-ink-500">{{ item.time }}</span>
            </template>
          </DataTable>
        </Panel>

        <Panel :title="t('dashboard.quick_actions')" :subtitle="t('dashboard.quick_actions_sub')">
          <div class="grid gap-2.5">
            <Link
              v-for="s in shortcuts"
              :key="s.href"
              :href="s.href"
              class="group flex items-center justify-between rounded-lg border border-border bg-surface-0 px-3.5 py-3 shadow-2xs transition-all hover:border-accent/40 hover:bg-surface-50 hover:shadow-xs focus-visible:outline focus-visible:outline-2 focus-visible:outline-accent"
            >
              <div class="flex items-center gap-3">
                <div class="flex h-8 w-8 items-center justify-center rounded-md bg-surface-100 text-ink-600 transition-colors group-hover:bg-accent group-hover:text-accent-text">
                  <component :is="getIcon(s.icon)" class="h-4 w-4" />
                </div>
                <span class="text-sm font-medium text-ink-900 group-hover:text-accent">{{ s.label }}</span>
              </div>
              <icons.ChevronRight class="h-4 w-4 text-ink-400 transition-transform group-hover:translate-x-0.5 group-hover:text-accent" />
            </Link>
          </div>
        </Panel>
      </div>
    </div>
  </AppLayout>
</template>
