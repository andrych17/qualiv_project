<!-- ponytail: Accounting §3S review queue — "fails loudly and queues for review rather than
     posting to a suspense account silently" (spec rule). Retry re-attempts posting after the
     mapping/period problem behind a row is fixed. -->
<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'

interface FailureRow {
  id: number
  subject_id: string
  reason: string
  status: 'pending' | 'resolved'
  created_at: string
  resolved_at: string | null
}

const props = defineProps<{
  companies: Array<{ id: number; legal_name: string }>
  selectedCompanyId: number | null
  failures: FailureRow[]
}>()

const switchCompany = (e: Event) => router.get(route('accounting.payroll-posting-failures.index'), { company_id: (e.target as HTMLSelectElement).value }, { preserveState: true })

const retry = (f: FailureRow) => router.post(route('accounting.payroll-posting-failures.retry', f.id), {}, { preserveScroll: true })
</script>

<template>
  <AppLayout>
    <PageHeader title="Payroll posting review queue" description="Payroll runs that couldn't post — an unmapped component, an incomplete employer-cost mapping, no Net Pay Payable control account, or no fiscal period covering the date. Fix the underlying problem, then Retry.">
      <template #actions>
        <Link :href="route('accounting.payroll-component-gl-mappings.index', { company_id: selectedCompanyId })" class="text-sm font-medium text-accent hover:underline">GL mappings</Link>
      </template>
    </PageHeader>

    <Panel class="mt-6">
      <div class="mb-4 flex flex-wrap items-center gap-3">
        <select :value="selectedCompanyId" class="rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm text-ink-900 shadow-sm focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/20" @change="switchCompany">
          <option v-for="c in companies" :key="c.id" :value="c.id">{{ c.legal_name }}</option>
        </select>
      </div>

      <table class="w-full text-sm">
        <thead>
          <tr class="border-b border-border text-left text-xs uppercase text-ink-600">
            <th class="py-2">Run</th>
            <th class="py-2">Reason</th>
            <th class="py-2">Status</th>
            <th class="py-2">Created</th>
            <th class="py-2"></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="f in failures" :key="f.id" class="border-b border-border hover:bg-surface-50">
            <td class="py-2 text-ink-900">{{ f.subject_id }}</td>
            <td class="py-2 text-ink-700">{{ f.reason }}</td>
            <td class="py-2"><StatusBadge :status="f.status" /></td>
            <td class="py-2 text-ink-600">{{ f.created_at }}</td>
            <td class="py-2 text-right">
              <button v-if="f.status === 'pending'" type="button" class="text-sm font-medium text-accent hover:underline" @click="retry(f)">Retry</button>
              <span v-else class="text-xs text-ink-600">Resolved {{ f.resolved_at }}</span>
            </td>
          </tr>
          <tr v-if="!failures.length"><td colspan="5" class="py-6 text-center text-ink-600">Nothing queued for review.</td></tr>
        </tbody>
      </table>
    </Panel>
  </AppLayout>
</template>
