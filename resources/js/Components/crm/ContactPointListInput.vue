<!-- ponytail: repeatable Contact Point rows — shared by Contact/Company Create+Edit -->
<script setup lang="ts">
import { computed } from 'vue'
import { Plus, Trash2 } from 'lucide-vue-next'
import FormSelect from '@/Components/forms/FormSelect.vue'
import Checkbox from '@/Components/Checkbox.vue'
import { useI18n } from '@/Composables/useI18n'

export interface ContactPointRow {
  type: string
  value: string
  is_primary: boolean
  opt_out: boolean
}

const props = defineProps<{
  modelValue: ContactPointRow[]
}>()

const emit = defineEmits<{
  'update:modelValue': [value: ContactPointRow[]]
}>()

const { t } = useI18n()

const channelTypeOptions = computed(() => [
  { label: t('crm.channel_email'), value: 'email' },
  { label: t('crm.channel_phone'), value: 'phone' },
  { label: t('crm.channel_mobile'), value: 'mobile' },
  { label: t('crm.channel_fax'), value: 'fax' },
])

const emptyRow = (): ContactPointRow => ({
  type: 'email',
  value: '',
  is_primary: props.modelValue.length === 0,
  opt_out: false,
})

const addRow = () => emit('update:modelValue', [...props.modelValue, emptyRow()])
const removeRow = (index: number) => emit('update:modelValue', props.modelValue.filter((_, i) => i !== index))
const update = (index: number, patch: Partial<ContactPointRow>) => {
  const rows = props.modelValue.map((row, i) => (i === index ? { ...row, ...patch } : row))
  emit('update:modelValue', rows)
}
</script>

<template>
  <div class="space-y-3">
    <div class="flex items-center justify-between">
      <p class="text-xs font-semibold uppercase tracking-wide text-ink-600">
        {{ t('crm.contact_points') }}
      </p>
      <button
        type="button"
        class="inline-flex items-center gap-1 text-xs font-medium text-accent hover:underline cursor-pointer"
        @click="addRow"
      >
        <Plus class="h-3.5 w-3.5" /> {{ t('crm.add_contact_point') }}
      </button>
    </div>

    <div v-if="modelValue.length === 0" class="text-sm text-ink-500 italic">
      {{ t('crm.no_contact_points') }}
    </div>

    <div
      v-for="(row, index) in modelValue"
      :key="index"
      class="flex flex-col sm:flex-row items-stretch sm:items-center gap-3 rounded-xl border border-border bg-surface-50/50 p-3.5 transition-colors"
    >
      <div class="w-full sm:w-36 shrink-0">
        <FormSelect
          :model-value="row.type"
          :name="`contact_points.${index}.type`"
          :options="channelTypeOptions"
          @update:model-value="update(index, { type: String($event) })"
        />
      </div>

      <input
        :value="row.value"
        :placeholder="t('crm.contact_value_placeholder')"
        class="flex-1 rounded-lg border border-border bg-surface-0 px-3 py-2 text-sm text-ink-900 shadow-2xs placeholder:text-ink-400 focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/20"
        @input="update(index, { value: ($event.target as HTMLInputElement).value })"
      />

      <div class="flex items-center gap-4 shrink-0">
        <label class="flex items-center gap-2 text-xs font-medium text-ink-700 cursor-pointer select-none">
          <Checkbox
            :checked="row.is_primary"
            @update:checked="update(index, { is_primary: $event })"
          />
          <span>{{ t('crm.is_primary') }}</span>
        </label>

        <label class="flex items-center gap-2 text-xs font-medium text-ink-700 cursor-pointer select-none">
          <Checkbox
            :checked="row.opt_out"
            @update:checked="update(index, { opt_out: $event })"
          />
          <span>{{ t('crm.opt_out') }}</span>
        </label>

        <button
          type="button"
          class="text-signal-danger hover:text-signal-danger/80 p-1 rounded-md transition-colors cursor-pointer"
          :title="t('common.delete')"
          @click="removeRow(index)"
        >
          <Trash2 class="h-4 w-4" />
        </button>
      </div>
    </div>
  </div>
</template>
