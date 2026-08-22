<!-- ponytail: Field visit check-in/complete (§3M) — browser geolocation stands in for native
     GPS capture until a real mobile client exists; offline queueing is explicitly out of
     scope for this web slice (see migration docblock for the full deferred-wiring note). -->
<script setup lang="ts">
import { ref } from 'vue'
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'

interface ChecklistItem {
  label: string
  done: boolean
  note: string
}

const props = defineProps<{
  visit: {
    id: number
    matter_id: number | null
    land_object_id: number | null
    deed_id: number | null
    visit_type_id: number
    assigned_to: number | null
    status: string
    checked_in_at: string | null
    gps_lat: string | null
    gps_lng: string | null
    checklist_result: ChecklistItem[]
    notes: string | null
  }
  visitTypes: Array<{ id: number; name: string }>
}>()

const visitTypeName = props.visitTypes.find((t) => t.id === props.visit.visit_type_id)?.name ?? '—'

const checkInForm = useForm({ gps_lat: '', gps_lng: '' })
const geoError = ref('')
const geoLoading = ref(false)

const captureLocation = () => {
  geoError.value = ''
  if (!navigator.geolocation) {
    geoError.value = 'Geolocation is not available in this browser.'
    return
  }
  geoLoading.value = true
  navigator.geolocation.getCurrentPosition(
    (pos) => {
      checkInForm.gps_lat = String(pos.coords.latitude)
      checkInForm.gps_lng = String(pos.coords.longitude)
      geoLoading.value = false
    },
    (err) => {
      geoError.value = err.message
      geoLoading.value = false
    },
  )
}

const submitCheckIn = () => checkInForm.patch(route('legal.fieldVisits.checkIn', props.visit.id))

const completeForm = useForm({
  checklist_result: props.visit.checklist_result.map((c) => ({ ...c })),
  notes: props.visit.notes ?? '',
})
const submitComplete = () => completeForm.patch(route('legal.fieldVisits.complete', props.visit.id))
</script>

<template>
  <AppLayout>
    <PageHeader :title="visitTypeName" :description="`Visit #${visit.id}`">
      <template #actions>
        <StatusBadge :status="visit.status" />
      </template>
    </PageHeader>

    <div class="mt-6 max-w-2xl space-y-6">
      <Panel v-if="visit.status === 'scheduled'">
        <p class="mb-3 text-sm text-ink-600">Check in when you arrive on site — GPS is captured at this moment.</p>
        <button
          type="button"
          class="rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm font-semibold text-ink-900 shadow-sm hover:bg-surface-50"
          :disabled="geoLoading"
          @click="captureLocation"
        >
          {{ geoLoading ? 'Getting location…' : 'Capture my location' }}
        </button>
        <p v-if="geoError" class="mt-2 text-sm text-signal-danger">{{ geoError }}</p>
        <p v-if="checkInForm.gps_lat" class="mt-2 text-xs text-ink-600">{{ checkInForm.gps_lat }}, {{ checkInForm.gps_lng }}</p>
        <div class="mt-4">
          <PrimaryButton :disabled="!checkInForm.gps_lat || checkInForm.processing" @click="submitCheckIn">
            Check in
          </PrimaryButton>
        </div>
      </Panel>

      <Panel v-if="visit.status === 'checked_in'">
        <p class="mb-3 text-xs font-semibold uppercase tracking-wide text-ink-600">
          Checked in {{ visit.checked_in_at }} at {{ visit.gps_lat }}, {{ visit.gps_lng }}
        </p>
        <p class="mb-3 text-sm text-ink-600">
          Photos/scans go through DMS — upload separately and tag them to this visit
          (<code>subject_type = 'legal.field_visits'</code>, <code>subject_id = {{ visit.id }}</code>).
        </p>
        <form class="space-y-4" @submit.prevent="submitComplete">
          <div v-if="completeForm.checklist_result.length" class="space-y-2">
            <p class="text-sm font-medium text-ink-900">Checklist</p>
            <div v-for="(item, i) in completeForm.checklist_result" :key="i" class="rounded-sm border border-border p-2">
              <label class="flex items-center gap-2 text-sm text-ink-900">
                <input v-model="item.done" type="checkbox" class="rounded border-border" />
                {{ item.label }}
              </label>
              <input
                v-model="item.note"
                type="text"
                placeholder="Note (optional)"
                class="mt-1 w-full rounded-sm border border-border bg-surface-0 px-2 py-1 text-xs text-ink-900"
              />
            </div>
          </div>
          <div class="space-y-1.5">
            <label class="text-sm font-medium text-ink-900">Closing note</label>
            <textarea
              v-model="completeForm.notes"
              rows="3"
              class="w-full rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm text-ink-900 shadow-sm focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/20"
            />
          </div>
          <PrimaryButton type="submit" :disabled="completeForm.processing">Complete visit</PrimaryButton>
        </form>
      </Panel>

      <Panel v-if="visit.status === 'completed'">
        <p class="text-xs font-semibold uppercase tracking-wide text-ink-600">Checklist</p>
        <ul class="mt-2 space-y-1 text-sm text-ink-900">
          <li v-for="(item, i) in visit.checklist_result" :key="i">
            {{ item.done ? '✓' : '✗' }} {{ item.label }}
            <span v-if="item.note" class="text-ink-600"> — {{ item.note }}</span>
          </li>
        </ul>
        <p v-if="visit.notes" class="mt-3 text-sm text-ink-900">{{ visit.notes }}</p>
      </Panel>

      <Link
        :href="route('legal.fieldVisits.index')"
        class="inline-flex items-center justify-center rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm font-semibold text-ink-900 shadow-sm transition hover:bg-surface-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
      >
        Back
      </Link>
    </div>
  </AppLayout>
</template>
