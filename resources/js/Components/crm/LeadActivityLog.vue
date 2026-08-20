<!-- ponytail: lead_activities feed + quick-add — the "Comment/Activity Thread" DESIGN.md
     lists as a composite, scoped here to what Leads (§3D) need. -->
<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import FormSelect from '@/Components/forms/FormSelect.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'

export interface LeadActivityRow {
  id: number
  activity_type: string
  body: string | null
  logged_by_name: string | null
  logged_at_formatted: string | null
}

const props = defineProps<{
  leadId: number
  activities: LeadActivityRow[]
}>()

const form = useForm({
  activity_type: 'note',
  body: '',
})

const submit = () => {
  form.post(route('crm.leads.activities.store', props.leadId), {
    preserveScroll: true,
    onSuccess: () => form.reset('body'),
  })
}

const TYPE_LABEL: Record<string, string> = {
  call: 'Call',
  email: 'Email',
  meeting: 'Meeting',
  note: 'Note',
}
</script>

<template>
  <div class="space-y-4">
    <form class="space-y-2" @submit.prevent="submit">
      <div class="flex items-end gap-2">
        <div class="w-32">
          <FormSelect
            v-model="form.activity_type"
            name="activity_type"
            label="Log activity"
            :options="[
              { label: 'Note', value: 'note' },
              { label: 'Call', value: 'call' },
              { label: 'Email', value: 'email' },
              { label: 'Meeting', value: 'meeting' },
            ]"
          />
        </div>
        <div class="flex-1">
          <textarea
            v-model="form.body"
            rows="2"
            placeholder="What happened?"
            class="w-full rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm text-ink-900 shadow-sm focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/20"
          />
        </div>
        <PrimaryButton type="submit" :disabled="form.processing">Add</PrimaryButton>
      </div>
    </form>

    <div v-if="activities.length === 0" class="text-sm text-ink-600">No activity logged yet.</div>
    <ul v-else class="space-y-3">
      <li v-for="a in activities" :key="a.id" class="border-l-2 border-border pl-3">
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
