<!-- ponytail: Project board — KanbanBoard (HTML5 DnD) + List (DataTable groupBy).
     Issues load as one flat array (single project backlog), so list sort/search is client-side. -->
<script setup lang="ts">
import { computed, ref } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import DataTable, { type SortState } from '@/Components/tables/DataTable.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import FormSearchableSelect from '@/Components/forms/FormSearchableSelect.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import Tabs from '@/Components/navigation/Tabs.vue'
import KanbanBoard from '@/Components/kanban/KanbanBoard.vue'
import {
  PRIORITY_CLASS,
  PRIORITY_LABEL,
  type KanbanColumnDef,
  type KanbanItem,
} from '@/Components/kanban/types'
import { Paperclip } from 'lucide-vue-next'

const props = defineProps<{
  project: {
    id: number
    code: string
    name: string
    description: string | null
    status: string
    lead_name: string | null
    start_date: string | null
    end_date: string | null
  }
  issues: KanbanItem[]
  stats: { total: number; todo: number; in_progress: number; done: number; overdue: number }
  users: Array<{ id: number; name: string }>
}>()

const STATUS_COLUMNS: KanbanColumnDef[] = [
  { key: 'todo', label: 'To do' },
  { key: 'in_progress', label: 'In Progress' },
  { key: 'done', label: 'Done' },
]

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

const userOptions = computed(() => props.users.map((u) => ({ label: u.name, value: u.id })))

const issueHref = (item: KanbanItem) => route('projects.issues.edit', [props.project.id, item.id])

const onStatusChange = (issueId: number, status: string) => {
  router.patch(
    route('projects.issues.updateStatus', [props.project.id, issueId]),
    { status },
    { preserveScroll: true },
  )
}

const onAssigneeChange = (issueId: number, value: string | number | null) => {
  router.patch(
    route('projects.issues.updateAssignee', [props.project.id, issueId]),
    { assignee_id: value === null || value === '' ? null : Number(value) },
    { preserveScroll: true },
  )
}

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
          <span v-if="project.lead_name" class="text-sm text-ink-600">Lead: {{ project.lead_name }}</span>
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

    <div class="mt-4 flex flex-wrap gap-2 text-xs">
      <span class="rounded-full border border-border bg-surface-50 px-2.5 py-1 font-medium text-ink-600">{{ stats.total }} issues</span>
      <span class="rounded-full border border-border bg-surface-50 px-2.5 py-1 text-ink-600">{{ stats.todo }} to do</span>
      <span class="rounded-full border border-border bg-surface-50 px-2.5 py-1 text-ink-600">{{ stats.in_progress }} in progress</span>
      <span class="rounded-full border border-border bg-surface-50 px-2.5 py-1 text-ink-600">{{ stats.done }} done</span>
      <span v-if="stats.overdue" class="rounded-full border border-signal-danger/25 bg-signal-danger/10 px-2.5 py-1 font-medium text-signal-danger">{{ stats.overdue }} overdue</span>
    </div>

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
        <FormSearchableSelect
          v-model="form.assignee_id"
          name="assignee_id"
          label="Assignee"
          placeholder="Unassigned"
          search-placeholder="Search user..."
          :options="userOptions"
          :error="form.errors.assignee_id"
        />
        <PrimaryButton type="submit" :disabled="form.processing">Add issue</PrimaryButton>
      </form>
    </Panel>

    <div class="mt-6">
      <Tabs v-model="view" :tabs="[{ key: 'board', label: 'Board' }, { key: 'list', label: 'List' }]" />
    </div>

    <div v-if="view === 'board'" class="mt-4">
      <KanbanBoard
        :columns="STATUS_COLUMNS"
        :items="issues"
        :user-options="userOptions"
        :item-href="issueHref"
        @status-change="onStatusChange"
        @assignee-change="onAssigneeChange"
      />
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
