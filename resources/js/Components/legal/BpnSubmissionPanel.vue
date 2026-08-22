<!-- ponytail: bpn_submissions tracker (§3L) — manual tracker, no live BPN API at this scale -->
<script setup lang="ts">
import { ref } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import FormInput from '@/Components/forms/FormInput.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'

export interface BpnSubmissionRow {
  id: number
  submission_type: string
  submitted_at: string | null
  tracking_number: string | null
  pnbp_amount: string | null
  status: string
  completed_at: string | null
  rejection_reason: string | null
  resubmission_of_id: number | null
}

const props = defineProps<{
  deedId: number
  bpnSubmissions: BpnSubmissionRow[]
}>()

const submitForm = useForm({ tracking_number: '', submitted_at: '' })
const openSubmit = ref<number | null>(null)
const doSubmit = (id: number) => {
  submitForm.patch(route('legal.deeds.bpnSubmissions.submit', [props.deedId, id]), {
    preserveScroll: true,
    onSuccess: () => { openSubmit.value = null; submitForm.reset() },
  })
}

const rejectForm = useForm({ reason: '' })
const openReject = ref<number | null>(null)
const doReject = (id: number) => {
  rejectForm.patch(route('legal.deeds.bpnSubmissions.reject', [props.deedId, id]), {
    preserveScroll: true,
    onSuccess: () => { openReject.value = null; rejectForm.reset() },
  })
}

const markInProcess = (id: number) => router.patch(route('legal.deeds.bpnSubmissions.markInProcess', [props.deedId, id]), {}, { preserveScroll: true })
const complete = (id: number) => router.patch(route('legal.deeds.bpnSubmissions.complete', [props.deedId, id]), {}, { preserveScroll: true })
const resubmit = (id: number) => router.post(route('legal.deeds.bpnSubmissions.resubmit', [props.deedId, id]), {}, { preserveScroll: true })
</script>

<template>
  <div class="space-y-3">
    <p v-if="bpnSubmissions.length === 0" class="text-sm text-ink-600">No BPN submission yet — created automatically once this deed is signed.</p>

    <div v-for="s in bpnSubmissions" :key="s.id" class="rounded-sm border border-border p-3">
      <div class="flex items-center justify-between">
        <p class="text-sm font-medium text-ink-900">
          {{ s.submission_type.replace('_', ' ') }}
          <span v-if="s.resubmission_of_id" class="text-xs text-ink-600">(resubmission of #{{ s.resubmission_of_id }})</span>
        </p>
        <StatusBadge :status="s.status" />
      </div>
      <p class="mt-0.5 text-xs text-ink-600">
        PNBP: {{ s.pnbp_amount ?? '—' }}
        <span v-if="s.tracking_number"> · Tracking: {{ s.tracking_number }}</span>
      </p>
      <p v-if="s.rejection_reason" class="mt-1 text-sm text-signal-danger">{{ s.rejection_reason }}</p>

      <div v-if="s.status === 'prepared'" class="mt-2">
        <button v-if="openSubmit !== s.id" type="button" class="text-sm font-medium text-accent hover:underline" @click="openSubmit = s.id">
          Submit to BPN
        </button>
        <form v-else class="mt-1 space-y-2" @submit.prevent="doSubmit(s.id)">
          <FormInput v-model="submitForm.tracking_number" name="tracking_number" label="Tracking / receipt number" :error="submitForm.errors.tracking_number" />
          <FormInput v-model="submitForm.submitted_at" name="submitted_at" type="date" label="Submitted date" :error="submitForm.errors.submitted_at" />
          <PrimaryButton type="submit" :disabled="submitForm.processing">Save</PrimaryButton>
        </form>
      </div>

      <div v-if="s.status === 'submitted' || s.status === 'in_process'" class="mt-2 flex flex-wrap gap-3">
        <button v-if="s.status === 'submitted'" type="button" class="text-sm font-medium text-accent hover:underline" @click="markInProcess(s.id)">
          Mark in process
        </button>
        <button type="button" class="text-sm font-medium text-accent hover:underline" @click="complete(s.id)">
          Mark completed
        </button>
        <button v-if="openReject !== s.id" type="button" class="text-sm font-medium text-signal-danger hover:underline" @click="openReject = s.id">
          Reject
        </button>
      </div>
      <form v-if="openReject === s.id" class="mt-2 space-y-2" @submit.prevent="doReject(s.id)">
        <textarea
          v-model="rejectForm.reason"
          rows="2"
          placeholder="Rejection reason — required"
          class="w-full rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm text-ink-900 shadow-sm focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/20"
        />
        <PrimaryButton type="submit" :disabled="rejectForm.processing">Confirm reject</PrimaryButton>
      </form>

      <div v-if="s.status === 'rejected'" class="mt-2">
        <button type="button" class="text-sm font-medium text-accent hover:underline" @click="resubmit(s.id)">
          Resubmit (new submission)
        </button>
      </div>
    </div>
  </div>
</template>
