<!-- ponytail: DMS §3A Document Library — folder tree + filterable document table, and a
     row-click drawer (metadata/versions/audit/relations tabs) fetched the same way CRM's own
     dashboard drawer works (resources/js/Pages/CRM/Dashboard/Index.vue). This used to be
     rendered for both /dms/dashboard and /dms/documents (DocumentController::index() was the
     only DMS route with a real page) — DmsDashboardController now owns the lightweight
     summary landing page instead, so this is purely the browse/upload/drill-in page.
     "Upload" links to the §3B dedicated entry form (DMS/Documents/Create.vue), same
     "button links to Create page" convention as CRM ServiceCases — the inline quick-upload
     panel this page used before §3B existed has been removed in favor of that fuller form.
     The Versions tab is a capped (5) preview with a "View full history" link out to the §3C
     dedicated Version History page (DMS/Documents/Versions.vue), same "dashboard tab links to
     the fuller page" convention WNE's dashboard uses for My Approvals/Dead Letters. Folder
     CRUD is managed at DMS/Folders/Index.vue (§3D, linked from the Folders panel below).
     The search box here is §3E full-text search (Postgres tsvector, ranked) — no UI change
     was needed, DocumentController::index()/Document::scopeFilter() do the ranking. The
     Relations tab's "Add relation" form is §3H — submits via Inertia and then closes the
     drawer on success (same "mutate then close, don't try to keep the drawer open with fresh
     data" convention CRM's own dashboard drawer uses for its status-change form). -->
<script setup lang="ts">
import { ref, watch } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import { UploadCloud } from 'lucide-vue-next'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import StatCard from '@/Components/cards/StatCard.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import DataTable, { type FilterFieldDef, type SortState } from '@/Components/tables/DataTable.vue'
import FolderTreeNode, { type FolderNode } from '@/Components/dms/FolderTreeNode.vue'
import FormAsyncSearchableSelect from '@/Components/forms/FormAsyncSearchableSelect.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import { debounce } from '@/Composables/debounce'

interface DocumentRow {
  id: number
  title: string
  folder_name: string | null
  doc_type_name: string | null
  status: string
  rail: 'success' | 'warning' | 'danger' | 'neutral'
  legal_hold: boolean
  version_count: number
  current_filename: string | null
  current_size_bytes: number | null
  expiry_date_formatted: string | null
  created_at_formatted: string | null
}

interface PaginatedData<T> {
  data: T[]
  links: Array<{ url: string | null; label: string; active: boolean }>
  total: number
  from: number | null
  to: number | null
  per_page: number
}

const props = defineProps<{
  documents: PaginatedData<DocumentRow>
  filters: { search?: string; folder_id?: string; doc_type_id?: string; status?: string; flag?: string; sort?: string; direction?: string; per_page?: string }
  summary: { total_documents: number; expiring_soon: number; on_legal_hold: number; active_documents: number }
  folders: FolderNode[]
  docTypes: Array<{ id: number; name: string }>
}>()

// --- Table / filters ---
const search = ref(props.filters.search ?? '')
const filters = ref({
  doc_type_id: props.filters.doc_type_id ?? '',
  status: props.filters.status ?? '',
  flag: props.filters.flag ?? '',
})
const sort = ref<SortState>(
  props.filters.sort ? { key: props.filters.sort, direction: props.filters.direction === 'desc' ? 'desc' : 'asc' } : null,
)
const perPage = ref(Number(props.filters.per_page) || props.documents.per_page)
const activeFolderId = ref<number | null>(props.filters.folder_id ? Number(props.filters.folder_id) : null)

const filterFields: FilterFieldDef[] = [
  { key: 'doc_type_id', label: 'Doc type', type: 'select', options: props.docTypes.map((t) => ({ label: t.name, value: String(t.id) })) },
  {
    key: 'status',
    label: 'Status',
    type: 'select',
    options: [
      { label: 'Draft', value: 'draft' },
      { label: 'Active', value: 'active' },
      { label: 'Archived', value: 'archived' },
      { label: 'Expired', value: 'expired' },
      { label: 'Purged', value: 'purged' },
    ],
  },
  {
    key: 'flag',
    label: 'Flag',
    type: 'select',
    options: [
      { label: 'Expiring soon', value: 'expiring_soon' },
      { label: 'On legal hold', value: 'on_legal_hold' },
    ],
  },
]

const columns = [
  { key: 'title', label: 'Title', sortable: true },
  { key: 'folder_name', label: 'Folder' },
  { key: 'doc_type_name', label: 'Type' },
  { key: 'status', label: 'Status' },
  { key: 'current_filename', label: 'Current file' },
  { key: 'version_count', label: 'Versions', align: 'right' as const },
  { key: 'expiry_date_formatted', label: 'Expiry', sortable: true, sortKey: 'expiry_date' },
]

watch([search, filters, sort, perPage, activeFolderId], debounce(() => {
  router.get(route('dms.documents.index'), {
    search: search.value,
    doc_type_id: filters.value.doc_type_id,
    status: filters.value.status,
    flag: filters.value.flag,
    folder_id: activeFolderId.value ?? '',
    sort: sort.value?.key,
    direction: sort.value?.direction,
    per_page: perPage.value,
  }, { preserveState: true, replace: true })
}, 400))

const selectFolder = (id: number) => {
  activeFolderId.value = activeFolderId.value === id ? null : id
}

const formatSize = (bytes: number | null) => {
  if (bytes === null) return '—'
  if (bytes >= 1024 * 1024) return `${(bytes / 1024 / 1024).toFixed(1)} MB`
  return `${Math.max(1, Math.round(bytes / 1024))} KB`
}

// --- Row-click drawer (§3A: metadata / version history / audit log / relations) ---
type DrawerTab = 'Metadata' | 'Versions' | 'Audit Log' | 'Relations'
interface DrawerData {
  document: {
    id: number; title: string; description: string | null; folder_name: string | null
    doc_type_name: string | null; status: string; legal_hold: boolean
    subject_type: string | null; subject_id: number | null
    effective_date_formatted: string | null; expiry_date_formatted: string | null
    tags: string[]
  }
  versions: Array<{ id: number; version_no: number; original_filename: string; file_size_bytes: number; mime_type: string; file_url: string; uploaded_by_name: string | null; uploaded_at_formatted: string | null; version_note: string | null; is_current: boolean }>
  versionCount: number
  auditLog: Array<{ id: number; action: string; actor_name: string | null; created_at_formatted: string | null }>
  auditLogCount: number
  relations: Array<{ id: number; relation_type: string; document_title: string | null }>
}

const drawer = ref<DrawerData | null>(null)
const drawerLoading = ref(false)
const drawerTab = ref<DrawerTab>('Metadata')

const openDrawer = async (id: number) => {
  drawerLoading.value = true
  drawerTab.value = 'Metadata'
  try {
    const response = await fetch(route('dms.documents.show', id))
    drawer.value = await response.json()
  } finally {
    drawerLoading.value = false
  }
}

// --- §3H Object Relation Engine — add/remove a link from the currently open document ---
const relationTypes = [
  { label: 'Amendment of', value: 'amendment_of' },
  { label: 'Supersedes', value: 'supersedes' },
  { label: 'Attachment of', value: 'attachment_of' },
  { label: 'Related to', value: 'related_to' },
]

const relationForm = useForm({ target_document_id: null as number | null, relation_type: 'related_to' })

const addRelation = () => {
  if (!drawer.value || !relationForm.target_document_id) return
  relationForm.post(route('dms.documents.relations.store', drawer.value.document.id), {
    preserveScroll: true,
    onSuccess: () => { relationForm.reset(); drawer.value = null },
  })
}

const removeRelation = (relationId: number) => {
  if (!drawer.value) return
  router.delete(route('dms.documents.relations.destroy', [drawer.value.document.id, relationId]), {
    preserveScroll: true,
    onSuccess: () => { drawer.value = null },
  })
}

</script>

<template>
  <AppLayout>
    <PageHeader title="Document Library" description="Browse, upload, and track every document across the tenant.">
      <template #actions>
        <Link :href="route('dms.audit-log')" class="mr-4 text-sm font-medium text-accent hover:underline">Audit trail →</Link>
        <PrimaryButton :href="route('dms.documents.create', activeFolderId ? { folder_id: activeFolderId } : {})">
          <UploadCloud class="mr-1.5 h-4 w-4" /> Upload
        </PrimaryButton>
      </template>
    </PageHeader>

    <div class="mt-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
      <StatCard title="Total Documents" :value="String(summary.total_documents)" icon="FileText" />
      <StatCard title="Active" :value="String(summary.active_documents)" icon="CheckCircle2" />
      <StatCard title="Expiring Soon" :value="String(summary.expiring_soon)" icon="Clock" />
      <StatCard title="On Legal Hold" :value="String(summary.on_legal_hold)" icon="Lock" />
    </div>

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-4">
      <Panel title="Folders" class="lg:col-span-1">
        <template #actions>
          <Link :href="route('dms.folders.index')" class="text-xs font-medium text-accent hover:underline">Manage →</Link>
        </template>
        <ul class="space-y-0.5">
          <li>
            <button
              type="button"
              class="w-full rounded-sm px-2 py-1.5 text-left text-sm transition hover:bg-surface-50"
              :class="activeFolderId === null ? 'bg-accent/10 font-medium text-accent' : 'text-ink-700'"
              @click="activeFolderId = null"
            >
              All documents
            </button>
          </li>
          <FolderTreeNode
            v-for="node in folders"
            :key="node.id"
            :node="node"
            :active-id="activeFolderId"
            @select="selectFolder"
          />
        </ul>
      </Panel>

      <div class="lg:col-span-3">
        <DataTable
          :columns="columns"
          :items="documents.data"
          v-model:sort="sort"
          v-model:search="search"
          v-model:filters="filters"
          v-model:per-page="perPage"
          sticky-header
          status-rail-key="rail"
          storage-key="dms.documents"
          search-placeholder="Search title, filename, description, or tags…"
          :filter-fields="filterFields"
          export-filename="dms-documents"
          :total="documents.total"
          :from="documents.from"
          :to="documents.to"
          :links="documents.links"
          empty-title="No documents yet"
          empty-description="Upload your first document to start building the library."
        >
          <template #cell-title="{ item }">
            <button type="button" class="text-left font-medium text-ink-900 hover:underline" @click="openDrawer((item as DocumentRow).id)">
              {{ (item as DocumentRow).title }}
            </button>
            <span v-if="(item as DocumentRow).legal_hold" class="ml-1.5 text-xs text-signal-danger">(hold)</span>
          </template>
          <template #cell-status="{ item }">
            <StatusBadge :status="(item as DocumentRow).status" />
          </template>
          <template #cell-current_filename="{ item }">
            <span class="text-xs text-ink-600">{{ (item as DocumentRow).current_filename ?? '—' }}</span>
            <span v-if="(item as DocumentRow).current_size_bytes" class="text-xs text-ink-500"> · {{ formatSize((item as DocumentRow).current_size_bytes) }}</span>
          </template>
        </DataTable>
      </div>
    </div>

    <!-- Row-click drawer -->
    <div v-if="drawer || drawerLoading" class="fixed inset-0 z-50 flex justify-end bg-black/30" @click.self="drawer = null">
      <div class="h-full w-full max-w-md overflow-y-auto bg-surface-0 p-6 shadow-lg">
        <button type="button" class="text-sm text-ink-600 hover:text-ink-900" @click="drawer = null">Close</button>

        <template v-if="drawerLoading">
          <p class="mt-4 text-sm text-ink-600">Loading…</p>
        </template>
        <template v-else-if="drawer">
          <div class="mt-4 flex items-start justify-between gap-3">
            <h3 class="font-serif text-lg font-semibold text-ink-900">{{ drawer.document.title }}</h3>
            <Link :href="route('dms.documents.edit', drawer.document.id)" class="shrink-0 text-sm font-medium text-accent hover:underline">Edit</Link>
          </div>
          <p v-if="drawer.document.folder_name" class="mt-1 text-sm text-ink-600">{{ drawer.document.folder_name }}</p>
          <div class="mt-2 flex flex-wrap gap-2">
            <StatusBadge :status="drawer.document.status" />
            <span v-if="drawer.document.legal_hold" class="inline-flex items-center rounded-full border border-signal-danger/25 bg-signal-danger/10 px-2.5 py-0.5 text-xs font-medium text-signal-danger">Legal hold</span>
          </div>

          <div class="mt-4 flex gap-2 border-b border-border text-sm">
            <button
              v-for="tab in (['Metadata', 'Versions', 'Audit Log', 'Relations'] as DrawerTab[])"
              :key="tab"
              type="button"
              class="border-b-2 px-2 py-2 font-medium"
              :class="drawerTab === tab ? 'border-accent text-ink-900' : 'border-transparent text-ink-600 hover:text-ink-900'"
              @click="drawerTab = tab"
            >
              {{ tab }}
            </button>
          </div>

          <div v-if="drawerTab === 'Metadata'" class="mt-4 space-y-2 text-sm">
            <p v-if="drawer.document.description" class="text-ink-700">{{ drawer.document.description }}</p>
            <dl class="space-y-1 text-xs">
              <div class="flex justify-between"><dt class="text-ink-600">Doc type</dt><dd class="text-ink-900">{{ drawer.document.doc_type_name ?? '—' }}</dd></div>
              <div class="flex justify-between"><dt class="text-ink-600">Effective date</dt><dd class="text-ink-900">{{ drawer.document.effective_date_formatted ?? '—' }}</dd></div>
              <div class="flex justify-between"><dt class="text-ink-600">Expiry date</dt><dd class="text-ink-900">{{ drawer.document.expiry_date_formatted ?? '—' }}</dd></div>
              <div v-if="drawer.document.subject_type" class="flex justify-between"><dt class="text-ink-600">Owning record</dt><dd class="text-ink-900">{{ drawer.document.subject_type }}#{{ drawer.document.subject_id }}</dd></div>
            </dl>
            <div v-if="drawer.document.tags.length" class="flex flex-wrap gap-1.5 pt-2">
              <span v-for="tag in drawer.document.tags" :key="tag" class="rounded-full bg-surface-50 px-2 py-0.5 text-xs text-ink-600">{{ tag }}</span>
            </div>
          </div>

          <div v-else-if="drawerTab === 'Versions'">
            <!-- Quick preview (§3A): inline for image/PDF current version, metadata + download link otherwise. -->
            <template v-if="drawer.versions.find((v) => v.is_current)">
              <img
                v-if="drawer.versions.find((v) => v.is_current)!.mime_type.startsWith('image/')"
                :src="drawer.versions.find((v) => v.is_current)!.file_url"
                class="mt-4 max-h-64 w-full rounded-md border border-border object-contain"
                alt="Current version preview"
              />
              <iframe
                v-else-if="drawer.versions.find((v) => v.is_current)!.mime_type === 'application/pdf'"
                :src="drawer.versions.find((v) => v.is_current)!.file_url"
                class="mt-4 h-64 w-full rounded-md border border-border"
              />
            </template>

            <ul class="mt-4 space-y-2 text-sm">
              <li v-for="v in drawer.versions" :key="v.id" class="border-b border-border pb-2">
                <div class="flex items-center justify-between">
                  <span class="font-medium text-ink-900">v{{ v.version_no }} — {{ v.original_filename }}</span>
                  <span v-if="v.is_current" class="text-xs text-signal-success">current</span>
                </div>
                <p class="text-xs text-ink-600">{{ formatSize(v.file_size_bytes) }} · {{ v.uploaded_by_name ?? 'Unknown' }} · {{ v.uploaded_at_formatted }}</p>
                <p v-if="v.version_note" class="text-xs text-ink-600">{{ v.version_note }}</p>
                <a :href="v.file_url" target="_blank" class="text-xs font-medium text-accent hover:underline">Open / download</a>
              </li>
              <li v-if="!drawer.versions.length" class="text-ink-600">No versions yet.</li>
            </ul>
            <Link
              v-if="drawer.versionCount > drawer.versions.length"
              :href="route('dms.documents.versions', drawer.document.id)"
              class="mt-3 block text-sm font-medium text-accent hover:underline"
            >
              View full history ({{ drawer.versionCount }}) →
            </Link>
          </div>

          <div v-else-if="drawerTab === 'Audit Log'">
            <ul class="mt-4 space-y-2 text-sm">
              <li v-for="log in drawer.auditLog" :key="log.id" class="border-b border-border pb-2">
                <span class="font-medium capitalize text-ink-900">{{ log.action.replace(/_/g, ' ') }}</span>
                <span class="text-xs text-ink-600"> — {{ log.actor_name ?? 'System' }} · {{ log.created_at_formatted }}</span>
              </li>
              <li v-if="!drawer.auditLog.length" class="text-ink-600">No activity recorded yet.</li>
            </ul>
            <Link
              v-if="drawer.auditLogCount > drawer.auditLog.length"
              :href="route('dms.audit-log', { document_id: drawer.document.id })"
              class="mt-3 block text-sm font-medium text-accent hover:underline"
            >
              View full audit trail ({{ drawer.auditLogCount }}) →
            </Link>
          </div>

          <div v-else class="mt-4">
            <ul class="space-y-2 text-sm">
              <li v-for="rel in drawer.relations" :key="rel.id" class="flex items-center justify-between border-b border-border pb-2">
                <div>
                  <span class="text-xs uppercase text-ink-500">{{ rel.relation_type.replace(/_/g, ' ') }}</span>
                  <span class="ml-1 text-ink-900">{{ rel.document_title }}</span>
                </div>
                <button type="button" class="text-xs font-medium text-signal-danger hover:underline" @click="removeRelation(rel.id)">Remove</button>
              </li>
              <li v-if="!drawer.relations.length" class="text-ink-600">No related documents.</li>
            </ul>

            <form class="mt-4 space-y-3 border-t border-border pt-4" @submit.prevent="addRelation">
              <p class="text-xs font-medium uppercase text-ink-600">Add relation</p>
              <FormAsyncSearchableSelect
                v-model="relationForm.target_document_id"
                name="target_document_id"
                label="Related document"
                api-entity="dms_document"
                :extra-params="{ exclude_id: drawer.document.id }"
                placeholder="Search documents…"
                :error="relationForm.errors.target_document_id"
              />
              <FormSelect v-model="relationForm.relation_type" name="relation_type" label="Relation type" :options="relationTypes" :error="relationForm.errors.relation_type" required />
              <button
                type="submit"
                class="w-full rounded-sm bg-accent px-3 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-accent/90 disabled:opacity-50"
                :disabled="relationForm.processing || !relationForm.target_document_id"
              >
                Add relation
              </button>
            </form>
          </div>
        </template>
      </div>
    </div>
  </AppLayout>
</template>
