<!-- ponytail: Add Changeover Matrix row (PP_SPECS.md §3J) -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import FormSwitch from '@/Components/forms/FormSwitch.vue'
import FormAsyncSearchableSelect from '@/Components/forms/FormAsyncSearchableSelect.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'

defineProps<{
  resourceGroupOptions: Array<{ value: number; label: string }>
}>()

const form = useForm({
  from_product_id: null as number | null,
  from_family: '',
  to_product_id: null as number | null,
  to_family: '',
  resource_group_id: null as number | null,
  changeover_minutes: 0,
  cleaning_minutes: 0,
  is_active: true,
})

const submit = () => form.post(route('pp.changeoverMatrix.store'))
</script>

<template>
  <AppLayout>
    <PageHeader
      title="Add Changeover Matrix Row"
      description="The switching cost from one product/family to another on a resource group (§3J) — consumed by the minimize setup / minimize changeover dispatch strategies (§3I)."
    />

    <Panel class="mt-6 max-w-3xl">
      <form class="space-y-6" @submit.prevent="submit">
        <div class="space-y-2">
          <p class="text-xs font-semibold uppercase tracking-wide text-ink-600">From</p>
          <FormAsyncSearchableSelect
            v-model="form.from_product_id"
            name="from_product_id"
            label="Product"
            api-entity="inventory_product"
            placeholder="Search SKU or name… (leave empty to key by family instead)"
            :error="form.errors.from_product_id"
          />
          <FormInput
            v-model="form.from_family"
            name="from_family"
            label="Family tag"
            placeholder="e.g. color-white, or the literal 'other' for a catch-all"
            :disabled="!!form.from_product_id"
            :error="form.errors.from_family"
          />
        </div>

        <div class="space-y-2">
          <p class="text-xs font-semibold uppercase tracking-wide text-ink-600">To</p>
          <FormAsyncSearchableSelect
            v-model="form.to_product_id"
            name="to_product_id"
            label="Product"
            api-entity="inventory_product"
            placeholder="Search SKU or name… (leave empty to key by family instead)"
            :error="form.errors.to_product_id"
          />
          <FormInput
            v-model="form.to_family"
            name="to_family"
            label="Family tag"
            placeholder="e.g. color-white, or the literal 'other' for a catch-all"
            :disabled="!!form.to_product_id"
            :error="form.errors.to_family"
          />
        </div>

        <FormSelect
          v-model="form.resource_group_id"
          name="resource_group_id"
          label="Resource group"
          :options="resourceGroupOptions"
          placeholder="All groups (applies matrix-wide)"
          :error="form.errors.resource_group_id"
        />

        <div class="grid grid-cols-2 gap-4">
          <FormInput v-model.number="form.changeover_minutes" name="changeover_minutes" type="number" min="0" label="Changeover (minutes)" :error="form.errors.changeover_minutes" required />
          <FormInput v-model.number="form.cleaning_minutes" name="cleaning_minutes" type="number" min="0" label="Cleaning (minutes)" :error="form.errors.cleaning_minutes" required />
        </div>

        <FormSwitch v-model="form.is_active" name="is_active" label="Active" />

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <Link
            :href="route('pp.changeoverMatrix.index')"
            class="inline-flex items-center justify-center rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm font-semibold text-ink-900 shadow-sm transition hover:bg-surface-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            Cancel
          </Link>
          <PrimaryButton type="submit" :disabled="form.processing">Save Row</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
