<!-- ponytail: WNE §3J — self-service preference center for the logged-in user. One form,
     one submit: per-category channel selection + opt-out, plus per-channel quiet hours.
     Mandatory categories disable the opt-out switch rather than hide it, so a user can see
     *why* a category can't be turned off instead of wondering where the toggle went. -->
<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSwitch from '@/Components/forms/FormSwitch.vue'
import Checkbox from '@/Components/Checkbox.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import WneSubNav from '@/Components/wne/WneSubNav.vue'

type CategoryRow = {
  code: string
  name: string
  is_mandatory: boolean
  is_urgent: boolean
  default_channels: string[]
  channels: string[]
  opted_out: boolean
}

type QuietHoursRow = {
  channel: string
  start_time: string | null
  end_time: string | null
}

const props = defineProps<{
  categories: CategoryRow[]
  quietHours: QuietHoursRow[]
  channels: string[]
}>()

const CHANNEL_LABELS: Record<string, string> = { email: 'Email', sms: 'SMS', push: 'Push', in_app: 'In-app' }

const form = useForm({
  preferences: props.categories.map((c) => ({ category_code: c.code, channels: [...c.channels], opted_out: c.opted_out })),
  quiet_hours: props.quietHours.map((q) => ({ channel: q.channel, start_time: q.start_time, end_time: q.end_time })),
})

// Mirrors form.preferences 1:1 by index — kept alongside the category metadata (is_mandatory,
// is_urgent) that the plain form payload doesn't need to carry back to the server. `pref` is
// a direct reference into form.preferences[i], already reactive via useForm — this array
// itself is never resized, so it doesn't need its own reactive() wrapper.
const rows = props.categories.map((c, i) => ({ meta: c, pref: form.preferences[i] }))

const toggleChannel = (pref: (typeof form.preferences)[number], channel: string, checked: boolean) => {
  if (checked) {
    if (!pref.channels.includes(channel)) pref.channels.push(channel)
  } else {
    pref.channels = pref.channels.filter((c) => c !== channel)
  }
}

const submit = () => {
  form.post(route('wne.preferences.update'), { preserveScroll: true })
}
</script>

<template>
  <AppLayout>
    <PageHeader title="Preferences" description="Choose how and when you're notified — per category and per channel." />

    <WneSubNav active="preferences" class="mt-6" />

    <form class="mt-6 space-y-6" @submit.prevent="submit">
      <Panel>
        <h2 class="font-serif text-lg font-semibold text-ink-900">Notification channels</h2>
        <p class="mt-1 text-sm text-ink-600">Leave a category untouched to keep receiving it on its default channels.</p>

        <div class="mt-4 divide-y divide-border">
          <div v-for="row in rows" :key="row.meta.code" class="py-4 first:pt-0 last:pb-0">
            <div class="flex flex-wrap items-start justify-between gap-4">
              <div>
                <p class="text-sm font-medium text-ink-900">{{ row.meta.name }}</p>
                <p class="text-xs text-ink-600">
                  {{ row.meta.code }}
                  <span v-if="row.meta.is_mandatory"> · mandatory, cannot be opted out of</span>
                  <span v-if="row.meta.is_urgent"> · urgent, always bypasses quiet hours</span>
                </p>
              </div>

              <FormSwitch
                :modelValue="row.pref.opted_out"
                label="Opt out"
                :disabled="row.meta.is_mandatory"
                @update:modelValue="(v: boolean) => { row.pref.opted_out = v; if (v) row.pref.channels = [] }"
              />
            </div>

            <div v-if="!row.pref.opted_out" class="mt-3 flex flex-wrap gap-4">
              <label v-for="channel in channels" :key="channel" class="flex items-center gap-2 text-sm text-ink-900">
                <Checkbox
                  :checked="row.pref.channels.includes(channel)"
                  @update:checked="(v: boolean) => toggleChannel(row.pref, channel, v)"
                />
                {{ CHANNEL_LABELS[channel] ?? channel }}
              </label>
            </div>
          </div>
        </div>
      </Panel>

      <Panel>
        <h2 class="font-serif text-lg font-semibold text-ink-900">Quiet hours</h2>
        <p class="mt-1 text-sm text-ink-600">
          During these windows, non-urgent notifications on that channel wait until the window ends instead of arriving immediately.
          Leave both times blank to disable quiet hours for a channel.
        </p>

        <div class="mt-4 space-y-4">
          <div v-for="qh in form.quiet_hours" :key="qh.channel" class="grid grid-cols-1 gap-4 sm:grid-cols-3 sm:items-end">
            <p class="text-sm font-medium text-ink-900 sm:pb-2">{{ CHANNEL_LABELS[qh.channel] ?? qh.channel }}</p>
            <FormInput v-model="qh.start_time" :name="`start_${qh.channel}`" label="Start" type="time" />
            <FormInput v-model="qh.end_time" :name="`end_${qh.channel}`" label="End" type="time" />
          </div>
        </div>
      </Panel>

      <div class="flex justify-end">
        <PrimaryButton type="submit" :disabled="form.processing">Save preferences</PrimaryButton>
      </div>
    </form>
  </AppLayout>
</template>
