<!-- ponytail: §3D optional per-resource weekly working hours — one row per day,
     toggle enables/disables that day. No rows at all = available 24/7. -->
<script setup lang="ts">
import { computed } from 'vue'

export interface WorkingHourRow {
  day_of_week: number
  start_time: string
  end_time: string
}

const props = defineProps<{
  modelValue: WorkingHourRow[]
}>()

const emit = defineEmits<{
  'update:modelValue': [value: WorkingHourRow[]]
}>()

const DAYS = [
  { value: 0, label: 'Sunday' },
  { value: 1, label: 'Monday' },
  { value: 2, label: 'Tuesday' },
  { value: 3, label: 'Wednesday' },
  { value: 4, label: 'Thursday' },
  { value: 5, label: 'Friday' },
  { value: 6, label: 'Saturday' },
]

const rowFor = (day: number) => props.modelValue.find((r) => r.day_of_week === day)

const isEnabled = (day: number) => computed(() => !!rowFor(day)).value

const toggle = (day: number, enabled: boolean) => {
  if (enabled) {
    emit('update:modelValue', [...props.modelValue, { day_of_week: day, start_time: '09:00', end_time: '17:00' }])
  } else {
    emit('update:modelValue', props.modelValue.filter((r) => r.day_of_week !== day))
  }
}

const updateTime = (day: number, field: 'start_time' | 'end_time', value: string) => {
  emit('update:modelValue', props.modelValue.map((r) => (r.day_of_week === day ? { ...r, [field]: value } : r)))
}
</script>

<template>
  <div class="space-y-2">
    <p class="text-xs font-semibold uppercase tracking-wide text-ink-600">Working hours</p>
    <p class="text-xs text-ink-600">Leave every day off to treat this resource as available 24/7.</p>
    <div class="space-y-2">
      <div v-for="d in DAYS" :key="d.value" class="flex items-center gap-3">
        <label class="flex w-32 items-center gap-2 text-sm text-ink-900">
          <input
            type="checkbox"
            :checked="isEnabled(d.value)"
            @change="toggle(d.value, ($event.target as HTMLInputElement).checked)"
          />
          {{ d.label }}
        </label>
        <template v-if="rowFor(d.value)">
          <input
            type="time"
            :value="rowFor(d.value)?.start_time"
            class="rounded-md border border-border bg-white px-2 py-1 text-sm shadow-sm outline-none focus:border-ink-900 focus:ring-2 focus:ring-ink-900/10"
            @input="updateTime(d.value, 'start_time', ($event.target as HTMLInputElement).value)"
          />
          <span class="text-sm text-ink-600">to</span>
          <input
            type="time"
            :value="rowFor(d.value)?.end_time"
            class="rounded-md border border-border bg-white px-2 py-1 text-sm shadow-sm outline-none focus:border-ink-900 focus:ring-2 focus:ring-ink-900/10"
            @input="updateTime(d.value, 'end_time', ($event.target as HTMLInputElement).value)"
          />
        </template>
      </div>
    </div>
  </div>
</template>
