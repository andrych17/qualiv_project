<!-- ponytail: Accounting §3F bank statement imports — plain company-scoped list. -->
<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'

interface ImportRow {
  id: number
  bank_account_name: string | null
  original_filename: string
  line_count: number
  imported_at: string
}

const props = defineProps<{
  companies: Array<{ id: number; legal_name: string }>
  selectedCompanyId: number | null
  imports: ImportRow[]
}>()

const switchCompany = (e: Event) => {
  const companyId = (e.target as HTMLSelectElement).value
  router.get(route('accounting.bank-statement-imports.index'), { company_id: companyId }, { preserveState: true })
}
</script>

<template>
  <AppLayout>
    <PageHeader title="Bank Statement Imports" description="Staged for future reconciliation — matching against journal entries isn't built yet.">
      <template #actions>
        <PrimaryButton :href="route('accounting.bank-statement-imports.create', { company_id: selectedCompanyId })">Import statement</PrimaryButton>
      </template>
    </PageHeader>

    <Panel class="mt-6">
      <select
        :value="selectedCompanyId"
        class="mb-4 rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm text-ink-900 shadow-sm focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/20"
        @change="switchCompany"
      >
        <option v-for="c in companies" :key="c.id" :value="c.id">{{ c.legal_name }}</option>
      </select>

      <table class="w-full text-sm">
        <thead>
          <tr class="border-b border-border text-left text-xs uppercase text-ink-600">
            <th class="py-2">Bank account</th>
            <th class="py-2">File</th>
            <th class="py-2 text-right">Lines</th>
            <th class="py-2">Imported at</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="i in imports" :key="i.id" class="border-b border-border hover:bg-surface-50">
            <td class="py-2 text-ink-900">{{ i.bank_account_name ?? '—' }}</td>
            <td class="py-2">
              <Link :href="route('accounting.bank-statement-imports.show', i.id)" class="font-medium text-accent hover:underline">{{ i.original_filename }}</Link>
            </td>
            <td class="py-2 text-right text-ink-700">{{ i.line_count }}</td>
            <td class="py-2 text-ink-700">{{ i.imported_at }}</td>
          </tr>
          <tr v-if="!imports.length"><td colspan="4" class="py-6 text-center text-ink-600">No imports yet.</td></tr>
        </tbody>
      </table>
    </Panel>
  </AppLayout>
</template>
