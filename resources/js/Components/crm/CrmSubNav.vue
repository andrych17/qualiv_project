<!-- ponytail: Contacts/Companies/Leads/... switcher — one CRM menu row covers all CRM
     routes, so the second-level nav lives here rather than as separate sidebar entries
     (SysConfigSeeder). Merge tab only renders for users the backend actually lets in —
     the route's own menu.perm:CRM_MERGE middleware is the real gate either way. -->
<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3'

defineProps<{
  active: 'dashboard' | 'contacts' | 'companies' | 'leads' | 'service-cases' | 'tickets' | 'merge'
}>()

const canMergePartners = () => !!(usePage().props as { canMergePartners?: boolean }).canMergePartners
</script>

<template>
  <div class="flex items-center gap-1 border-b border-border" role="tablist">
    <Link
      :href="route('crm.dashboard')"
      role="tab"
      class="border-b-2 px-3 py-2 text-sm font-medium transition"
      :class="active === 'dashboard' ? 'border-accent text-ink-900' : 'border-transparent text-ink-600 hover:text-ink-900'"
    >
      Dashboard
    </Link>
    <Link
      :href="route('crm.contacts.index')"
      role="tab"
      class="border-b-2 px-3 py-2 text-sm font-medium transition"
      :class="active === 'contacts' ? 'border-accent text-ink-900' : 'border-transparent text-ink-600 hover:text-ink-900'"
    >
      Contacts
    </Link>
    <Link
      :href="route('crm.companies.index')"
      role="tab"
      class="border-b-2 px-3 py-2 text-sm font-medium transition"
      :class="active === 'companies' ? 'border-accent text-ink-900' : 'border-transparent text-ink-600 hover:text-ink-900'"
    >
      Companies
    </Link>
    <Link
      :href="route('crm.leads.index')"
      role="tab"
      class="border-b-2 px-3 py-2 text-sm font-medium transition"
      :class="active === 'leads' ? 'border-accent text-ink-900' : 'border-transparent text-ink-600 hover:text-ink-900'"
    >
      Leads
    </Link>
    <Link
      :href="route('crm.serviceCases.index')"
      role="tab"
      class="border-b-2 px-3 py-2 text-sm font-medium transition"
      :class="active === 'service-cases' ? 'border-accent text-ink-900' : 'border-transparent text-ink-600 hover:text-ink-900'"
    >
      Service Cases
    </Link>
    <Link
      :href="route('crm.tickets.index')"
      role="tab"
      class="border-b-2 px-3 py-2 text-sm font-medium transition"
      :class="active === 'tickets' ? 'border-accent text-ink-900' : 'border-transparent text-ink-600 hover:text-ink-900'"
    >
      Helpdesk
    </Link>
    <Link
      v-if="canMergePartners()"
      :href="route('crm.merge.index')"
      role="tab"
      class="border-b-2 px-3 py-2 text-sm font-medium transition"
      :class="active === 'merge' ? 'border-accent text-ink-900' : 'border-transparent text-ink-600 hover:text-ink-900'"
    >
      Merge
    </Link>
  </div>
</template>
