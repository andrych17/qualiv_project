<!-- ponytail: "Convert to Partner" (§3D) — the one action a Lead's Converted column
     needs, since a bare drag can't supply the Role a conversion requires. -->
<script setup lang="ts">
import { watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import Modal from '@/Components/Modal.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import FormRadioGroup from '@/Components/forms/FormRadioGroup.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'

const props = defineProps<{
  show: boolean
  leadId: number | null
  leadName: string
  hasCompanyName: boolean
  roleTypes: Array<{ id: number; name: string }>
}>()

const emit = defineEmits<{
  close: []
}>()

const form = useForm({
  partner_type: 'individual' as 'individual' | 'organization',
  role_type_id: null as number | null,
})

watch(() => props.show, (show) => {
  if (show) {
    form.reset()
    form.partner_type = props.hasCompanyName ? 'organization' : 'individual'
  }
})

const submit = () => {
  if (!props.leadId) return
  form.post(route('crm.leads.convert', props.leadId), {
    onSuccess: () => emit('close'),
  })
}
</script>

<template>
  <Modal :show="show" max-width="sm" @close="emit('close')">
    <div class="p-6">
      <h2 class="font-serif text-lg font-semibold text-ink-900">Convert "{{ leadName }}" to a partner</h2>
      <p class="mt-1 text-sm text-ink-600">
        Creates a new Partner record and assigns the role you choose below.
      </p>

      <form class="mt-4 space-y-4" @submit.prevent="submit">
        <FormRadioGroup
          v-model="form.partner_type"
          name="partner_type"
          label="Partner type"
          :options="[
            { label: 'Individual', value: 'individual' },
            { label: 'Organization', value: 'organization' },
          ]"
          :error="form.errors.partner_type"
        />
        <FormSelect
          v-model="form.role_type_id"
          name="role_type_id"
          label="Initial role"
          placeholder="Select a role…"
          :options="roleTypes.map((rt) => ({ label: rt.name, value: rt.id }))"
          :error="form.errors.role_type_id"
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
          <PrimaryButton type="submit" :disabled="form.processing">Convert</PrimaryButton>
        </div>
      </form>
    </div>
  </Modal>
</template>
