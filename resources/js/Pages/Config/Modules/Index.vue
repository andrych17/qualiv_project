<!-- ponytail: Tenant module activation — entitlement ceiling + opt-out toggle (SYSCONFIG_SPECS.md §3A) -->
<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
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

defineProps<{
  modules: ModuleRow[]
}>()

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

    <div class="mt-6 overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
      <table class="min-w-full divide-y divide-gray-200 text-sm">
        <thead class="bg-gray-50">
          <tr>
            <th class="px-4 py-3 text-left font-semibold text-gray-700">Module</th>
            <th class="px-4 py-3 text-left font-semibold text-gray-700">Entitlement</th>
            <th class="px-4 py-3 text-left font-semibold text-gray-700">Visible</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <tr v-for="row in modules" :key="row.module_code">
            <td class="px-4 py-3 font-medium text-gray-900">{{ row.module_code }}</td>
            <td class="px-4 py-3">
              <StatusBadge :status="row.entitled ? 'active' : 'inactive'" :label="row.entitled ? 'On plan' : 'Not entitled'" />
            </td>
            <td class="px-4 py-3">
              <FormSwitch
                :model-value="row.is_active"
                :disabled="!row.can_toggle"
                :description="row.can_toggle ? 'Shown in sidebar and routes' : 'Cannot enable a module this plan does not include'"
                @update:model-value="toggle(row)"
              />
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </AppLayout>
</template>
