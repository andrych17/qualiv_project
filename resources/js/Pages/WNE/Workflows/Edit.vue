<!-- ponytail: WNE §3B builder — header form + step list + transition list + read-only preview + publish/unpublish. -->
<script setup lang="ts">
import { ref } from 'vue'
import { useForm, router, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import FormTextarea from '@/Components/forms/FormTextarea.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import WorkflowGraphPreview from '@/Components/wne/WorkflowGraphPreview.vue'
import WorkflowStepModal from '@/Components/wne/WorkflowStepModal.vue'
import WorkflowTransitionModal from '@/Components/wne/WorkflowTransitionModal.vue'
import WneSubNav from '@/Components/wne/WneSubNav.vue'

type StepRow = {
  id: number
  step_code: string
  type: string
  config: Record<string, unknown>
  has_webhook_auth_headers: boolean
  pos_x: number | null
  pos_y: number | null
  is_entry_step: boolean
}
type TransitionRow = {
  id: number
  from_step_id: number
  to_step_id: number
  condition_expression: { field?: string; op?: string; value?: unknown } | null
  seq: number
}

const props = defineProps<{
  definition: {
    id: number
    code: string
    name: string
    description: string | null
    category_id: number | null
    status: string
  }
  categories: Array<{ id: number; name: string }>
  draftVersionNo: number
  publishedVersionNo: number | null
  steps: StepRow[]
  transitions: TransitionRow[]
}>()

// --- Header form ---
const headerForm = useForm({
  name: props.definition.name,
  description: props.definition.description ?? '',
  category_id: props.definition.category_id,
})
const saveHeader = () => headerForm.put(route('wne.workflows.update', props.definition.id))

// --- Step modal ---
const showStepModal = ref(false)
const editingStep = ref<StepRow | null>(null)
const openAddStep = () => {
  editingStep.value = null
  showStepModal.value = true
}
const openEditStep = (step: StepRow) => {
  editingStep.value = step
  showStepModal.value = true
}
const deleteStep = (step: StepRow) => {
  if (!confirm(`Remove step "${step.step_code}"? Its transitions will be removed too.`)) return
  router.delete(route('wne.workflows.steps.destroy', [props.definition.id, step.id]), { preserveScroll: true })
}

// --- Transition modal ---
const showTransitionModal = ref(false)
const stepCode = (id: number) => props.steps.find((s) => s.id === id)?.step_code ?? `#${id}`
const conditionText = (t: TransitionRow) => (t.condition_expression ? `${t.condition_expression.field} ${t.condition_expression.op} ${t.condition_expression.value}` : 'else (default)')
const deleteTransition = (t: TransitionRow) => {
  router.delete(route('wne.workflows.transitions.destroy', [props.definition.id, t.id]), { preserveScroll: true })
}

// --- Publish / unpublish ---
const publishing = ref(false)
const publish = () => {
  publishing.value = true
  router.post(route('wne.workflows.publish', props.definition.id), {}, { onFinish: () => (publishing.value = false) })
}
const unpublish = () => {
  if (!confirm('Unpublish this workflow? New instances will be blocked; running instances are unaffected.')) return
  router.post(route('wne.workflows.unpublish', props.definition.id))
}
</script>

<template>
  <AppLayout>
    <PageHeader :title="definition.name" :description="definition.code">
      <template #actions>
        <StatusBadge :status="definition.status" />
        <PrimaryButton v-if="definition.status !== 'published'" type="button" :disabled="publishing" @click="publish">
          Publish
        </PrimaryButton>
        <button
          v-else
          type="button"
          class="inline-flex items-center justify-center rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm font-semibold text-ink-900 shadow-sm transition hover:bg-surface-50"
          @click="unpublish"
        >
          Unpublish
        </button>
      </template>
    </PageHeader>

    <WneSubNav active="workflows" class="mt-6" />

    <p class="mt-2 text-sm text-ink-600">
      Editing draft v{{ draftVersionNo }}.
      <span v-if="publishedVersionNo">Version {{ publishedVersionNo }} stays live for instances already running until you publish this draft.</span>
    </p>

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
      <Panel title="Details" class="lg:col-span-1">
        <form class="space-y-4" @submit.prevent="saveHeader">
          <FormInput v-model="headerForm.name" name="name" label="Name" :error="headerForm.errors.name" required />
          <FormTextarea v-model="headerForm.description" name="description" label="Description" :error="headerForm.errors.description" />
          <FormSelect
            v-model="headerForm.category_id"
            name="category_id"
            label="Category"
            placeholder="Uncategorized"
            :options="categories.map((c) => ({ label: c.name, value: c.id }))"
            :error="headerForm.errors.category_id"
          />
          <div class="flex justify-end border-t border-border pt-4">
            <PrimaryButton type="submit" :disabled="headerForm.processing">Save details</PrimaryButton>
          </div>
        </form>
      </Panel>

      <div class="space-y-6 lg:col-span-2">
        <Panel title="Steps">
          <template #actions>
            <button type="button" class="text-sm font-medium text-accent hover:underline" @click="openAddStep">Add step</button>
          </template>

          <div v-if="steps.length === 0" class="py-4 text-sm text-ink-600">No steps yet.</div>
          <ul v-else class="divide-y divide-border">
            <li v-for="step in steps" :key="step.id" class="flex items-center justify-between py-3">
              <div>
                <p class="text-sm font-medium text-ink-900">
                  {{ step.step_code }}
                  <span v-if="step.is_entry_step" class="ml-1 rounded-full border border-accent/25 bg-accent/10 px-2 py-0.5 text-xs text-accent">entry</span>
                </p>
                <p class="text-xs text-ink-600">{{ step.type.replace(/_/g, ' ') }}</p>
              </div>
              <div class="flex items-center gap-3">
                <button type="button" class="text-sm font-medium text-accent hover:underline" @click="openEditStep(step)">Edit</button>
                <button type="button" class="text-sm font-medium text-signal-danger hover:underline" @click="deleteStep(step)">Remove</button>
              </div>
            </li>
          </ul>
        </Panel>

        <Panel title="Transitions">
          <template #actions>
            <button
              type="button"
              class="text-sm font-medium text-accent hover:underline disabled:cursor-not-allowed disabled:text-ink-600"
              :disabled="steps.length < 2"
              @click="showTransitionModal = true"
            >
              Add transition
            </button>
          </template>

          <div v-if="transitions.length === 0" class="py-4 text-sm text-ink-600">No transitions yet.</div>
          <ul v-else class="divide-y divide-border">
            <li v-for="t in transitions" :key="t.id" class="flex items-center justify-between py-3">
              <p class="text-sm text-ink-900">
                {{ stepCode(t.from_step_id) }} → {{ stepCode(t.to_step_id) }}
                <span class="text-ink-600">({{ conditionText(t) }})</span>
              </p>
              <button type="button" class="text-sm font-medium text-signal-danger hover:underline" @click="deleteTransition(t)">Remove</button>
            </li>
          </ul>
        </Panel>

        <Panel title="Preview">
          <WorkflowGraphPreview :steps="steps" :transitions="transitions" />
        </Panel>
      </div>
    </div>

    <WorkflowStepModal
      :show="showStepModal"
      :definition-id="definition.id"
      :step="editingStep"
      @close="showStepModal = false"
    />
    <WorkflowTransitionModal
      :show="showTransitionModal"
      :definition-id="definition.id"
      :steps="steps"
      @close="showTransitionModal = false"
    />
  </AppLayout>
</template>
