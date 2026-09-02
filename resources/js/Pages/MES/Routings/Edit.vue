<!-- ponytail: Edit Routing (MES_SPECS.md §3E) -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSwitch from '@/Components/forms/FormSwitch.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import RoutingOpListInput, { type RoutingOpRow } from '@/Components/mes/RoutingOpListInput.vue'

const props = defineProps<{
  routing: {
    id: number
    product_id: number
    product_label: string | null
    version: number
    is_active: boolean
    ops: RoutingOpRow[]
  }
  workCenters: Array<{ value: number; label: string }>
}>()

const form = useForm({
  is_active: props.routing.is_active,
  ops: props.routing.ops,
})

const submit = () => form.put(route('mes.routings.update', props.routing.id))
</script>

<template>
  <AppLayout>
    <PageHeader title="Edit Routing" :description="routing.product_label ? `${routing.product_label} — v${routing.version}` : undefined" />

    <Panel class="mt-6 max-w-3xl">
      <form class="space-y-4" @submit.prevent="submit">
        <FormInput :model-value="routing.product_label ?? ''" name="product_label" label="Product" disabled />

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
