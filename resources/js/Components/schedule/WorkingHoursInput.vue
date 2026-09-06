<!-- ponytail: §3D optional per-resource weekly working hours — one row per day,
     toggle enables/disables that day. No rows at all = available 24/7. -->
<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from '@/Composables/useI18n'

const { t } = useI18n()

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

const DAYS = computed(() => [
  { value: 0, label: t('days.sun') },
  { value: 1, label: t('days.mon') },
  { value: 2, label: t('days.tue') },
  { value: 3, label: t('days.wed') },
  { value: 4, label: t('days.thu') },
  { value: 5, label: t('days.fri') },
  { value: 6, label: t('days.sat') },
])

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
    <p class="text-xs font-semibold uppercase tracking-wide text-ink-600">{{ t('schedule.working_hours') }}</p>
    <p class="text-xs text-ink-600">{{ t('schedule.working_hours_desc') }}</p>
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
            class="rounded-md border border-border bg-surface-0 text-ink-900 px-2 py-1 text-sm shadow-sm outline-none focus:border-accent focus:ring-2 focus:ring-accent/20"
            @input="updateTime(d.value, 'start_time', ($event.target as HTMLInputElement).value)"
          />
          <span class="text-sm text-ink-600">–</span>
          <input
            type="time"
            :value="rowFor(d.value)?.end_time"
            class="rounded-md border border-border bg-surface-0 text-ink-900 px-2 py-1 text-sm shadow-sm outline-none focus:border-accent focus:ring-2 focus:ring-accent/20"
            @input="updateTime(d.value, 'end_time', ($event.target as HTMLInputElement).value)"
          />
        </template>
      </div>
    </div>
  </div>
</template>
