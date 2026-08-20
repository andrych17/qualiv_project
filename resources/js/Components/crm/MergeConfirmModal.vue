<!-- ponytail: Merge confirm (§3G) — trust-sensitive, always an explicit confirm, never
     one-click from the review queue (DESIGN.md "trust, precision"). -->
<script setup lang="ts">
import { watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import Modal from '@/Components/Modal.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'

const props = defineProps<{
  show: boolean
  survivor: { id: number; name: string } | null
  loser: { id: number; name: string } | null
}>()

const emit = defineEmits<{
  close: []
}>()

const form = useForm({
  survivor_partner_id: null as number | null,
  loser_partner_id: null as number | null,
})

watch(() => props.show, (show) => {
  if (show && props.survivor && props.loser) {
    form.survivor_partner_id = props.survivor.id
    form.loser_partner_id = props.loser.id
  }
})

const submit = () => {
  form.post(route('crm.merge.store'), {
    onSuccess: () => emit('close'),
  })
}
</script>

<template>
  <Modal :show="show" max-width="sm" @close="emit('close')">
    <div class="p-6" v-if="survivor && loser">
      <h2 class="font-serif text-lg font-semibold text-ink-900">Merge partners</h2>
      <p class="mt-1 text-sm text-ink-600">
        <strong class="text-ink-900">{{ loser.name }}</strong> will be tombstoned and everything that
        referenced it (roles, contacts, leads, cases, tickets) will move onto
        <strong class="text-ink-900">{{ survivor.name }}</strong>. This is logged, not silent — but
        it is not one-click reversible.
      </p>

      <form class="mt-4 space-y-4" @submit.prevent="submit">
        <p v-if="form.errors.loser_partner_id" class="text-sm text-signal-danger">{{ form.errors.loser_partner_id }}</p>

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <button
            type="button"
            class="inline-flex items-center justify-center rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm font-semibold text-ink-900 shadow-sm transition hover:bg-surface-50"
            @click="emit('close')"
          >
            Cancel
          </button>
          <PrimaryButton type="submit" :disabled="form.processing">Merge into {{ survivor.name }}</PrimaryButton>
        </div>
      </form>
    </div>
  </Modal>
</template>
