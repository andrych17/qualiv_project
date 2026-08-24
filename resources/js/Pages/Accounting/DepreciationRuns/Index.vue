<!-- ponytail: Accounting §3G monthly depreciation batch trigger — manual "run for period" v1, see DepreciationRunController docblock. -->
<script setup lang="ts">
import { useForm, Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormSearchableSelect from '@/Components/forms/FormSearchableSelect.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'

const props = defineProps<{
  companies: Array<{ id: number; legal_name: string }>
  selectedCompanyId: number | null
  periods: Array<{ value: number; label: string }>
  recentJournals: Array<{ id: number; journal_date: string; memo: string | null }>
}>()

const switchCompany = (e: Event) => {
  const companyId = (e.target as HTMLSelectElement).value
  router.get(route('accounting.depreciation-runs.index'), { company_id: companyId }, { preserveState: true })
}

const form = useForm({ fiscal_period_id: null as number | null })
const submit = () => form.post(route('accounting.depreciation-runs.store'))
</script>

<template>
  <AppLayout>
    <PageHeader title="Depreciation Runs" description="Posts commercial depreciation to the GL and records the parallel fiscal schedule for the selected period. Safe to re-run — already-scheduled assets are skipped.">
      <template #actions>
        <Link :href="route('accounting.fixed-assets.index', { company_id: selectedCompanyId })" class="text-sm font-medium text-accent hover:underline">← Back to assets</Link>
      </template>
    </PageHeader>

    <Panel class="mt-6 max-w-xl">
      <select
        :value="selectedCompanyId"
        class="mb-4 rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm text-ink-900 shadow-sm focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/20"
        @change="switchCompany"
      >
        <option v-for="c in companies" :key="c.id" :value="c.id">{{ c.legal_name }}</option>
      </select>

      <form class="space-y-4" @submit.prevent="submit">
        <FormSearchableSelect v-model="form.fiscal_period_id" name="fiscal_period_id" label="Fiscal period" :options="periods" :error="form.errors.fiscal_period_id" required />
        <div class="flex justify-end">
          <PrimaryButton type="submit" :disabled="form.processing || !form.fiscal_period_id">Run depreciation</PrimaryButton>
        </div>
      </form>
    </Panel>

    <Panel class="mt-6">
      <div class="border-b border-border px-4 py-3 text-sm font-semibold text-ink-900">Recent depreciation journals</div>
      <table class="w-full text-sm">
        <thead>
          <tr class="border-b border-border text-left text-xs uppercase text-ink-600">
            <th class="px-4 py-2">Date</th>
            <th class="px-4 py-2">Memo</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="j in recentJournals" :key="j.id" class="border-b border-border">
            <td class="px-4 py-2 text-ink-700">{{ j.journal_date }}</td>
            <td class="px-4 py-2">
              <Link :href="route('accounting.journals.show', j.id)" class="text-accent hover:underline">{{ j.memo ?? `Journal #${j.id}` }}</Link>
            </td>
          </tr>
          <tr v-if="!recentJournals.length"><td colspan="2" class="px-4 py-6 text-center text-ink-600">No depreciation runs yet.</td></tr>
        </tbody>
      </table>
    </Panel>
  </AppLayout>
</template>
