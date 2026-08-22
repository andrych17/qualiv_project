<!-- ponytail: §3A Main Calendar Dashboard — Day/Week/Month/Agenda toggle, Tasks and Events
     together, Status Rail per DESIGN.md. No shared Drawer/StatusRail component exists in
     this codebase (confirmed against CRM/WNE/Central's own dashboards) — the side panel
     below is the same hand-rolled fixed-overlay pattern those pages use, fetched via plain
     `fetch(route(...))` returning JSON, not an Inertia visit. Week/Day views list items per
     day rather than an hour-positioned grid — the spec's MVP text only asks for "click a
     time slot → inline mini-form", not drag/resize (that's explicitly Future Version). -->
<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import ScheduleSubNav from '@/Components/schedule/ScheduleSubNav.vue'
import QuickCreateModal from '@/Components/schedule/QuickCreateModal.vue'
import { debounce } from '@/Composables/debounce'

interface CalendarItemRow {
  sched_item_id: number
  uuid: string
  type: 'task' | 'event'
  title: string
  start: string
  end: string
  date: string
  all_day: boolean
  status: string
  status_rail: 'danger' | 'warning' | 'success' | 'info' | 'neutral'
  owner_name: string | null
  location: string | null
  is_recurring_instance: boolean
  original_occurrence_date: string | null
}

const props = defineProps<{
  view: 'day' | 'week' | 'month' | 'agenda'
  date: string
  rangeStart: string
  rangeEnd: string
  items: CalendarItemRow[]
  filters: { mine: boolean; owner_id: string | null; resource_id: string | null; subject_type: string | null }
  owners: Array<{ id: number; name: string }>
  resources: Array<{ id: number; name: string }>
  subjectTypes: string[]
}>()

// ---------------------------------------------------------------------------
// Navigation (view switch, prev/today/next) — every change is a fresh Inertia
// GET with query params, same convention as every other index page's router.get.
// ---------------------------------------------------------------------------

const mine = ref(props.filters.mine)
const ownerId = ref(props.filters.owner_id ?? '')
const resourceId = ref(props.filters.resource_id ?? '')
const subjectType = ref(props.filters.subject_type ?? '')

const navigate = (overrides: Record<string, unknown> = {}) => {
  router.get(route('schedule.dashboard'), {
    view: props.view,
    date: props.date,
    mine: mine.value ? 1 : undefined,
    owner_id: ownerId.value || undefined,
    resource_id: resourceId.value || undefined,
    subject_type: subjectType.value || undefined,
    ...overrides,
  }, { preserveState: true, preserveScroll: true })
}

watch([mine, ownerId, resourceId, subjectType], debounce(() => navigate(), 300))

const setView = (v: string) => navigate({ view: v })

// toISOString() converts to UTC, which silently rolls the date back a full day
// for anyone east of UTC (e.g. Asia/Jakarta) — build the Y-m-d string from the
// Date's own local getters instead, same fix already used in QuickCreateModal.
const toLocalDateString = (d: Date): string => {
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
}

const addDays = (dateStr: string, days: number): string => {
  const d = new Date(dateStr + 'T00:00:00')
  d.setDate(d.getDate() + days)
  return toLocalDateString(d)
}

const addMonths = (dateStr: string, months: number): string => {
  const d = new Date(dateStr + 'T00:00:00')
  d.setMonth(d.getMonth() + months)
  return toLocalDateString(d)
}

const dayDeltas: Partial<Record<typeof props.view, number>> = { day: 1, week: 7, agenda: 30 }

const stepBack = () => {
  const delta = dayDeltas[props.view]
  navigate({ date: delta ? addDays(props.date, -delta) : addMonths(props.date, -1) })
}

const stepForward = () => {
  const delta = dayDeltas[props.view]
  navigate({ date: delta ? addDays(props.date, delta) : addMonths(props.date, 1) })
}

const goToday = () => navigate({ date: toLocalDateString(new Date()) })

const rangeLabel = computed(() => {
  const opts: Intl.DateTimeFormatOptions = { day: 'numeric', month: 'short', year: 'numeric' }
  const start = new Date(props.rangeStart + 'T00:00:00').toLocaleDateString('en-GB', opts)
  const end = new Date(props.rangeEnd + 'T00:00:00').toLocaleDateString('en-GB', opts)
  return props.view === 'day' ? start : `${start} – ${end}`
})

// ---------------------------------------------------------------------------
// Grid building
// ---------------------------------------------------------------------------

const itemsByDate = computed(() => {
  const map: Record<string, CalendarItemRow[]> = {}
  for (const item of props.items) {
    (map[item.date] ??= []).push(item)
  }
  for (const date in map) map[date].sort((a, b) => a.start.localeCompare(b.start))
  return map
})

const allDatesInRange = computed(() => {
  const dates: string[] = []
  let cur = props.rangeStart
  while (cur <= props.rangeEnd) {
    dates.push(cur)
    cur = addDays(cur, 1)
  }
  return dates
})

const monthWeeks = computed(() => {
  const dates = allDatesInRange.value
  const weeks: string[][] = []
  for (let i = 0; i < dates.length; i += 7) weeks.push(dates.slice(i, i + 7))
  return weeks
})

const agendaGroups = computed(() =>
  Object.keys(itemsByDate.value).sort().map((date) => ({ date, items: itemsByDate.value[date] }))
)

const today = toLocalDateString(new Date())
const dayLabel = (date: string) => new Date(date + 'T00:00:00').toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short' })

const expandedDays = ref<Set<string>>(new Set())
const toggleExpand = (date: string) => {
  expandedDays.value.has(date) ? expandedDays.value.delete(date) : expandedDays.value.add(date)
}

const railClass = (rail: string) => {
  const map: Record<string, string> = {
    danger: 'border-l-[3px] border-l-signal-danger',
    warning: 'border-l-[3px] border-l-signal-warning',
    success: 'border-l-[3px] border-l-signal-success',
    info: 'border-l-[3px] border-l-signal-info',
    neutral: 'border-l-[3px] border-l-border',
  }
  return map[rail] ?? map.neutral
}

const moduleLabel = (subjectType: string) => subjectType.replace(/[._]/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase())

// ---------------------------------------------------------------------------
// Item detail drawer — plain fetch(), same convention as CRM/WNE/Central dashboards.
// ---------------------------------------------------------------------------

interface DrawerData {
  id: number
  type: 'task' | 'event'
  title: string
  description: string | null
  status: string
  priority: string | null
  due_at: string | null
  start_at: string | null
  end_at: string | null
  owner_name: string | null
  location: string | null
  recurrence_rule: string | null
  attendees: Array<{ name: string | null; role: string }>
  resources: string[]
  conference_link: { provider_name: string; join_url: string } | null
  edit_url: string
  mark_done_url: string | null
  cancel_url: string
}

const drawer = ref<DrawerData | null>(null)
const drawerLoading = ref(false)

const openDrawer = async (schedItemId: number) => {
  drawerLoading.value = true
  try {
    const response = await fetch(route('schedule.dashboard.item', schedItemId))
    drawer.value = await response.json()
  } finally {
    drawerLoading.value = false
  }
}

const markDone = () => {
  if (!drawer.value?.mark_done_url) return
  router.post(drawer.value.mark_done_url, {}, { preserveScroll: true, onSuccess: () => { drawer.value = null } })
}

const cancelItem = () => {
  if (!drawer.value) return
  router.post(drawer.value.cancel_url, {}, { preserveScroll: true, onSuccess: () => { drawer.value = null } })
}

// ---------------------------------------------------------------------------
// Quick-create — "click a time slot → inline mini-form"
// ---------------------------------------------------------------------------

const showQuickCreate = ref(false)
const quickCreateDatetime = ref('')

const openQuickCreate = (date: string, time = '09:00') => {
  quickCreateDatetime.value = `${date}T${time}`
  showQuickCreate.value = true
}
</script>

<template>
  <AppLayout>
    <PageHeader title="Schedule" description="Tasks and events together — day, week, month, or agenda." />

    <ScheduleSubNav active="dashboard" class="mt-6" />

    <div class="mt-6 flex flex-wrap items-center justify-between gap-3">
      <div class="flex items-center gap-1" role="tablist">
        <button
          v-for="v in ['day', 'week', 'month', 'agenda']"
          :key="v"
          type="button"
          class="rounded-md border px-3 py-1.5 text-sm font-medium capitalize transition"
          :class="view === v ? 'border-accent bg-accent/10 text-accent' : 'border-border bg-surface-0 text-ink-600 hover:bg-surface-50'"
          @click="setView(v)"
        >
          {{ v }}
        </button>
      </div>

      <div class="flex items-center gap-2">
        <button type="button" class="rounded-md border border-border bg-surface-0 px-2 py-1.5 text-sm hover:bg-surface-50" @click="stepBack">‹</button>
        <button type="button" class="rounded-md border border-border bg-surface-0 px-3 py-1.5 text-sm hover:bg-surface-50" @click="goToday">Today</button>
        <button type="button" class="rounded-md border border-border bg-surface-0 px-2 py-1.5 text-sm hover:bg-surface-50" @click="stepForward">›</button>
        <span class="ml-2 text-sm font-medium text-ink-900">{{ rangeLabel }}</span>
      </div>

      <PrimaryButton type="button" @click="openQuickCreate(date)">+ New</PrimaryButton>
    </div>

    <div class="mt-4 flex flex-wrap items-center gap-3">
      <label class="flex items-center gap-1.5 text-sm text-ink-900">
        <input v-model="mine" type="checkbox" />
        My items
      </label>
      <FormSelect
        v-model="ownerId"
        name="owner_id"
        placeholder="All owners"
        :options="owners.map((o) => ({ label: o.name, value: String(o.id) }))"
      />
      <FormSelect
        v-model="resourceId"
        name="resource_id"
        placeholder="All resources"
        :options="resources.map((r) => ({ label: r.name, value: String(r.id) }))"
      />
      <FormSelect
        v-if="subjectTypes.length"
        v-model="subjectType"
        name="subject_type"
        placeholder="All modules"
        :options="subjectTypes.map((s) => ({ label: moduleLabel(s), value: s }))"
      />
    </div>

    <!-- Month view -->
    <Panel v-if="view === 'month'" class="mt-4 overflow-x-auto">
      <div class="min-w-[840px]">
        <div class="grid grid-cols-7 border-b border-border text-xs font-semibold uppercase tracking-wide text-ink-600">
          <div v-for="d in ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun']" :key="d" class="px-2 py-2">{{ d }}</div>
        </div>
        <div v-for="(week, wi) in monthWeeks" :key="wi" class="grid grid-cols-7 border-b border-border last:border-b-0">
          <div
            v-for="d in week"
            :key="d"
            class="min-h-[110px] cursor-pointer border-r border-border p-1.5 last:border-r-0 hover:bg-surface-50"
            :class="d === today ? 'bg-accent/5' : ''"
            @click="openQuickCreate(d)"
          >
            <p class="text-xs font-medium" :class="d === today ? 'text-accent' : 'text-ink-600'">{{ new Date(d + 'T00:00:00').getDate() }}</p>
            <div class="mt-1 space-y-1">
              <button
                v-for="item in (expandedDays.has(d) ? itemsByDate[d] : (itemsByDate[d] ?? []).slice(0, 3))"
                :key="item.sched_item_id + item.start"
                type="button"
                class="block w-full truncate rounded-sm bg-surface-50 px-1.5 py-0.5 text-left text-xs text-ink-900 hover:bg-surface-100"
                :class="railClass(item.status_rail)"
                @click.stop="openDrawer(item.sched_item_id)"
              >
                {{ item.title }}
              </button>
              <button
                v-if="(itemsByDate[d]?.length ?? 0) > 3"
                type="button"
                class="text-xs font-medium text-accent hover:underline"
                @click.stop="toggleExpand(d)"
              >
                {{ expandedDays.has(d) ? 'Show less' : `+${(itemsByDate[d]?.length ?? 0) - 3} more` }}
              </button>
            </div>
          </div>
        </div>
      </div>
    </Panel>

    <!-- Week view -->
    <Panel v-else-if="view === 'week'" class="mt-4 overflow-x-auto">
      <div class="grid min-w-[840px] grid-cols-7 divide-x divide-border">
        <div v-for="d in allDatesInRange" :key="d" class="min-h-[300px] p-2" :class="d === today ? 'bg-accent/5' : ''">
          <div class="flex items-center justify-between">
            <p class="text-xs font-semibold text-ink-900">{{ dayLabel(d) }}</p>
            <button type="button" class="text-xs font-medium text-accent hover:underline" @click="openQuickCreate(d)">+</button>
          </div>
          <div class="mt-2 space-y-1">
            <button
              v-for="item in itemsByDate[d] ?? []"
              :key="item.sched_item_id + item.start"
              type="button"
              class="block w-full truncate rounded-sm bg-surface-50 px-1.5 py-1 text-left text-xs text-ink-900 hover:bg-surface-100"
              :class="railClass(item.status_rail)"
              @click="openDrawer(item.sched_item_id)"
            >
              <span v-if="!item.all_day" class="text-ink-600">{{ item.start.split('T')[1] }}</span>
              {{ item.title }}
            </button>
            <p v-if="!(itemsByDate[d] ?? []).length" class="text-xs text-ink-600">—</p>
          </div>
        </div>
      </div>
    </Panel>

    <!-- Day view -->
    <Panel v-else-if="view === 'day'" class="mt-4 max-w-xl">
      <div class="space-y-2">
        <button
          v-for="item in itemsByDate[date] ?? []"
          :key="item.sched_item_id + item.start"
          type="button"
          class="flex w-full items-center justify-between gap-3 rounded-sm bg-surface-50 px-3 py-2 text-left text-sm text-ink-900 hover:bg-surface-100"
          :class="railClass(item.status_rail)"
          @click="openDrawer(item.sched_item_id)"
        >
          <span>{{ item.title }}</span>
          <span class="shrink-0 text-xs text-ink-600">{{ item.all_day ? 'All day' : item.start.split('T')[1] }}</span>
        </button>
        <p v-if="!(itemsByDate[date] ?? []).length" class="text-sm text-ink-600">Nothing scheduled. Click "+ New" to add something.</p>
      </div>
    </Panel>

    <!-- Agenda view -->
    <Panel v-else class="mt-4 max-w-2xl">
      <div class="space-y-4">
        <div v-for="group in agendaGroups" :key="group.date">
          <p class="text-xs font-semibold uppercase tracking-wide text-ink-600">{{ dayLabel(group.date) }}</p>
          <div class="mt-1 space-y-1">
            <button
              v-for="item in group.items"
              :key="item.sched_item_id + item.start"
              type="button"
              class="flex w-full items-center justify-between gap-3 rounded-sm bg-surface-50 px-3 py-2 text-left text-sm text-ink-900 hover:bg-surface-100"
              :class="railClass(item.status_rail)"
              @click="openDrawer(item.sched_item_id)"
            >
              <span>{{ item.title }}</span>
              <span class="shrink-0 text-xs text-ink-600">{{ item.all_day ? 'All day' : item.start.split('T')[1] }}</span>
            </button>
          </div>
        </div>
        <p v-if="!agendaGroups.length" class="text-sm text-ink-600">Nothing scheduled in the next 30 days.</p>
      </div>
    </Panel>

    <!-- Item detail side panel -->
    <div v-if="drawer || drawerLoading" class="fixed inset-0 z-50 flex justify-end bg-black/30" @click.self="drawer = null">
      <div class="h-full w-full max-w-md overflow-y-auto bg-surface-0 p-6 shadow-xl">
        <button type="button" class="text-sm text-ink-600 hover:text-ink-900" @click="drawer = null">Close</button>

        <template v-if="drawerLoading">
          <p class="mt-4 text-sm text-ink-600">Loading…</p>
        </template>
        <template v-else-if="drawer">
          <h2 class="mt-4 font-serif text-lg font-semibold text-ink-900">{{ drawer.title }}</h2>
          <p v-if="drawer.description" class="mt-1 text-sm text-ink-600">{{ drawer.description }}</p>
          <div class="mt-2 flex items-center gap-2">
            <StatusBadge :status="drawer.status" />
            <StatusBadge v-if="drawer.priority" :status="drawer.priority" />
          </div>

          <dl class="mt-4 space-y-1 text-sm">
            <div v-if="drawer.due_at" class="flex justify-between"><dt class="text-ink-600">Due</dt><dd class="text-ink-900">{{ drawer.due_at }}</dd></div>
            <div v-if="drawer.start_at" class="flex justify-between"><dt class="text-ink-600">Start</dt><dd class="text-ink-900">{{ drawer.start_at }}</dd></div>
            <div v-if="drawer.end_at" class="flex justify-between"><dt class="text-ink-600">End</dt><dd class="text-ink-900">{{ drawer.end_at }}</dd></div>
            <div v-if="drawer.owner_name" class="flex justify-between"><dt class="text-ink-600">Owner</dt><dd class="text-ink-900">{{ drawer.owner_name }}</dd></div>
            <div v-if="drawer.location" class="flex justify-between"><dt class="text-ink-600">Location</dt><dd class="text-ink-900">{{ drawer.location }}</dd></div>
            <div v-if="drawer.recurrence_rule" class="flex justify-between"><dt class="text-ink-600">Recurs</dt><dd class="text-ink-900 text-right">{{ drawer.recurrence_rule }}</dd></div>
          </dl>

          <div v-if="drawer.attendees.length" class="mt-4">
            <h3 class="text-xs font-semibold uppercase tracking-wide text-ink-600">Attendees</h3>
            <ul class="mt-1 text-sm text-ink-900">
              <li v-for="(a, idx) in drawer.attendees" :key="idx">{{ a.name }} <span class="text-xs text-ink-600">({{ a.role }})</span></li>
            </ul>
          </div>

          <div v-if="drawer.resources.length" class="mt-4">
            <h3 class="text-xs font-semibold uppercase tracking-wide text-ink-600">Resources</h3>
            <p class="mt-1 text-sm text-ink-900">{{ drawer.resources.join(', ') }}</p>
          </div>

          <div v-if="drawer.conference_link" class="mt-4">
            <h3 class="text-xs font-semibold uppercase tracking-wide text-ink-600">Conference</h3>
            <a :href="drawer.conference_link.join_url" target="_blank" rel="noopener" class="mt-1 block text-sm text-accent underline">
              {{ drawer.conference_link.provider_name }} — join link
            </a>
          </div>

          <div class="mt-6 flex flex-wrap items-center gap-3 border-t border-border pt-4">
            <a :href="drawer.edit_url" class="text-sm font-medium text-accent hover:underline">Edit</a>
            <button v-if="drawer.mark_done_url" type="button" class="text-sm font-medium text-signal-success hover:underline" @click="markDone">Mark done</button>
            <button v-if="drawer.status !== 'cancelled'" type="button" class="text-sm font-medium text-signal-danger hover:underline" @click="cancelItem">Cancel</button>
          </div>
        </template>
      </div>
    </div>

    <QuickCreateModal
      :show="showQuickCreate"
      :default-datetime="quickCreateDatetime"
      :resources="resources"
      @close="showQuickCreate = false"
    />
  </AppLayout>
</template>
