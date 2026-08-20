<!-- ponytail: Contacts/Companies switcher — one CRM menu row covers both routes, so the
     second-level nav lives here rather than as a separate sidebar entry (SysConfigSeeder). -->
<script setup lang="ts">
import { Link } from '@inertiajs/vue3'

defineProps<{
  active: 'contacts' | 'companies'
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
  </div>
</template>
