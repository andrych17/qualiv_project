<!-- ponytail: Add Routing (MES_SPECS.md §3E) -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormSwitch from '@/Components/forms/FormSwitch.vue'
import FormAsyncSearchableSelect from '@/Components/forms/FormAsyncSearchableSelect.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import RoutingOpListInput, { type RoutingOpRow } from '@/Components/mes/RoutingOpListInput.vue'

defineProps<{
  workCenters: Array<{ value: number; label: string }>
}>()

const form = useForm({
  product_id: null as number | null,
  is_active: true,
  ops: [] as RoutingOpRow[],
})

const submit = () => form.post(route('mes.routings.store'))
</script>

<template>
  <AppLayout>
    <PageHeader title="Add Routing" description="Discrete Routing / Operations — a new version is created automatically for this product." />

    <Panel class="mt-6 max-w-3xl">
      <form class="space-y-4" @submit.prevent="submit">
        <FormAsyncSearchableSelect
          v-model="form.product_id"
          name="product_id"
          label="Product"
          api-entity="inventory_product"
          placeholder="Search SKU or name…"
          :error="form.errors.product_id"
          required
        />

        <FormSwitch v-model="form.is_active" name="is_active" label="Active version" />
        <p class="text-xs text-ink-600">Only one active version per product — marking this active deactivates any other active routing for the same product.</p>

        <RoutingOpListInput v-model="form.ops" :work-centers="workCenters" />
        <p v-if="form.errors.ops" class="text-sm text-signal-danger">{{ form.errors.ops }}</p>

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <Link
            :href="route('mes.routings.index')"
            class="inline-flex items-center justify-center rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm font-semibold text-ink-900 shadow-sm transition hover:bg-surface-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            Cancel
          </Link>
          <PrimaryButton type="submit" :disabled="form.processing">Save Routing</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
