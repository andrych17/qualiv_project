<!-- ponytail: Issue detail — full edit form + comment thread. First "detail/show"-style page in
     the app (Legal never built one — see DataTable.vue's expandable rows for the usual pattern);
     issues need a dedicated page since comments need somewhere to live. -->
<script setup lang="ts">
import { useForm, Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import DangerButton from '@/Components/DangerButton.vue'

interface CommentRow {
  id: number
  body: string
  author: string
  created_at_formatted: string | null
}

const props = defineProps<{
  project: { id: number; code: string; name: string }
  issue: {
    id: number
    code: string
    title: string
    description: string | null
    type: string
    status: string
    priority: string
    assignee_id: number | null
    due_date: string | null
  }
  comments: CommentRow[]
  users: Array<{ id: number; name: string }>
}>()

const form = useForm({
  title: props.issue.title,
  description: props.issue.description ?? '',
  type: props.issue.type,
  status: props.issue.status,
  priority: props.issue.priority,
  assignee_id: (props.issue.assignee_id ?? '') as number | '',
  due_date: props.issue.due_date ?? '',
})

const submit = () => form.put(route('projects.issues.update', [props.project.id, props.issue.id]))

const confirmDelete = () => {
  if (!confirm(`Delete issue ${props.issue.code}?`)) return
  router.delete(route('projects.issues.destroy', [props.project.id, props.issue.id]))
}

const commentForm = useForm({ body: '' })

const submitComment = () => {
  commentForm.post(route('projects.issues.comments.store', [props.project.id, props.issue.id]), {
    preserveScroll: true,
    onSuccess: () => commentForm.reset('body'),
  })
}

const deleteComment = (comment: CommentRow) => {
  if (!confirm('Delete this comment?')) return
  router.delete(route('projects.issues.comments.destroy', [props.project.id, props.issue.id, comment.id]), {
    preserveScroll: true,
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader :title="`${issue.code} — ${issue.title}`">
      <template #actions>
        <Link :href="route('projects.show', project.id)" class="text-sm font-medium text-accent hover:underline">
          ← Back to {{ project.code }}
        </Link>
      </template>
    </PageHeader>

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
      <Panel class="lg:col-span-2">
        <form class="space-y-4" @submit.prevent="submit">
          <FormInput v-model="form.title" name="title" label="Title" :error="form.errors.title" required />
          <div class="space-y-1.5">
            <label class="text-sm font-medium text-ink-900">Description</label>
            <textarea
              v-model="form.description"
              rows="5"
              class="w-full rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm text-ink-900 shadow-sm focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/20"
            />
          </div>
          <div class="grid grid-cols-2 gap-4">
            <FormSelect
              v-model="form.type"
              name="type"
              label="Type"
              :options="[
                { label: 'Task', value: 'task' },
                { label: 'Bug', value: 'bug' },
                { label: 'Story', value: 'story' },
              ]"
              required
            />
            <FormSelect
              v-model="form.status"
              name="status"
              label="Status"
              :options="[
                { label: 'To do', value: 'todo' },
                { label: 'In Progress', value: 'in_progress' },
                { label: 'Done', value: 'done' },
              ]"
              required
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
              required
            />
            <FormSelect
              v-model="form.assignee_id"
              name="assignee_id"
              label="Assignee"
              placeholder="Unassigned"
              :options="users.map((u) => ({ label: u.name, value: u.id }))"
            />
          </div>
          <FormInput v-model="form.due_date" name="due_date" type="date" label="Due date" :error="form.errors.due_date" />

          <div class="flex items-center justify-between border-t border-border pt-4">
            <DangerButton type="button" @click="confirmDelete">Delete issue</DangerButton>
            <PrimaryButton type="submit" :disabled="form.processing">Save changes</PrimaryButton>
          </div>
        </form>
      </Panel>

      <Panel title="Comments">
        <div class="space-y-4">
          <div v-if="comments.length === 0" class="text-sm text-ink-600">No comments yet.</div>
          <div v-for="comment in comments" :key="comment.id" class="rounded-md border border-border bg-surface-50 p-3">
            <div class="flex items-center justify-between text-xs text-ink-600">
              <span class="font-medium text-ink-900">{{ comment.author }}</span>
              <div class="flex items-center gap-2">
                <span>{{ comment.created_at_formatted }}</span>
                <button type="button" class="text-signal-danger hover:underline" @click="deleteComment(comment)">
                  Delete
                </button>
              </div>
            </div>
            <p class="mt-1 whitespace-pre-wrap text-sm text-ink-900">{{ comment.body }}</p>
          </div>

          <form class="space-y-2 border-t border-border pt-4" @submit.prevent="submitComment">
            <textarea
              v-model="commentForm.body"
              rows="3"
              placeholder="Add a comment…"
              class="w-full rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm text-ink-900 shadow-sm focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/20"
            />
            <p v-if="commentForm.errors.body" class="text-sm text-signal-danger">{{ commentForm.errors.body }}</p>
            <div class="flex justify-end">
              <PrimaryButton type="submit" :disabled="commentForm.processing">Comment</PrimaryButton>
            </div>
          </form>
        </div>
      </Panel>
    </div>
  </AppLayout>
</template>
