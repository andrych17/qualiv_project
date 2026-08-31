<!-- ponytail: DMS §3A Main Dashboard — summary cards + tabbed table, mirrors WNE's own
     dashboard (resources/js/Pages/WNE/Dashboard/Index.vue). Every tab is a capped,
     read-only preview — the full browse/filter/upload page is DMS/Documents/Index.vue
     (DocumentController::index()), which this dashboard links out to via the "flag" filter
     it already supports, not a duplicate implementation. -->
<script setup lang="ts">
import { ref } from 'vue'
import { Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import StatCard from '@/Components/cards/StatCard.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'

interface DocumentRow {
  id: number
  title: string
  folder_name: string | null
  doc_type_name: string | null
  status: string
  rail: 'success' | 'warning' | 'danger' | 'neutral'
  expiry_date_formatted: string | null
  created_at_formatted: string | null
}

const props = defineProps<{
  summary: { total_documents: number; active_documents: number; expiring_soon: number; on_legal_hold: number }
  recentUploads: DocumentRow[]
  expiringSoon: DocumentRow[]
  onLegalHold: DocumentRow[]
}>()

const railClass = (rail: string) => {
  const map: Record<string, string> = {
    danger: 'border-l-[3px] border-l-signal-danger',
    warning: 'border-l-[3px] border-l-signal-warning',
    success: 'border-l-[3px] border-l-signal-success',
  }
  return map[rail] ?? 'border-l-[3px] border-l-border'
}

const tabs = ['Recent Uploads', 'Expiring Soon', 'On Legal Hold'] as const
const activeTab = ref<(typeof tabs)[number]>('Recent Uploads')

const currentRows = () => {
  if (activeTab.value === 'Expiring Soon') return props.expiringSoon
  if (activeTab.value === 'On Legal Hold') return props.onLegalHold
  return props.recentUploads
}
</script>

<template>
  <AppLayout>
    <PageHeader title="DMS Dashboard" description="Library health at a glance — open Documents to browse, upload, or drill into anything here.">
      <template #actions>
        <PrimaryButton :href="route('dms.documents.create')">Upload</PrimaryButton>
      </template>
    </PageHeader>

    <div class="mt-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
      <StatCard title="Total Documents" :value="String(summary.total_documents)" icon="FileText" href="/dms/documents" />
      <StatCard title="Active" :value="String(summary.active_documents)" icon="CheckCircle2" href="/dms/documents?status=active" />
      <StatCard title="Expiring Soon" :value="String(summary.expiring_soon)" icon="Clock" href="/dms/documents?flag=expiring_soon" />
      <StatCard title="On Legal Hold" :value="String(summary.on_legal_hold)" icon="Lock" href="/dms/documents?flag=on_legal_hold" />
    </div>

    <Panel class="mt-6">
      <div class="mb-4 flex gap-2 border-b border-border">
        <button
          v-for="tab in tabs"
          :key="tab"
          type="button"
          class="border-b-2 px-3 py-2 text-sm font-medium"
          :class="activeTab === tab ? 'border-accent text-ink-900' : 'border-transparent text-ink-600 hover:text-ink-900'"
          @click="activeTab = tab"
        >
          {{ tab }}
        </button>
      </div>

      <table class="w-full text-sm">
        <tbody>
          <tr v-for="d in currentRows()" :key="d.id" class="border-b border-border hover:bg-surface-50" :class="railClass(d.rail)">
            <td class="py-2 pl-3">
              <Link :href="route('dms.documents.index', { search: d.title })" class="font-medium text-ink-900 hover:underline">{{ d.title }}</Link>
              <span v-if="d.folder_name" class="text-xs text-ink-600"> — {{ d.folder_name }}</span>
            </td>
            <td class="py-2 text-xs text-ink-600">{{ d.doc_type_name ?? '—' }}</td>
            <td class="py-2"><StatusBadge :status="d.status" /></td>
            <td class="py-2 text-xs text-ink-600">
              {{ activeTab === 'Expiring Soon' ? (d.expiry_date_formatted ?? '—') : (d.created_at_formatted ?? '—') }}
            </td>
          </tr>
          <tr v-if="!currentRows().length">
            <td class="py-4 text-ink-600">
              {{ activeTab === 'Recent Uploads' ? 'No documents uploaded yet.' : activeTab === 'Expiring Soon' ? 'Nothing expiring in the next 30 days.' : 'Nothing on legal hold.' }}
            </td>
          </tr>
        </tbody>
      </table>

      <div class="mt-4 flex justify-end border-t border-border pt-4 text-sm">
        <Link :href="route('dms.documents.index')" class="font-medium text-accent hover:underline">View all in Documents →</Link>
      </div>
    </Panel>
  </AppLayout>
</template>
