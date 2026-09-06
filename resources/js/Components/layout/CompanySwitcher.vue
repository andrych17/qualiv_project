<!-- ponytail: §3K — top-bar company context switcher. Only renders on an Accounting
     page (accountingCompanyContext is null everywhere else, see HandleInertiaRequests).
     Switching always lands on Accounts (the section's established home) rather than
     trying to stay on whatever page you're on — a Show/Edit screen for one company's
     specific record has no sensible "same page, different company" equivalent. Each
     page's own company selector still exists and still works: both write through
     CompanyContextService's session state, so they can't disagree (see its docblock). -->
<script setup lang="ts">
import { computed } from 'vue'
import { usePage, router } from '@inertiajs/vue3'
import { Building2 } from 'lucide-vue-next'

type CompanyContext = {
  companies: Array<{ id: number; legal_name: string }>
  currentCompanyId: number | null
}

const page = usePage()
const context = computed(() => page.props.accountingCompanyContext as CompanyContext | null)

const switchCompany = (e: Event) => {
  const companyId = (e.target as HTMLSelectElement).value
  router.get(route('accounting.accounts.index'), { company_id: companyId })
}
</script>

<template>
  <div v-if="context && context.companies.length" class="flex items-center gap-1.5 sm:gap-2">
    <Building2 class="h-4 w-4 shrink-0 text-ink-600 hidden xs:block" />
    <select
      :value="context.currentCompanyId"
      class="min-w-[120px] max-w-[160px] xs:max-w-[200px] sm:max-w-[240px] truncate rounded-md border border-border bg-surface-0 pl-2.5 pr-8 py-1.5 text-xs sm:text-sm text-ink-900 shadow-xs outline-none transition hover:bg-surface-50 focus:border-accent focus:ring-2 focus:ring-accent/20 cursor-pointer"
      @change="switchCompany"
    >
      <option v-for="c in context.companies" :key="c.id" :value="c.id" class="bg-surface-0 text-ink-900">{{ c.legal_name }}</option>
    </select>
  </div>
</template>
