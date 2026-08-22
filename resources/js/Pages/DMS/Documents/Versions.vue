<!-- ponytail: DMS §3C Version History Viewer — full list (uploader, timestamp, size, checksum,
     note), restore-as-current, and compare-two-versions. Compare is entirely client-side: every
     version's metadata is already in `versions`, so no extra endpoint is needed for a metadata
     diff (content diffing is out of scope — these are opaque binary files, not text). -->
<script setup lang="ts">
import { ref, computed } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import { useConfirm } from '@/Composables/useConfirmDialog'

interface VersionRow {
  id: number
  version_no: number
  original_filename: string
  checksum_sha256: string
  file_size_bytes: number
  mime_type: string
  file_url: string
  uploaded_by_name: string | null
  uploaded_at_formatted: string | null
  version_note: string | null
  is_current: boolean
}

const props = defineProps<{
  document: { id: number; title: string }
  versions: VersionRow[]
}>()

const formatSize = (bytes: number) => {
  if (bytes >= 1024 * 1024) return `${(bytes / 1024 / 1024).toFixed(1)} MB`
  return `${Math.max(1, Math.round(bytes / 1024))} KB`
}

const { confirm } = useConfirm()
const restore = (version: VersionRow) => {
  confirm({
    title: `Restore v${version.version_no}?`,
    description: `Creates a new version pointing at v${version.version_no}'s file. History is never overwritten — the current version stays in the list.`,
    confirmText: 'Restore',
    onConfirm: () => router.post(route('dms.documents.versions.restore', [props.document.id, version.id]), {}, { preserveScroll: true }),
  })
}

// --- Compare two versions ---
const selected = ref<number[]>([])
const toggleSelect = (id: number) => {
  if (selected.value.includes(id)) {
    selected.value = selected.value.filter((v) => v !== id)
  } else if (selected.value.length < 2) {
    selected.value = [...selected.value, id]
  }
}

const compareRows = computed(() => {
  if (selected.value.length !== 2) return null
  const [a, b] = selected.value
    .map((id) => props.versions.find((v) => v.id === id)!)
    .sort((x, y) => x.version_no - y.version_no)

  const fields: Array<{ label: string; a: string; b: string }> = [
    { label: 'Filename', a: a.original_filename, b: b.original_filename },
    { label: 'Size', a: formatSize(a.file_size_bytes), b: formatSize(b.file_size_bytes) },
    { label: 'Checksum (SHA-256)', a: a.checksum_sha256, b: b.checksum_sha256 },
    { label: 'MIME type', a: a.mime_type, b: b.mime_type },
    { label: 'Uploaded by', a: a.uploaded_by_name ?? '—', b: b.uploaded_by_name ?? '—' },
    { label: 'Uploaded at', a: a.uploaded_at_formatted ?? '—', b: b.uploaded_at_formatted ?? '—' },
    { label: 'Note', a: a.version_note ?? '—', b: b.version_note ?? '—' },
  ]

  return { a, b, fields }
})
</script>

<template>
  <AppLayout>
    <PageHeader :title="`Version history — ${document.title}`" description="Every version ever uploaded, oldest history never overwritten.">
      <template #actions>
        <Link :href="route('dms.documents.edit', document.id)" class="text-sm font-medium text-accent hover:underline">← Back to document</Link>
      </template>
    </PageHeader>

    <Panel class="mt-6" title="Versions" subtitle="Select two rows to compare their metadata.">
      <table class="w-full text-sm">
        <thead>
          <tr class="border-b border-border text-left text-xs uppercase text-ink-600">
            <th class="w-8 py-2"></th>
            <th class="py-2">Version</th>
            <th class="py-2">File</th>
            <th class="py-2">Checksum</th>
            <th class="py-2">Size</th>
            <th class="py-2">Uploaded by</th>
            <th class="py-2">Note</th>
            <th class="py-2 text-right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="v in versions" :key="v.id" class="border-b border-border hover:bg-surface-50">
            <td class="py-2">
              <input
                type="checkbox"
                :checked="selected.includes(v.id)"
                :disabled="!selected.includes(v.id) && selected.length >= 2"
                class="h-4 w-4 rounded border-border"
                @change="toggleSelect(v.id)"
              />
            </td>
            <td class="py-2">
              <span class="font-medium text-ink-900">v{{ v.version_no }}</span>
              <span v-if="v.is_current" class="ml-1.5 rounded-full bg-signal-success/10 px-1.5 py-0.5 text-[10px] font-medium text-signal-success">current</span>
            </td>
            <td class="py-2 text-ink-700">
              {{ v.original_filename }}
              <div class="text-xs text-ink-500">{{ v.uploaded_at_formatted }}</div>
            </td>
            <td class="py-2 font-mono text-xs text-ink-600" :title="v.checksum_sha256">{{ v.checksum_sha256.slice(0, 12) }}…</td>
            <td class="py-2 text-ink-700">{{ formatSize(v.file_size_bytes) }}</td>
            <td class="py-2 text-ink-700">{{ v.uploaded_by_name ?? 'Unknown' }}</td>
            <td class="py-2 text-ink-600">{{ v.version_note ?? '—' }}</td>
            <td class="py-2 text-right">
              <a :href="v.file_url" target="_blank" class="mr-3 text-sm font-medium text-accent hover:underline">Download</a>
              <button v-if="!v.is_current" type="button" class="text-sm font-medium text-accent hover:underline" @click="restore(v)">Restore</button>
            </td>
          </tr>
        </tbody>
      </table>
    </Panel>

    <Panel v-if="compareRows" class="mt-6" title="Compare" :subtitle="`v${compareRows.a.version_no} vs v${compareRows.b.version_no}`">
      <table class="w-full text-sm">
        <thead>
          <tr class="border-b border-border text-left text-xs uppercase text-ink-600">
            <th class="py-2">Field</th>
            <th class="py-2">{{ `v${compareRows.a.version_no}` }}</th>
            <th class="py-2">{{ `v${compareRows.b.version_no}` }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="f in compareRows.fields" :key="f.label" class="border-b border-border">
            <td class="py-2 font-medium text-ink-900">{{ f.label }}</td>
            <td class="py-2 break-all" :class="f.a !== f.b ? 'text-signal-danger' : 'text-ink-700'">{{ f.a }}</td>
            <td class="py-2 break-all" :class="f.a !== f.b ? 'text-signal-danger' : 'text-ink-700'">{{ f.b }}</td>
          </tr>
        </tbody>
      </table>
    </Panel>
  </AppLayout>
</template>
