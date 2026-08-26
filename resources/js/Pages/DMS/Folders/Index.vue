<!-- ponytail: DMS §3D Folder / Category Management — depth-indented flat listing. -->
<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import DataTable from '@/Components/tables/DataTable.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import { useConfirm } from '@/Composables/useConfirmDialog'
import { showToast } from '@/Composables/useFlashToast'
import { Folder } from 'lucide-vue-next'

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

const columns = [
  { key: 'name', label: 'Folder Name', sortable: true },
  { key: 'default_doc_type_name', label: 'Default Doc Type', sortable: true },
  { key: 'access_flag', label: 'Access Level', sortable: true },
  { key: 'document_count', label: 'Documents', align: 'right' as const, sortable: true },
  { key: 'child_count', label: 'Subfolders', align: 'right' as const, sortable: true },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

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

const page = usePage()
watch(() => (page.props.errors as { folder?: string })?.folder, (message) => {
  if (message) showToast(message, 'error')
})
</script>

<template>
  <AppLayout>
    <PageHeader title="DMS Folders" description="Organize documents into structured taxonomy with inherited access controls.">
      <template #actions>
        <div class="flex items-center gap-2">
          <SecondaryButton :href="route('dms.dashboard')">
            &larr; Document Library
          </SecondaryButton>
          <PrimaryButton :href="route('dms.folders.create')">
            + New Folder
          </PrimaryButton>
        </div>
      </template>
    </PageHeader>

    <div class="mt-6">
      <DataTable
        :columns="columns"
        :items="filtered"
        :search="search"
        search-placeholder="Search folders..."
        empty-title="No folders found"
        empty-description="Create your first folder to organize files in the document management system."
        @update:search="search = $event"
      >
        <template #cell-name="{ item }">
          <div class="flex items-center gap-2" :style="{ paddingLeft: `${item.depth * 20}px` }">
            <Folder class="h-4 w-4 text-accent flex-shrink-0" />
            <span class="font-medium text-ink-900">{{ item.name }}</span>
          </div>
        </template>
        <template #cell-default_doc_type_name="{ item }">
          <span class="text-xs font-mono text-ink-700">{{ item.default_doc_type_name ?? '—' }}</span>
        </template>
        <template #cell-access_flag="{ item }">
          <span class="capitalize text-xs font-semibold px-2 py-0.5 rounded-full bg-surface-100 text-ink-700">{{ item.access_flag }}</span>
        </template>
        <template #cell-document_count="{ item }">
          <span class="font-mono text-xs text-ink-800">{{ item.document_count }}</span>
        </template>
        <template #cell-child_count="{ item }">
          <span class="font-mono text-xs text-ink-800">{{ item.child_count }}</span>
        </template>
        <template #cell-actions="{ item }">
          <div class="flex items-center justify-end gap-3 text-xs font-semibold">
            <Link :href="route('dms.folders.edit', item.id)" class="text-accent hover:underline">Edit</Link>
            <button type="button" class="text-signal-danger hover:underline" @click="confirmDelete(item as FolderRow)">Delete</button>
          </div>
        </template>
      </DataTable>
    </div>
  </AppLayout>
</template>
