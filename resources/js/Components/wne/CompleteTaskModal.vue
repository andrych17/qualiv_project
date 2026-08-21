<!-- ponytail: §3H task detail — decision buttons come from the step's own config.decisions
     (defaults to approve/reject); source record link resolution is deliberately left to the
     calling module's own frontend, per spec — WNE only stores the polymorphic pointer. -->
<script setup lang="ts">
import { watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import Modal from '@/Components/Modal.vue'
import FormTextarea from '@/Components/forms/FormTextarea.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'

type TaskRow = {
  id: number
  step_code: string
  prompt: string
  decisions: string[]
  workflow_name: string
  subject_type: string | null
  subject_id: number | null
}

const props = defineProps<{
  show: boolean
  task: TaskRow | null
}>()

const emit = defineEmits<{ close: [] }>()

const form = useForm({
  decision: '',
  comment: '',
})

watch(
  () => props.show,
  (show) => {
    if (!show) return
    form.decision = ''
    form.comment = ''
    form.clearErrors()
  },
)

const submit = (decision: string) => {
  if (!props.task) return
  form.decision = decision
  form.post(route('wne.my-tasks.complete', props.task.id), { onSuccess: () => emit('close') })
}
</script>

<template>
  <Modal :show="show" @close="emit('close')">
    <div v-if="task" class="p-6">
      <h2 class="font-serif text-lg font-semibold text-ink-900">{{ task.workflow_name }}</h2>
      <p class="mt-1 text-sm text-ink-600">{{ task.prompt }}</p>
      <p v-if="task.subject_type" class="mt-1 text-xs text-ink-600">
        Source: <code class="font-mono">{{ task.subject_type }}#{{ task.subject_id }}</code>
      </p>

      <div class="mt-4 space-y-4">
        <FormTextarea v-model="form.comment" name="comment" label="Comment (optional)" :error="form.errors.comment" />
        <p v-if="form.errors.decision" class="text-sm text-signal-danger">{{ form.errors.decision }}</p>

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <button
            type="button"
            class="inline-flex items-center justify-center rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm font-semibold text-ink-900 shadow-sm transition hover:bg-surface-50"
            @click="emit('close')"
          >
            Cancel
          </button>
          <PrimaryButton
            v-for="decision in task.decisions"
            :key="decision"
            type="button"
            :disabled="form.processing"
            class="capitalize"
            @click="submit(decision)"
          >
            {{ decision.replace(/_/g, ' ') }}
          </PrimaryButton>
        </div>
      </div>
    </div>
  </Modal>
</template>
