<!-- ponytail: Contacts/Companies/Leads switcher — one CRM menu row covers all CRM routes, so
     the second-level nav lives here rather than as separate sidebar entries (SysConfigSeeder). -->
<script setup lang="ts">
import { Link } from '@inertiajs/vue3'

defineProps<{
  active: 'contacts' | 'companies' | 'leads'
}>()
</script>

<template>
  <div class="flex items-center gap-1 border-b border-border" role="tablist">
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
  </div>
</template>
