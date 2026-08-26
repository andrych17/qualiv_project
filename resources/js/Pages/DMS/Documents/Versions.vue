<!-- ponytail: DMS §3C Version History Viewer — full list (uploader, timestamp, size, checksum,
     note), restore-as-current, and compare-two-versions. -->
<script setup lang="ts">
import { ref, computed } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
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
    description: `Creates a new version pointing at v${version.version_no}'s file. Version history is preserved permanently.`,
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
    { label: 'MIME Type', a: a.mime_type, b: b.mime_type },
    { label: 'Uploaded By', a: a.uploaded_by_name ?? '—', b: b.uploaded_by_name ?? '—' },
    { label: 'Uploaded At', a: a.uploaded_at_formatted ?? '—', b: b.uploaded_at_formatted ?? '—' },
    { label: 'Version Note', a: a.version_note ?? '—', b: b.version_note ?? '—' },
  ]

  return { a, b, fields }
})
</script>

<template>
  <AppLayout>
    <PageHeader :title="`Version History — ${document.title}`" description="Audit log of all uploaded file revisions with checksum validation and rollback capability.">
      <template #actions>
        <SecondaryButton :href="route('dms.documents.edit', document.id)">
          &larr; Back to Document
        </SecondaryButton>
      </template>
    </PageHeader>

    <Panel class="mt-6" title="Versions" subtitle="Select any two versions to compare metadata changes.">
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="border-b border-border bg-surface-50 text-left text-xs uppercase tracking-wider text-ink-600 font-semibold">
              <th class="w-10 px-3 py-3 text-center">Compare</th>
              <th class="px-3 py-3">Version</th>
              <th class="px-3 py-3">File Name</th>
              <th class="px-3 py-3">SHA-256 Checksum</th>
              <th class="px-3 py-3">File Size</th>
              <th class="px-3 py-3">Uploaded By</th>
              <th class="px-3 py-3">Version Note</th>
              <th class="px-3 py-3 text-right">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-border bg-surface">
            <tr v-for="v in versions" :key="v.id" class="hover:bg-surface-50/50 transition-colors">
              <td class="px-3 py-3 text-center">
                <input
                  type="checkbox"
                  :checked="selected.includes(v.id)"
                  :disabled="!selected.includes(v.id) && selected.length >= 2"
                  class="h-4 w-4 rounded border-border text-accent focus:ring-accent/20 cursor-pointer"
                  @change="toggleSelect(v.id)"
                />
              </td>
              <td class="px-3 py-3">
                <div class="flex items-center gap-2">
                  <span class="font-bold text-ink-900">v{{ v.version_no }}</span>
                  <StatusBadge v-if="v.is_current" status="active" label="Current" />
                </div>
              </td>
              <td class="px-3 py-3 text-ink-700">
                <span class="font-medium text-ink-900">{{ v.original_filename }}</span>
                <div class="text-xs text-ink-500 font-mono">{{ v.uploaded_at_formatted }}</div>
              </td>
              <td class="px-3 py-3 font-mono text-xs text-ink-600" :title="v.checksum_sha256">{{ v.checksum_sha256.slice(0, 14) }}…</td>
              <td class="px-3 py-3 font-mono text-xs text-ink-700">{{ formatSize(v.file_size_bytes) }}</td>
              <td class="px-3 py-3 text-ink-700">{{ v.uploaded_by_name ?? 'Unknown' }}</td>
              <td class="px-3 py-3 text-xs text-ink-600">{{ v.version_note ?? '—' }}</td>
              <td class="px-3 py-3 text-right">
                <div class="flex items-center justify-end gap-3 text-xs font-semibold">
                  <a :href="v.file_url" target="_blank" class="text-accent hover:underline">Download</a>
                  <button v-if="!v.is_current" type="button" class="text-accent hover:underline" @click="restore(v)">Restore</button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </Panel>

    <Panel v-if="compareRows" class="mt-6" title="Version Comparison" :subtitle="`Comparing v${compareRows.a.version_no} vs v${compareRows.b.version_no}`">
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="border-b border-border bg-surface-50 text-left text-xs uppercase tracking-wider text-ink-600 font-semibold">
              <th class="w-1/4 px-4 py-3">Field</th>
              <th class="w-3/8 px-4 py-3">{{ `v${compareRows.a.version_no}` }}</th>
              <th class="w-3/8 px-4 py-3">{{ `v${compareRows.b.version_no}` }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-border bg-surface">
            <tr v-for="f in compareRows.fields" :key="f.label" class="hover:bg-surface-50/50">
              <td class="px-4 py-3 font-medium text-ink-900">{{ f.label }}</td>
              <td class="px-4 py-3 break-all font-mono text-xs" :class="f.a !== f.b ? 'text-signal-danger font-semibold bg-signal-danger/5' : 'text-ink-700'">{{ f.a }}</td>
              <td class="px-4 py-3 break-all font-mono text-xs" :class="f.a !== f.b ? 'text-signal-danger font-semibold bg-signal-danger/5' : 'text-ink-700'">{{ f.b }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </Panel>
  </AppLayout>
</template>
