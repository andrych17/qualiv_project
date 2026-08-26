<!-- ponytail: Accounting §3H — edit an item/category → GL account mapping. -->
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
  mapping: {
    id: number
    company_id: number
    inventory_item_id: number | null
    inventory_category_id: number | null
    inventory_asset_account_id: number
    cogs_account_id: number | null
    grni_account_id: number | null
    adjustment_account_id: number | null
  }
  items: Option[]
  categories: Option[]
  accounts: Option[]
}>()

const form = useForm({
  inventory_item_id: props.mapping.inventory_item_id,
  inventory_category_id: props.mapping.inventory_category_id,
  inventory_asset_account_id: props.mapping.inventory_asset_account_id,
  cogs_account_id: props.mapping.cogs_account_id,
  grni_account_id: props.mapping.grni_account_id,
  adjustment_account_id: props.mapping.adjustment_account_id,
})

const scopeIsValid = computed(() => (form.inventory_item_id !== null) !== (form.inventory_category_id !== null))

const submit = () => form.put(route('accounting.inventory-gl-mappings.update', props.mapping.id))
</script>

<template>
  <AppLayout>
    <PageHeader title="Edit Inventory GL Mapping" description="Set either an item or a category, not both — an item-level mapping overrides its category mapping." />

    <Panel class="mt-6 max-w-2xl">
      <form class="space-y-4" @submit.prevent="submit">
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
          <SecondaryButton :href="route('accounting.inventory-gl-mappings.index', { company_id: mapping.company_id })">
            Cancel
          </SecondaryButton>
          <PrimaryButton type="submit" :disabled="form.processing || !scopeIsValid">Save Changes</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
