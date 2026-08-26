<!-- ponytail: Tenant module activation — entitlement ceiling + opt-out toggle (SYSCONFIG_SPECS.md §3A) -->
<script setup lang="ts">
import { computed } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import DataTable from '@/Components/tables/DataTable.vue'
import FormSwitch from '@/Components/forms/FormSwitch.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'

interface ModuleRow {
  module_code: string
  entitled: boolean
  is_active: boolean
  can_toggle: boolean
  notes: string | null
  activated_at: string | null
}

const props = defineProps<{
  modules: ModuleRow[]
}>()

const columns = [
  { key: 'module_code', label: 'Module Code', sortable: true },
  { key: 'entitled', label: 'Plan Entitlement' },
  { key: 'is_active', label: 'Tenant Visibility' },
]

const toggle = (row: ModuleRow) => {
  if (!row.can_toggle) return
  router.patch(route('config.modules.update', row.module_code), {
    is_active: !row.is_active,
  }, { preserveScroll: true })
}
</script>

<template>
  <AppLayout>
    <PageHeader
      title="Modules"
      description="Turn entitled modules on or off for this tenant. Plan entitlement is the ceiling — this only narrows it."
    />

    <div class="mt-6">
      <DataTable
        :columns="columns"
        :items="modules"
        empty-title="No modules available"
        empty-description="There are no modules registered in the system."
      >
        <template #cell-module_code="{ item }">
          <span class="font-mono font-bold text-ink-900">{{ item.module_code }}</span>
        </template>
        <template #cell-entitled="{ item }">
          <StatusBadge :status="item.entitled ? 'active' : 'inactive'" :label="item.entitled ? 'On Plan' : 'Not Entitled'" />
        </template>
        <template #cell-is_active="{ item }">
          <FormSwitch
            :model-value="item.is_active"
            :disabled="!item.can_toggle"
            :description="item.can_toggle ? 'Enabled for tenant sidebar and routes' : 'Cannot enable: not included in tenant plan'"
            @update:model-value="toggle(item as ModuleRow)"
          />
        </template>
      </DataTable>
    </div>
  </AppLayout>
</template>
