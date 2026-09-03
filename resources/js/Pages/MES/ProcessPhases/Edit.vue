<!-- ponytail: Edit Process Phase set (MES_SPECS.md §3F) -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import ProcessPhaseListInput, { type ProcessPhaseRow } from '@/Components/mes/ProcessPhaseListInput.vue'

const props = defineProps<{
  recipe: { id: number; label: string }
  phases: ProcessPhaseRow[]
  workCenters: Array<{ value: number; label: string }>
}>()

const form = useForm({
  phases: props.phases,
})

const submit = () => form.put(route('mes.processPhases.update', props.recipe.id))
</script>

<template>
  <AppLayout>
    <PageHeader title="Edit Process Phase Set" :description="recipe.label" />

    <Panel class="mt-6 max-w-4xl">
      <form class="space-y-4" @submit.prevent="submit">
        <FormInput :model-value="recipe.label" name="recipe_label" label="Recipe" disabled />

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
