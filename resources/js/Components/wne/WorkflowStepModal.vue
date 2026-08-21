<!-- ponytail: WNE §3B add/edit step. Config is authored as raw JSON (spec: "a config payload
     (JSON) ... per type") — a per-type structured sub-form is a nice-to-have, not v1 scope. -->
<script setup lang="ts">
import { ref, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import Modal from '@/Components/Modal.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import FormSwitch from '@/Components/forms/FormSwitch.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'

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

const props = defineProps<{
  show: boolean
  definitionId: number
  step: StepRow | null
}>()

const emit = defineEmits<{ close: [] }>()

const STEP_TYPES = [
  'approval', 'task', 'condition', 'parallel_split', 'parallel_join', 'webhook_call', 'wait_for_callback', 'notify',
]

const form = useForm({
  step_code: '',
  type: 'task',
  config: '{}',
  webhook_auth_headers: '{}',
  is_entry_step: false,
})

const jsonError = ref('')
const authHeadersJsonError = ref('')

watch(
  () => [props.show, props.step],
  () => {
    if (!props.show) return
    jsonError.value = ''
    authHeadersJsonError.value = ''
    if (props.step) {
      form.step_code = props.step.step_code
      form.type = props.step.type
      form.config = JSON.stringify(props.step.config ?? {}, null, 2)
      // Never prefilled with the actual secret (server doesn't send it back) — blank means
      // "leave unchanged" on submit; type "{}" here to explicitly clear it.
      form.webhook_auth_headers = ''
      form.is_entry_step = props.step.is_entry_step
    } else {
      // Set fields explicitly rather than form.reset() — Inertia's reset() restores
      // a mutable `defaults` snapshot, not necessarily the literal passed to useForm(),
      // and this modal instance is reused (not remounted) across repeated "Add step" opens.
      form.step_code = ''
      form.type = 'task'
      form.config = '{}'
      form.webhook_auth_headers = '{}'
      form.is_entry_step = false
    }
    form.clearErrors()
  },
  { immediate: true },
)

const submit = () => {
  let parsedConfig: Record<string, unknown>
  try {
    parsedConfig = JSON.parse(form.config || '{}')
  } catch {
    jsonError.value = 'Config must be valid JSON.'
    return
  }
  jsonError.value = ''

  // Blank = leave whatever's already stored untouched (omit the key entirely so the backend
  // never overwrites it); non-blank = replace it, "{}" being the explicit way to clear it.
  let authHeadersToSend: Record<string, unknown> | null | undefined
  if (form.webhook_auth_headers.trim() === '') {
    authHeadersToSend = undefined
  } else {
    try {
      const parsed = JSON.parse(form.webhook_auth_headers)
      authHeadersToSend = Object.keys(parsed).length > 0 ? parsed : null
    } catch {
      authHeadersJsonError.value = 'Auth headers must be valid JSON.'
      return
    }
  }
  authHeadersJsonError.value = ''

  const payload: Record<string, unknown> = {
    step_code: form.step_code,
    type: form.type,
    config: parsedConfig,
    is_entry_step: form.is_entry_step,
  }
  if (authHeadersToSend !== undefined) {
    payload.webhook_auth_headers = authHeadersToSend
  }

  const onSuccess = () => emit('close')

  if (props.step) {
    form.transform(() => payload).put(route('wne.workflows.steps.update', [props.definitionId, props.step.id]), { onSuccess })
  } else {
    form.transform(() => payload).post(route('wne.workflows.steps.store', props.definitionId), { onSuccess })
  }
}
</script>

<template>
  <Modal :show="show" @close="emit('close')">
    <div class="p-6">
      <h2 class="font-serif text-lg font-semibold text-ink-900">{{ step ? 'Edit step' : 'Add step' }}</h2>

      <form class="mt-4 space-y-4" @submit.prevent="submit">
        <FormInput
          v-model="form.step_code"
          name="step_code"
          label="Step code"
          placeholder="e.g. manager_approval"
          :error="form.errors.step_code"
          required
        />
        <FormSelect
          v-model="form.type"
          name="type"
          label="Type"
          :options="STEP_TYPES.map((t) => ({ label: t.replace(/_/g, ' '), value: t }))"
          :error="form.errors.type"
          required
        />
        <div class="space-y-1.5">
          <label class="text-sm font-medium text-ink-900">Config (JSON)</label>
          <textarea
            v-model="form.config"
            rows="6"
            class="w-full rounded-md border border-border bg-white px-3 py-2 font-mono text-xs shadow-sm outline-none transition focus:border-ink-900 focus:ring-2 focus:ring-ink-900/10"
          />
          <p v-if="jsonError" class="text-sm text-signal-danger">{{ jsonError }}</p>
          <p v-else-if="form.errors.config" class="text-sm text-signal-danger">{{ form.errors.config }}</p>
        </div>
        <div v-if="form.type === 'webhook_call'" class="space-y-1.5">
          <label class="text-sm font-medium text-ink-900">Auth headers (JSON)</label>
          <p class="text-xs text-ink-500">
            Stored encrypted — e.g. {"Authorization": "Bearer ..."}. Kept off the plain config above deliberately.
            <template v-if="step?.has_webhook_auth_headers">Already set — leave blank to keep it, or enter JSON to replace it ("{}" clears it).</template>
          </p>
          <textarea
            v-model="form.webhook_auth_headers"
            rows="3"
            :placeholder="step?.has_webhook_auth_headers ? '(unchanged)' : ''"
            class="w-full rounded-md border border-border bg-white px-3 py-2 font-mono text-xs shadow-sm outline-none transition focus:border-ink-900 focus:ring-2 focus:ring-ink-900/10"
          />
          <p v-if="authHeadersJsonError" class="text-sm text-signal-danger">{{ authHeadersJsonError }}</p>
          <p v-else-if="form.errors.webhook_auth_headers" class="text-sm text-signal-danger">{{ form.errors.webhook_auth_headers }}</p>
        </div>
        <FormSwitch
          v-model="form.is_entry_step"
          label="Entry step"
          description="Where a new instance of this workflow starts. Setting this replaces the current entry step."
        />

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <button
            type="button"
            class="inline-flex items-center justify-center rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm font-semibold text-ink-900 shadow-sm transition hover:bg-surface-50"
            @click="emit('close')"
          >
            Cancel
          </button>
          <PrimaryButton type="submit" :disabled="form.processing">{{ step ? 'Save step' : 'Add step' }}</PrimaryButton>
        </div>
      </form>
    </div>
  </Modal>
</template>
