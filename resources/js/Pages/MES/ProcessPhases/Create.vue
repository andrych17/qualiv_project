<!-- ponytail: Add Process Phase set (MES_SPECS.md §3F) -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormAsyncSearchableSelect from '@/Components/forms/FormAsyncSearchableSelect.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import ProcessPhaseListInput, { type ProcessPhaseRow } from '@/Components/mes/ProcessPhaseListInput.vue'

defineProps<{
  workCenters: Array<{ value: number; label: string }>
}>()

const form = useForm({
  recipe_id: null as number | null,
  phases: [] as ProcessPhaseRow[],
})

const submit = () => form.post(route('mes.processPhases.store'))
</script>

<template>
  <AppLayout>
    <PageHeader title="Add Process Phase Set" description="Sequence a process recipe's execution steps — PP owns the recipe/ingredient list itself." />

    <Panel class="mt-6 max-w-4xl">
      <form class="space-y-4" @submit.prevent="submit">
        <FormAsyncSearchableSelect
          v-model="form.recipe_id"
          name="recipe_id"
          label="Recipe"
          api-entity="pp_recipe"
          placeholder="Search product SKU or name…"
          :error="form.errors.recipe_id"
          required
        />

        <ProcessPhaseListInput v-model="form.phases" :work-centers="workCenters" />
        <p v-if="form.errors.phases" class="text-sm text-signal-danger">{{ form.errors.phases }}</p>

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <Link
            :href="route('mes.processPhases.index')"
            class="inline-flex items-center justify-center rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm font-semibold text-ink-900 shadow-sm transition hover:bg-surface-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            Cancel
          </Link>
          <PrimaryButton type="submit" :disabled="form.processing">Save Phases</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
