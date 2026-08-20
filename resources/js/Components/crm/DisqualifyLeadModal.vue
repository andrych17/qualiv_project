<!-- ponytail: Disqualify (§3D) — requires a reason code, never silent (also feeds the
     loss-reason report the spec calls out as worth having even at MVP). -->
<script setup lang="ts">
import { watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import Modal from '@/Components/Modal.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'

const props = defineProps<{
  show: boolean
  leadId: number | null
  leadName: string
}>()

const emit = defineEmits<{
  close: []
}>()

const REASONS = [
  { label: 'Lost to competitor', value: 'Lost to competitor' },
  { label: 'No budget', value: 'No budget' },
  { label: 'Not a fit', value: 'Not a fit' },
  { label: 'Unresponsive', value: 'Unresponsive' },
  { label: 'Other', value: 'Other' },
]

const form = useForm({
  reason: '',
})

watch(() => props.show, (show) => {
  if (show) form.reset()
})

const submit = () => {
  if (!props.leadId) return
  form.post(route('crm.leads.disqualify', props.leadId), {
    onSuccess: () => emit('close'),
  })
}
</script>

<template>
  <Modal :show="show" max-width="sm" @close="emit('close')">
    <div class="p-6">
      <h2 class="font-serif text-lg font-semibold text-ink-900">Disqualify "{{ leadName }}"</h2>
      <p class="mt-1 text-sm text-ink-600">A reason is required — this feeds the loss-reason report.</p>

      <form class="mt-4 space-y-4" @submit.prevent="submit">
        <FormSelect
          v-model="form.reason"
          name="reason"
          label="Reason"
          placeholder="Select a reason…"
          :options="REASONS"
          :error="form.errors.reason"
          required
        />

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <button
            type="button"
            class="inline-flex items-center justify-center rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm font-semibold text-ink-900 shadow-sm transition hover:bg-surface-50"
            @click="emit('close')"
          >
            Cancel
          </button>
          <PrimaryButton type="submit" :disabled="form.processing">Disqualify</PrimaryButton>
        </div>
      </form>
    </div>
  </Modal>
</template>
