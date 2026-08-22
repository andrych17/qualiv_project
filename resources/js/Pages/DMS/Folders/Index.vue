<!-- ponytail: DMS §3D Folder / Category Management — depth-indented flat listing (folder counts
     are small, so a plain client-filtered table beats wiring server-side search/pagination
     just for this, same reasoning Versions.vue used). Delete is guarded server-side
     (FolderService::delete) — a blocked delete redirects back with a validation error, which
     has no automatic toast anywhere else in this codebase either, so this page reads
     page.props.errors.folder itself and shows it via the shared toast store. -->
<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import { useConfirm } from '@/Composables/useConfirmDialog'
import { showToast } from '@/Composables/useFlashToast'

interface FolderRow {
  id: number
  name: string
  depth: number
  parent_name: string | null
  default_doc_type_name: string | null
  access_flag: string
  document_count: number
  child_count: number
}

const props = defineProps<{ folders: FolderRow[] }>()

const search = ref('')
const filtered = computed(() => {
  const q = search.value.trim().toLowerCase()
  if (!q) return props.folders
  return props.folders.filter((f) => f.name.toLowerCase().includes(q))
})

const { confirm } = useConfirm()
const confirmDelete = (folder: FolderRow) => {
  confirm({
    title: `Delete folder "${folder.name}"?`,
    description: folder.document_count || folder.child_count
      ? `This folder has ${folder.document_count} document(s) and ${folder.child_count} subfolder(s) — deletion will be blocked until it's empty.`
      : undefined,
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () => router.delete(route('dms.folders.destroy', folder.id)),
  })
}

// Blocked-delete guard surfaces as a validation error (FolderService throws ValidationException),
// not a flash message — watch for it and show it the same way every flash success/error shows.
const page = usePage()
watch(() => (page.props.errors as { folder?: string })?.folder, (message) => {
  if (message) showToast(message, 'error')
})
</script>

<template>
  <AppLayout>
    <PageHeader title="Folders" description="Standalone folder tree — default doc type, retention policy, and access per folder.">
      <template #actions>
        <Link :href="route('dms.dashboard')" class="mr-4 text-sm font-medium text-accent hover:underline">← Back to library</Link>
        <PrimaryButton :href="route('dms.folders.create')">New folder</PrimaryButton>
      </template>
    </PageHeader>

    <Panel class="mt-6">
      <input
        v-model="search"
        type="text"
        placeholder="Search folders…"
        class="mb-4 w-full max-w-xs rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm text-ink-900 shadow-sm focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/20"
      />

      <table class="w-full text-sm">
        <thead>
          <tr class="border-b border-border text-left text-xs uppercase text-ink-600">
            <th class="py-2">Name</th>
            <th class="py-2">Default doc type</th>
            <th class="py-2">Access</th>
            <th class="py-2 text-right">Documents</th>
            <th class="py-2 text-right">Subfolders</th>
            <th class="py-2 text-right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="f in filtered" :key="f.id" class="border-b border-border hover:bg-surface-50">
            <td class="py-2 text-ink-900" :style="{ paddingLeft: `${8 + f.depth * 16}px` }">{{ f.name }}</td>
            <td class="py-2 text-ink-700">{{ f.default_doc_type_name ?? '—' }}</td>
            <td class="py-2 text-ink-700 capitalize">{{ f.access_flag }}</td>
            <td class="py-2 text-right text-ink-700">{{ f.document_count }}</td>
            <td class="py-2 text-right text-ink-700">{{ f.child_count }}</td>
            <td class="py-2 text-right">
              <Link :href="route('dms.folders.edit', f.id)" class="mr-3 text-sm font-medium text-accent hover:underline">Edit</Link>
              <button type="button" class="text-sm font-medium text-signal-danger hover:underline" @click="confirmDelete(f)">Delete</button>
            </td>
          </tr>
          <tr v-if="!filtered.length"><td colspan="6" class="py-6 text-center text-ink-600">No folders yet.</td></tr>
        </tbody>
      </table>
    </Panel>
  </AppLayout>
</template>
