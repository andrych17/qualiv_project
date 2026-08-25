<!-- ponytail: Edit Category (§3B) -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import FormSwitch from '@/Components/forms/FormSwitch.vue'
import InventorySubNav from '@/Components/inventory/InventorySubNav.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'

const props = defineProps<{
  category: { id: number; name: string; parent_category_id: number | null; is_active: boolean }
  categoryOptions: Array<{ id: number; label: string }>
}>()

const form = useForm({
  name: props.category.name,
  parent_category_id: props.category.parent_category_id,
  is_active: props.category.is_active,
})

const submit = () => form.put(route('inventory.categories.update', props.category.id))
</script>

<template>
  <AppLayout>
    <PageHeader title="Edit category" :description="category.name" />

    <InventorySubNav active="categories" class="mt-6" />

    <Panel class="mt-6 max-w-xl">
      <form class="space-y-4" @submit.prevent="submit">
        <FormInput v-model="form.name" name="name" label="Name" :error="form.errors.name" required />
        <FormSelect
          v-model="form.parent_category_id"
          name="parent_category_id"
          label="Parent category"
          placeholder="Top-level category"
          :options="categoryOptions.map((c) => ({ label: c.label, value: c.id }))"
          :error="form.errors.parent_category_id"
        />
        <FormSwitch v-model="form.is_active" label="Active" description="Inactive categories are hidden from the product form." />

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <Link
            :href="route('inventory.categories.index')"
            class="inline-flex items-center justify-center rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm font-semibold text-ink-900 shadow-sm transition hover:bg-surface-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            Cancel
          </Link>
          <PrimaryButton type="submit" :disabled="form.processing">Update category</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
