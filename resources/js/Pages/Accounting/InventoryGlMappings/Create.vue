<!-- ponytail: Accounting §3H — new item/category → GL account mapping. Exactly one of
     item/category must be set (enforced server-side in InventoryGlMappingService), shown
     live here so a user doesn't have to submit to find out. -->
<script setup lang="ts">
import { computed } from 'vue'
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormSearchableSelect from '@/Components/forms/FormSearchableSelect.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'

type Option = { value: number; label: string }

const props = defineProps<{
  companies: Array<{ id: number; legal_name: string }>
  selectedCompanyId: number | null
  items: Option[]
  categories: Option[]
  accounts: Option[]
}>()

const form = useForm({
  company_id: props.selectedCompanyId,
  inventory_item_id: null as number | null,
  inventory_category_id: null as number | null,
  inventory_asset_account_id: null as number | null,
  cogs_account_id: null as number | null,
  grni_account_id: null as number | null,
  adjustment_account_id: null as number | null,
})

const scopeIsValid = computed(() => (form.inventory_item_id !== null) !== (form.inventory_category_id !== null))

const submit = () => form.post(route('accounting.inventory-gl-mappings.store'))
</script>

<template>
  <AppLayout>
    <PageHeader title="New Inventory GL Mapping" description="Configure automatic GL posting rules for inventory receipts, issues, and adjustments." />

    <Panel class="mt-6 max-w-2xl">
      <form class="space-y-4" @submit.prevent="submit">
        <FormSearchableSelect
          v-model="form.company_id"
          name="company_id"
          label="Company"
          :options="companies.map((c) => ({ value: c.id, label: c.legal_name }))"
          :error="form.errors.company_id"
          required
        />

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <FormSearchableSelect v-model="form.inventory_item_id" name="inventory_item_id" label="Item (Override)" placeholder="None" :options="items" :error="form.errors.inventory_item_id" />
          <FormSearchableSelect v-model="form.inventory_category_id" name="inventory_category_id" label="Category (Default)" placeholder="None" :options="categories" :error="form.errors.inventory_category_id" />
        </div>
        <p v-if="!scopeIsValid" class="text-sm text-signal-danger">Choose exactly one — an item or a category, not both and not neither.</p>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <FormSearchableSelect v-model="form.inventory_asset_account_id" name="inventory_asset_account_id" label="Inventory Asset Account" :options="accounts" :error="form.errors.inventory_asset_account_id" required />
          <FormSearchableSelect v-model="form.cogs_account_id" name="cogs_account_id" label="COGS Account" placeholder="None" :options="accounts" :error="form.errors.cogs_account_id" />
          <FormSearchableSelect v-model="form.grni_account_id" name="grni_account_id" label="GRNI / Accrual Account" placeholder="None" :options="accounts" :error="form.errors.grni_account_id" />
          <FormSearchableSelect v-model="form.adjustment_account_id" name="adjustment_account_id" label="Adjustment / Write-Off Account" placeholder="None" :options="accounts" :error="form.errors.adjustment_account_id" />
        </div>

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <SecondaryButton :href="route('accounting.inventory-gl-mappings.index', { company_id: form.company_id })">
            Cancel
          </SecondaryButton>
          <PrimaryButton type="submit" :disabled="form.processing || !scopeIsValid">Save Mapping</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
