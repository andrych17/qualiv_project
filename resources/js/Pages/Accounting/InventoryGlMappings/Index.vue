<!-- ponytail: Accounting §3H — item/category → GL account mapping list. -->
<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import { useConfirm } from '@/Composables/useConfirmDialog'

interface MappingRow {
  id: number
  scope_label: string
  inventory_asset_account: string
  cogs_account: string | null
  grni_account: string | null
  adjustment_account: string | null
}

const props = defineProps<{
  companies: Array<{ id: number; legal_name: string }>
  selectedCompanyId: number | null
  mappings: MappingRow[]
}>()

const { confirm } = useConfirm()

const switchCompany = (e: Event) => router.get(route('accounting.inventory-gl-mappings.index'), { company_id: (e.target as HTMLSelectElement).value }, { preserveState: true })

const destroy = (m: MappingRow) => {
  confirm({
    title: 'Delete Inventory GL Mapping?',
    description: `Delete the mapping for "${m.scope_label}"? Movements for it will fail loudly and queue for review until it's remapped.`,
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () => router.delete(route('accounting.inventory-gl-mappings.destroy', m.id)),
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader title="Inventory GL mappings" description="Which accounts an Inventory movement posts against — inventory-asset (always), COGS (issues), GRNI/accrual (receipts), adjustment (write-offs). A movement with no mapping fails loudly and queues for review instead of guessing.">
      <template #actions>
        <Link :href="route('accounting.inventory-posting-failures.index', { company_id: selectedCompanyId })" class="mr-4 text-sm font-medium text-accent hover:underline">Review queue</Link>
        <PrimaryButton :href="route('accounting.inventory-gl-mappings.create', { company_id: selectedCompanyId })">New mapping</PrimaryButton>
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
            <th class="py-2">Scope</th>
            <th class="py-2">Inventory asset</th>
            <th class="py-2">COGS</th>
            <th class="py-2">GRNI</th>
            <th class="py-2">Adjustment</th>
            <th class="py-2"></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="m in mappings" :key="m.id" class="border-b border-border hover:bg-surface-50">
            <td class="py-2">
              <a :href="route('accounting.inventory-gl-mappings.edit', m.id)" class="font-medium text-accent hover:underline">{{ m.scope_label }}</a>
            </td>
            <td class="py-2 text-ink-700">{{ m.inventory_asset_account }}</td>
            <td class="py-2 text-ink-700">{{ m.cogs_account ?? '—' }}</td>
            <td class="py-2 text-ink-700">{{ m.grni_account ?? '—' }}</td>
            <td class="py-2 text-ink-700">{{ m.adjustment_account ?? '—' }}</td>
            <td class="py-2 text-right">
              <button type="button" class="text-sm font-medium text-signal-danger hover:underline" @click="destroy(m)">Delete</button>
            </td>
          </tr>
          <tr v-if="!mappings.length"><td colspan="6" class="py-6 text-center text-ink-600">No mappings yet — Inventory movements will fail loudly and queue for review until one exists.</td></tr>
        </tbody>
      </table>
    </Panel>
  </AppLayout>
</template>
