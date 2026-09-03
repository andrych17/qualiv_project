<!-- ponytail: Edit manual demand (PP_SPECS.md §3B) — manual source only, enforced server-side too -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import DemandLineListInput, { type DemandLineRow } from '@/Components/pp/DemandLineListInput.vue'

const props = defineProps<{
  demand: {
    id: number
    demand_date: string
    note: string | null
    lines: DemandLineRow[]
  }
}>()

const form = useForm({
  demand_date: props.demand.demand_date,
  note: props.demand.note ?? '',
  lines: props.demand.lines,
})

const submit = () => form.put(route('pp.demand.update', props.demand.id))
</script>

<template>
  <AppLayout>
    <PageHeader title="Edit manual demand" />

    <Panel class="mt-6 max-w-3xl">
      <form class="space-y-4" @submit.prevent="submit">
        <FormInput v-model="form.demand_date" name="demand_date" label="Demand date" type="date" :error="form.errors.demand_date" required />
        <FormInput v-model="form.note" name="note" label="Note" placeholder="Optional context for this plan" :error="form.errors.note" />

        <DemandLineListInput v-model="form.lines" />
        <p v-if="form.errors.lines" class="text-sm text-signal-danger">{{ form.errors.lines }}</p>

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <Link
            :href="route('pp.demand.index')"
            class="inline-flex items-center justify-center rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm font-semibold text-ink-900 shadow-sm transition hover:bg-surface-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            Cancel
          </Link>
          <PrimaryButton type="submit" :disabled="form.processing">Save demand</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
