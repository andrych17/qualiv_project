<!-- ponytail: wasiat / will tracking (§3D) — DPW registration is the highest-liability step -->
<script setup lang="ts">
import { ref } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import FormInput from '@/Components/forms/FormInput.vue'
import FormAsyncSearchableSelect from '@/Components/forms/FormAsyncSearchableSelect.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import { useConfirm } from '@/Composables/useConfirmDialog'

export interface WillRow {
  id: number
  testator_partner_id: number
  dpw_reg_number: string | null
  dpw_registered_at: string | null
  status: string
  closing_notes: string | null
}

const props = defineProps<{
  deedId: number
  will: WillRow | null
}>()

const createForm = useForm({ testator_partner_id: null as number | null })
const submitCreate = () => createForm.post(route('legal.deeds.will.store', props.deedId), { preserveScroll: true })

const dpwForm = useForm({ dpw_reg_number: '', dpw_registered_at: '' })
const showDpwForm = ref(false)
const submitDpw = () => {
  dpwForm.patch(route('legal.deeds.will.registerDpw', [props.deedId, props.will!.id]), {
    preserveScroll: true,
    onSuccess: () => { showDpwForm.value = false },
  })
}

const activate = () => router.patch(route('legal.deeds.will.activate', [props.deedId, props.will!.id]), {}, { preserveScroll: true })

const { confirm } = useConfirm()
const noteForm = useForm({ notes: '' })

// ponytail: window.prompt for the required note. Ceiling: no multi-line/rich input, no
// cancel-without-empty-string distinction. Upgrade to a proper modal form if open/revoke
// becomes a frequent action instead of a rare, deliberate one.
const requestOpenOrRevoke = (action: 'open' | 'revoke') => {
  confirm({
    title: action === 'open' ? 'Mark this will as opened (executed)?' : 'Revoke this will?',
    description: 'This is logged permanently — provide a note in the next step.',
    variant: action === 'revoke' ? 'destructive' : 'default',
    confirmText: 'Continue',
    onConfirm: () => {
      const note = window.prompt(`Note for ${action} (required)`) ?? ''
      if (!note) return
      noteForm.notes = note
      const routeName = action === 'open' ? 'legal.deeds.will.open' : 'legal.deeds.will.revoke'
      noteForm.patch(route(routeName, [props.deedId, props.will!.id]), { preserveScroll: true })
    },
  })
}
</script>

<template>
  <div class="space-y-4">
    <form v-if="!will" class="space-y-3" @submit.prevent="submitCreate">
      <FormAsyncSearchableSelect
        v-model="createForm.testator_partner_id"
        name="testator_partner_id"
        label="Testator"
        api-entity="crm_partner"
        placeholder="Search for a contact"
        :error="createForm.errors.testator_partner_id"
      />
      <PrimaryButton type="submit" :disabled="createForm.processing">Create will record</PrimaryButton>
    </form>

    <div v-else class="space-y-3">
      <div class="flex items-center justify-between">
        <p class="text-sm font-medium text-ink-900">Will status</p>
        <StatusBadge :status="will.status" />
      </div>

      <p v-if="will.status === 'drafted'" class="rounded-sm border border-signal-warning/40 bg-signal-warning/10 px-3 py-2 text-xs text-ink-900">
        Not yet registered with Daftar Pusat Wasiat (DPW) — the single highest-liability gap in will practice.
      </p>

      <div v-if="will.status === 'drafted'">
        <button v-if="!showDpwForm" type="button" class="text-sm font-medium text-accent hover:underline" @click="showDpwForm = true">
          Register with DPW
        </button>
        <form v-else class="mt-2 space-y-2" @submit.prevent="submitDpw">
          <FormInput v-model="dpwForm.dpw_reg_number" name="dpw_reg_number" label="DPW registration number" :error="dpwForm.errors.dpw_reg_number" required />
          <FormInput v-model="dpwForm.dpw_registered_at" name="dpw_registered_at" type="date" label="Registered date" :error="dpwForm.errors.dpw_registered_at" />
          <PrimaryButton type="submit" :disabled="dpwForm.processing">Save</PrimaryButton>
        </form>
      </div>

      <p v-if="will.dpw_reg_number" class="text-xs text-ink-600">
        DPW #{{ will.dpw_reg_number }} — registered {{ will.dpw_registered_at }}
      </p>

      <button v-if="will.status === 'dpw_registered'" type="button" class="text-sm font-medium text-accent hover:underline" @click="activate">
        Activate
      </button>

      <div v-if="will.status === 'active'" class="flex gap-3">
        <button type="button" class="text-sm font-medium text-accent hover:underline" @click="requestOpenOrRevoke('open')">
          Mark opened (executed)
        </button>
        <button type="button" class="text-sm font-medium text-signal-danger hover:underline" @click="requestOpenOrRevoke('revoke')">
          Revoke
        </button>
      </div>

      <p v-if="will.closing_notes" class="text-xs text-ink-600">Note: {{ will.closing_notes }}</p>
    </div>
  </div>
</template>
