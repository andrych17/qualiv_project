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
import FormTextarea from '@/Components/forms/FormTextarea.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import DangerButton from '@/Components/DangerButton.vue'
import { Paperclip } from 'lucide-vue-next'
import { ref } from 'vue'
import { useConfirm } from '@/Composables/useConfirmDialog'

interface CommentRow {
  id: number
  body: string
  author: string
  created_at_formatted: string | null
}

interface AttachmentRow {
  id: number
  original_name: string
  mime_type: string | null
  previewable: boolean
  size: number
  uploader: string
  created_at_formatted: string | null
  download_url: string
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
  attachments: AttachmentRow[]
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

const { confirm } = useConfirm()

const confirmDelete = () => {
  confirm({
    title: `Delete issue ${props.issue.code}?`,
    description: 'This also removes its comments and attachments. This cannot be undone.',
    confirmText: 'Delete issue',
    variant: 'destructive',
    onConfirm: () => router.delete(route('projects.issues.destroy', [props.project.id, props.issue.id])),
  })
}

const commentForm = useForm({ body: '' })

const submitComment = () => {
  commentForm.post(route('projects.issues.comments.store', [props.project.id, props.issue.id]), {
    preserveScroll: true,
    onSuccess: () => commentForm.reset('body'),
  })
}

const deleteComment = (comment: CommentRow) => {
  confirm({
    title: 'Delete this comment?',
    confirmText: 'Delete comment',
    variant: 'destructive',
    onConfirm: () =>
      router.delete(route('projects.issues.comments.destroy', [props.project.id, props.issue.id, comment.id]), {
        preserveScroll: true,
      }),
  })
}

// --- Attachments ---
const attachForm = useForm({ file: null as File | null })
const dragOver = ref(false)

const submitAttachment = () => {
  if (!attachForm.file) return
  attachForm.post(route('projects.issues.attachments.store', [props.project.id, props.issue.id]), {
    preserveScroll: true,
    forceFormData: true,
    onSuccess: () => attachForm.reset(),
  })
}

const onDropFile = (event: DragEvent) => {
  dragOver.value = false
  attachForm.file = event.dataTransfer?.files?.[0] ?? null
}

const deleteAttachment = (attachment: AttachmentRow) => {
  confirm({
    title: `Delete ${attachment.original_name}?`,
    description: 'The file is removed from the internal file server.',
    confirmText: 'Delete file',
    variant: 'destructive',
    onConfirm: () =>
      router.delete(route('projects.issues.attachments.destroy', [props.project.id, props.issue.id, attachment.id]), {
        preserveScroll: true,
      }),
  })
}

const formatSize = (bytes: number) => {
  if (bytes >= 1024 * 1024) return `${(bytes / 1024 / 1024).toFixed(1)} MB`
  return `${Math.max(1, Math.round(bytes / 1024))} KB`
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
          <FormTextarea v-model="form.description" name="description" label="Description" :rows="5" />
          <div class="grid grid-cols-2 gap-4">            <FormSelect
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

        <div class="border-t border-border pt-4">
          <p class="flex items-center gap-1.5 text-sm font-medium text-ink-900">
            <Paperclip class="h-4 w-4" />
            Attachments
            <span v-if="attachments.length" class="rounded-full bg-surface-50 px-1.5 py-0.5 text-[11px] text-ink-600">{{ attachments.length }}</span>
          </p>

          <form
            class="mt-3 rounded-md border-2 border-dashed border-border bg-surface-50 p-4 text-center transition"
            :class="dragOver ? 'border-accent bg-accent/5' : ''"
            @submit.prevent="submitAttachment"
            @dragover.prevent="dragOver = true"
            @dragleave.prevent="dragOver = false"
            @drop.prevent="onDropFile"
          >
            <input
              id="issue-attachment"
              type="file"
              class="sr-only"
              @change="attachForm.file = ($event.target as HTMLInputElement).files?.[0] ?? null"
            />
            <label
              for="issue-attachment"
              class="inline-block cursor-pointer rounded-sm p-2 text-sm text-ink-600 transition hover:text-accent focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
            >
              {{ attachForm.file ? attachForm.file.name : 'Drop a file here, or click to choose' }}
            </label>
            <p v-if="attachForm.errors.file" class="mt-1 text-sm text-signal-danger">{{ attachForm.errors.file }}</p>
            <div class="mt-3 flex justify-center gap-2">
              <button
                v-if="attachForm.file"
                type="button"
                class="text-sm font-medium text-ink-600 underline-offset-2 hover:underline"
                @click="attachForm.reset()"
              >
                Clear
              </button>
              <PrimaryButton type="submit" :disabled="attachForm.processing || !attachForm.file">Upload</PrimaryButton>
            </div>
          </form>

          <ul v-if="attachments.length" class="mt-3 space-y-2">
            <li
              v-for="attachment in attachments"
              :key="attachment.id"
              class="flex items-center gap-3 rounded-md border border-border bg-surface-0 p-2.5"
            >
              <img
                v-if="attachment.previewable"
                :src="attachment.download_url"
                :alt="attachment.original_name"
                class="h-10 w-10 shrink-0 rounded-sm object-cover ring-1 ring-border"
              />
              <span v-else class="flex h-10 w-10 shrink-0 items-center justify-center rounded-sm bg-surface-50 text-[10px] uppercase text-ink-600">
                {{ (attachment.mime_type ?? 'file').split('/').pop()?.slice(0, 4) }}
              </span>
              <a :href="attachment.download_url" class="min-w-0 flex-1" :download="!attachment.previewable ? attachment.original_name : undefined">
                <p class="truncate text-sm font-medium text-ink-900 hover:text-accent">{{ attachment.original_name }}</p>
                <p class="text-xs text-ink-600">
                  {{ formatSize(attachment.size) }} — {{ attachment.uploader }}, {{ attachment.created_at_formatted }}
                </p>
              </a>
              <button type="button" class="shrink-0 text-sm text-signal-danger hover:underline" @click="deleteAttachment(attachment)">
                Delete
              </button>
            </li>
          </ul>
        </div>
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
            <FormTextarea v-model="commentForm.body" name="comment_body" :rows="3" placeholder="Add a comment…" />
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
