<!-- ponytail: Project board — Kanban (native HTML5 drag/drop, no new dependency) + List (DataTable
     groupBy showcasing the Excel-style subtotal footer). Issues load as one flat array (a single
     project's backlog, not paginated), so sort/search/filter here are client-side computed instead
     of the Inertia router.get push pattern Index.vue uses for server-paginated tables. -->
<script setup lang="ts">
import { computed, ref } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import DataTable, { type SortState } from '@/Components/tables/DataTable.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import Tabs from '@/Components/navigation/Tabs.vue'
import { Paperclip } from 'lucide-vue-next'

interface IssueRow {
  id: number
  code: string
  title: string
  type: string
  status: string
  priority: string
  assignee_id: number | null
  assignee: string | null
  attachments_count: number
  due_date: string | null
  due_date_formatted: string | null
  is_overdue: boolean
}

const props = defineProps<{
  project: {
    id: number
    code: string
    name: string
    description: string | null
    status: string
    start_date: string | null
    end_date: string | null
  }
  issues: IssueRow[]
  users: Array<{ id: number; name: string }>
}>()

const STATUS_COLUMNS: Array<{ key: string; label: string }> = [
  { key: 'todo', label: 'To do' },
  { key: 'in_progress', label: 'In Progress' },
  { key: 'done', label: 'Done' },
]

const PRIORITY_LABEL: Record<string, string> = { low: 'Low', medium: 'Medium', high: 'High', urgent: 'Urgent' }
const PRIORITY_CLASS: Record<string, string> = {
  low: 'text-ink-600',
  medium: 'text-ink-900',
  high: 'text-signal-warning font-medium',
  urgent: 'text-signal-danger font-semibold',
}

const view = ref<'board' | 'list'>('board')

// --- New issue (quick create, always starts in Todo) ---
const form = useForm({
  title: '',
  type: 'task',
  status: 'todo',
  priority: 'medium',
  assignee_id: '' as number | '',
  description: '',
  due_date: '',
})

const submitNewIssue = () => {
  form.post(route('projects.issues.store', props.project.id), {
    preserveScroll: true,
    onSuccess: () => form.reset('title'),
  })
}

// --- Board drag/drop ---
const onDragStart = (event: DragEvent, issueId: number) => {
  event.dataTransfer?.setData('text/plain', String(issueId))
}

const onDrop = (event: DragEvent, status: string) => {
  const issueId = Number(event.dataTransfer?.getData('text/plain'))
  if (!issueId) return
  router.patch(route('projects.issues.updateStatus', [props.project.id, issueId]), { status }, { preserveScroll: true })
}

// --- Board quick-assign (Jira-style: pick a user straight from the card) ---
const onAssigneeChange = (issueId: number, event: Event) => {
  const value = (event.target as HTMLSelectElement).value
  router.patch(
    route('projects.issues.updateAssignee', [props.project.id, issueId]),
    { assignee_id: value === '' ? null : Number(value) },
    { preserveScroll: true },
  )
}

const issuesByStatus = (status: string) =>
  props.issues
    .filter((i) => i.status === status)
    .sort((a, b) => {
      // Overdue first, then soonest due date; undated issues sink to the bottom.
      if (a.is_overdue !== b.is_overdue) return a.is_overdue ? -1 : 1
      return (a.due_date ?? '9999-12-31').localeCompare(b.due_date ?? '9999-12-31')
    })

const formatProjectDates = () => {
  if (!props.project.start_date && !props.project.end_date) return null
  const fmt = (d: string | null) => {
    if (!d) return null
    const [y, m, day] = d.split('-')
    return `${day} ${['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'][Number(m) - 1]} ${y}`
  }
  const s = fmt(props.project.start_date)
  const e = fmt(props.project.end_date)
  if (s && e) return `${s} — ${e}`
  return s ?? `until ${e}`
}

// --- List view (client-side sort/search, DataTable groupBy showcase) ---
const search = ref('')
const sort = ref<SortState>(null)

const columns = [
  { key: 'code', label: 'Code', sortable: true, footer: 'count' as const },
  { key: 'title', label: 'Title', sortable: true },
  { key: 'type', label: 'Type', sortable: true },
  { key: 'priority', label: 'Priority', sortable: true },
  { key: 'assignee', label: 'Assignee' },
  { key: 'attachments_count', label: 'Files', sortable: true },
  { key: 'due_date_formatted', label: 'Due', sortKey: 'due_date' },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

const groupByStatus = (item: Record<string, any>) => {
  const label = STATUS_COLUMNS.find((c) => c.key === item.status)?.label ?? item.status
  return { key: item.status as string, label }
}

const filteredIssues = computed(() => {
  let list = props.issues
  if (search.value) {
    const q = search.value.toLowerCase()
    list = list.filter((i) => i.code.toLowerCase().includes(q) || i.title.toLowerCase().includes(q))
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
    <PageHeader :title="`${project.code} — ${project.name}`" :description="project.description || 'No description.'">
      <template #actions>
        <div class="flex items-center gap-3">
          <span v-if="formatProjectDates()" class="text-sm text-ink-600">{{ formatProjectDates() }}</span>
          <Link
            :href="route('projects.edit', project.id)"
            class="inline-flex items-center justify-center rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm font-semibold text-ink-900 shadow-sm transition hover:bg-surface-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            Edit project
          </Link>
        </div>
      </template>
    </PageHeader>

    <Panel class="mt-6">
      <form class="flex flex-wrap items-end gap-3" @submit.prevent="submitNewIssue">
        <div class="min-w-[240px] flex-1">
          <FormInput v-model="form.title" name="title" label="New issue" placeholder="What needs doing?" :error="form.errors.title" required />
        </div>
        <FormSelect
          v-model="form.type"
          name="type"
          label="Type"
          :options="[
            { label: 'Task', value: 'task' },
            { label: 'Bug', value: 'bug' },
            { label: 'Story', value: 'story' },
          ]"
        />
        <FormSelect
          v-model="form.priority"
          name="priority"
          label="Priority"
          :options="[
            { label: 'Low', value: 'low' },
            { label: 'Medium', value: 'medium' },
            { label: 'High', value: 'high' },
            { label: 'Urgent', value: 'urgent' },
          ]"
        />
        <FormSelect
          v-model="form.assignee_id"
          name="assignee_id"
          label="Assignee"
          placeholder="Unassigned"
          :options="users.map((u) => ({ label: u.name, value: u.id }))"
        />
        <PrimaryButton type="submit" :disabled="form.processing">Add issue</PrimaryButton>
      </form>
    </Panel>

    <div class="mt-6">
      <Tabs v-model="view" :tabs="[{ key: 'board', label: 'Board' }, { key: 'list', label: 'List' }]" />
    </div>

    <div v-if="view === 'board'" class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-3">
      <div
        v-for="column in STATUS_COLUMNS"
        :key="column.key"
        class="rounded-md border border-border bg-surface-50 p-3"
        @dragover.prevent
        @drop="onDrop($event, column.key)"
      >
        <p class="mb-3 flex items-center justify-between text-xs font-semibold uppercase tracking-wide text-ink-600">
          {{ column.label }}
          <span class="rounded-full bg-surface-0 px-2 py-0.5 text-[11px] font-medium text-ink-600">
            {{ issuesByStatus(column.key).length }}
          </span>
        </p>
        <div class="space-y-2">
          <Link
            v-for="issue in issuesByStatus(column.key)"
            :key="issue.id"
            :href="route('projects.issues.edit', [project.id, issue.id])"
            draggable="true"
            class="block cursor-grab rounded-md border border-border bg-surface-0 p-3 shadow-sm transition hover:shadow active:cursor-grabbing"
            @dragstart="onDragStart($event, issue.id)"
          >
            <p class="font-mono text-xs text-ink-600">{{ issue.code }}</p>
            <p class="mt-1 text-sm font-medium text-ink-900">{{ issue.title }}</p>
            <div class="mt-2 flex items-center justify-between gap-2 text-xs">
              <span :class="PRIORITY_CLASS[issue.priority]">{{ PRIORITY_LABEL[issue.priority] }}</span>
              <select
                :value="issue.assignee_id ?? ''"
                draggable="false"
                class="max-w-[7.5rem] truncate rounded-sm border border-transparent bg-transparent py-0.5 pl-1 pr-4 text-right text-ink-600 hover:border-border focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
                @click.stop
                @mousedown.stop
                @dragstart.stop
                @change="onAssigneeChange(issue.id, $event)"
              >
                <option value="">Unassigned</option>
                <option v-for="user in users" :key="user.id" :value="user.id">{{ user.name }}</option>
              </select>
            </div>
            <div class="mt-1 flex items-center justify-between text-xs">
              <span v-if="issue.attachments_count" class="flex items-center gap-1 text-ink-600">
                <Paperclip class="h-3 w-3" />
                {{ issue.attachments_count }}
              </span>
              <p
                v-if="issue.due_date_formatted"
                class="ml-auto text-xs"
                :class="issue.is_overdue ? 'font-semibold text-signal-danger' : 'text-ink-600'"
              >
                {{ issue.is_overdue ? 'Overdue — ' : 'Due ' }}{{ issue.due_date_formatted }}
              </p>
            </div>
          </Link>
          <p v-if="issuesByStatus(column.key).length === 0" class="text-xs text-ink-600">No issues.</p>
        </div>
      </div>
    </div>

    <div v-else class="mt-4">
      <DataTable
        :columns="columns"
        :items="filteredIssues"
        v-model:sort="sort"
        v-model:search="search"
        search-placeholder="Search code or title…"
        status-rail-key="status"
        :group-by="groupByStatus"
        empty-title="No issues yet"
        empty-description="Add your first issue above."
      >
        <template #cell-code="{ item }">
          <Link :href="route('projects.issues.edit', [project.id, item.id])" class="font-mono text-sm text-accent hover:underline">
            {{ item.code }}
          </Link>
        </template>
        <template #cell-priority="{ item }">
          <span :class="PRIORITY_CLASS[item.priority]">{{ PRIORITY_LABEL[item.priority] }}</span>
        </template>
        <template #cell-attachments_count="{ item }">
          <span v-if="item.attachments_count" class="inline-flex items-center gap-1 text-ink-600">
            <Paperclip class="h-3.5 w-3.5" />
            {{ item.attachments_count }}
          </span>
          <span v-else class="text-ink-600/60">—</span>
        </template>
        <template #cell-due_date_formatted="{ item }">
          <span :class="item.is_overdue ? 'font-semibold text-signal-danger' : 'text-ink-900'">
            {{ item.due_date_formatted ?? '—' }}
            <span v-if="item.is_overdue" class="ml-1 rounded-full bg-signal-danger/10 px-1.5 py-0.5 text-[10px] font-medium">Overdue</span>
          </span>
        </template>
        <template #footer-code="{ value }">
          <span class="font-mono">{{ value }}</span>
        </template>
        <template #cell-actions="{ item }">
          <Link
            :href="route('projects.issues.edit', [project.id, item.id])"
            class="text-sm font-medium text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            Open
          </Link>
        </template>
      </DataTable>
    </div>
  </AppLayout>
</template>
