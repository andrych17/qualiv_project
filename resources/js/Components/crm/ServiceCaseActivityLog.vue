<!-- ponytail: svc_case_activities feed + quick note (§3E) — status_change entries are
     system-generated (via ServiceCaseService::updateStatus), so unlike LeadActivityLog
     there's no type picker: the user can only add a note. -->
<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import PrimaryButton from '@/Components/PrimaryButton.vue'

export interface ServiceCaseActivityRow {
  id: number
  activity_type: string
  body: string | null
  logged_by_name: string | null
  logged_at_formatted: string | null
}

const props = defineProps<{
  caseId: number
  activities: ServiceCaseActivityRow[]
}>()

const form = useForm({ body: '' })

const submit = () => {
  form.post(route('crm.serviceCases.activities.store', props.caseId), {
    preserveScroll: true,
    onSuccess: () => form.reset('body'),
  })
}

const TYPE_LABEL: Record<string, string> = {
  note: 'Note',
  status_change: 'Status change',
  attachment: 'Attachment',
}
</script>

<template>
  <div class="space-y-4">
    <form class="space-y-2" @submit.prevent="submit">
      <div class="flex items-end gap-2">
        <div class="flex-1">
          <textarea
            v-model="form.body"
            rows="2"
            placeholder="Add a note…"
            class="w-full rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm text-ink-900 shadow-sm focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/20"
          />
        </div>
        <PrimaryButton type="submit" :disabled="form.processing || !form.body">Add</PrimaryButton>
      </div>
    </form>

    <div v-if="activities.length === 0" class="text-sm text-ink-600">No activity yet.</div>
    <ul v-else class="space-y-3">
      <li
        v-for="a in activities"
        :key="a.id"
        class="border-l-2 pl-3"
        :class="a.activity_type === 'status_change' ? 'border-accent' : 'border-border'"
      >
        <p class="text-xs font-medium uppercase tracking-wide text-ink-600">
          {{ TYPE_LABEL[a.activity_type] ?? a.activity_type }}
          <span class="font-normal normal-case text-ink-600/70">
            — {{ a.logged_by_name ?? 'System' }} · {{ a.logged_at_formatted }}
          </span>
        </p>
        <p v-if="a.body" class="mt-0.5 text-sm text-ink-900">{{ a.body }}</p>
      </li>
    </ul>
  </div>
</template>
