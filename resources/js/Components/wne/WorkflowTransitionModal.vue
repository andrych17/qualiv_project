<!-- ponytail: WNE §3B add transition. Condition is a single field/op/value comparison
     (spec §3D v1 scope: "simple field comparisons") — leave condition fields blank for the
     mandatory default/"else" transition. -->
<script setup lang="ts">
import { ref, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import Modal from '@/Components/Modal.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'

type StepRow = { id: number; step_code: string }

const props = defineProps<{
  show: boolean
  definitionId: number
  steps: StepRow[]
}>()

const emit = defineEmits<{ close: [] }>()

const OPS = ['=', '!=', '>', '<', 'in', 'contains']

const form = useForm({
  from_step_id: null as number | null,
  to_step_id: null as number | null,
  has_condition: false,
  condition_field: '',
  condition_op: '=',
  condition_value: '',
  seq: 0,
})

watch(
  () => props.show,
  () => {
    if (!props.show) return
    // Set fields explicitly rather than form.reset() — Inertia's reset() restores a
    // mutable `defaults` snapshot, not necessarily the useForm() literal, and this modal
    // instance is reused (not remounted) across repeated "Add transition" opens.
    form.from_step_id = null
    form.to_step_id = null
    form.has_condition = false
    form.condition_field = ''
    form.condition_op = '='
    form.condition_value = ''
    form.seq = 0
    form.clearErrors()
  },
)

const submit = () => {
  const payload = {
    from_step_id: form.from_step_id,
    to_step_id: form.to_step_id,
    seq: form.seq,
    condition_expression: form.has_condition
      ? { field: form.condition_field, op: form.condition_op, value: form.condition_value }
      : null,
  }

  form.transform(() => payload).post(route('wne.workflows.transitions.store', props.definitionId), {
    onSuccess: () => emit('close'),
  })
}
</script>

<template>
  <Modal :show="show" @close="emit('close')">
    <div class="p-6">
      <h2 class="font-serif text-lg font-semibold text-ink-900">Add transition</h2>

      <form class="mt-4 space-y-4" @submit.prevent="submit">
        <FormSelect
          v-model="form.from_step_id"
          name="from_step_id"
          label="From step"
          :options="steps.map((s) => ({ label: s.step_code, value: s.id }))"
          :error="form.errors.from_step_id"
          required
        />
        <FormSelect
          v-model="form.to_step_id"
          name="to_step_id"
          label="To step"
          :options="steps.map((s) => ({ label: s.step_code, value: s.id }))"
          :error="form.errors.to_step_id"
          required
        />

        <label class="flex items-center gap-2 text-sm text-ink-900">
          <input v-model="form.has_condition" type="checkbox" class="rounded border-border" />
          Conditional (leave unchecked for the default/"else" path)
        </label>

        <template v-if="form.has_condition">
          <FormInput v-model="form.condition_field" name="condition_field" label="Payload field" placeholder="e.g. amount" />
          <FormSelect
            v-model="form.condition_op"
            name="condition_op"
            label="Operator"
            :options="OPS.map((op) => ({ label: op, value: op }))"
          />
          <FormInput v-model="form.condition_value" name="condition_value" label="Value" />
        </template>

        <FormInput v-model="form.seq" name="seq" type="number" label="Evaluation order" :error="form.errors.seq" />
        <p v-if="form.errors.to_step_id" class="text-sm text-signal-danger">{{ form.errors.to_step_id }}</p>

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <button
            type="button"
            class="inline-flex items-center justify-center rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm font-semibold text-ink-900 shadow-sm transition hover:bg-surface-50"
            @click="emit('close')"
          >
            Cancel
          </button>
          <PrimaryButton type="submit" :disabled="form.processing">Add transition</PrimaryButton>
        </div>
      </form>
    </div>
  </Modal>
</template>
