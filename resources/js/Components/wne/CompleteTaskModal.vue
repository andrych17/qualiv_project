<script setup lang="ts">
import { watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import Modal from '@/Components/Modal.vue'
import FormTextarea from '@/Components/forms/FormTextarea.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import { useI18n } from '@/Composables/useI18n'

const { t } = useI18n()

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
        {{ t('wne.source') }}: <code class="font-mono">{{ task.subject_type }}#{{ task.subject_id }}</code>
      </p>

      <div class="mt-4 space-y-4">
        <FormTextarea v-model="form.comment" name="comment" :label="t('wne.comment_optional')" :error="form.errors.comment" />
        <p v-if="form.errors.decision" class="text-sm text-signal-danger">{{ form.errors.decision }}</p>

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <SecondaryButton
            type="button"
            @click="emit('close')"
          >
            {{ t('common.cancel') }}
          </SecondaryButton>
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
