<!-- ponytail: repeatable Address rows — shared by Contact/Company Create+Edit -->
<script setup lang="ts">
import { computed } from 'vue'
import { Plus, Trash2 } from 'lucide-vue-next'
import FormSelect from '@/Components/forms/FormSelect.vue'
import Checkbox from '@/Components/Checkbox.vue'
import { useI18n } from '@/Composables/useI18n'

export interface AddressRow {
  type: string
  line1: string
  line2: string
  city: string
  state_province: string
  postal_code: string
  country: string
  is_primary: boolean
}

const props = defineProps<{
  modelValue: AddressRow[]
}>()

const emit = defineEmits<{
  'update:modelValue': [value: AddressRow[]]
}>()

const { t } = useI18n()

const addressTypeOptions = computed(() => [
  { label: t('crm.address_office'), value: 'office' },
  { label: t('crm.address_billing'), value: 'billing' },
  { label: t('crm.address_shipping'), value: 'shipping' },
  { label: t('crm.address_other'), value: 'other' },
])

const emptyRow = (): AddressRow => ({
  type: 'office',
  line1: '',
  line2: '',
  city: '',
  state_province: '',
  postal_code: '',
  country: '',
  is_primary: props.modelValue.length === 0,
})

const addRow = () => emit('update:modelValue', [...props.modelValue, emptyRow()])
const removeRow = (index: number) => emit('update:modelValue', props.modelValue.filter((_, i) => i !== index))
const update = (index: number, patch: Partial<AddressRow>) => {
  const rows = props.modelValue.map((row, i) => (i === index ? { ...row, ...patch } : row))
  emit('update:modelValue', rows)
}
</script>

<template>
  <div class="space-y-3">
    <div class="flex items-center justify-between">
      <p class="text-xs font-semibold uppercase tracking-wide text-ink-600">
        {{ t('crm.addresses') }}
      </p>
      <button
        type="button"
        class="inline-flex items-center gap-1 text-xs font-medium text-accent hover:underline cursor-pointer"
        @click="addRow"
      >
        <Plus class="h-3.5 w-3.5" /> {{ t('crm.add_address') }}
      </button>
    </div>

    <div v-if="modelValue.length === 0" class="text-sm text-ink-500 italic">
      {{ t('crm.no_addresses') }}
    </div>

    <div
      v-for="(row, index) in modelValue"
      :key="index"
      class="space-y-3 rounded-xl border border-border bg-surface-50/50 p-3.5 transition-colors"
    >
      <div class="flex items-center justify-between gap-3">
        <div class="w-44">
          <FormSelect
            :model-value="row.type"
            :name="`addresses.${index}.type`"
            :label="t('crm.address_type')"
            :options="addressTypeOptions"
            @update:model-value="update(index, { type: String($event) })"
          />
        </div>

        <div class="flex items-center gap-4">
          <label class="flex items-center gap-2 text-xs font-medium text-ink-700 cursor-pointer select-none">
            <Checkbox
              :checked="row.is_primary"
              @update:checked="update(index, { is_primary: $event })"
            />
            <span>{{ t('crm.is_primary') }}</span>
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

      <input
        :value="row.line1"
        :placeholder="t('crm.address_line1')"
        class="w-full rounded-lg border border-border bg-surface-0 px-3 py-2 text-sm text-ink-900 shadow-2xs placeholder:text-ink-400 focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/20"
        @input="update(index, { line1: ($event.target as HTMLInputElement).value })"
      />
      <input
        :value="row.line2"
        :placeholder="t('crm.address_line2')"
        class="w-full rounded-lg border border-border bg-surface-0 px-3 py-2 text-sm text-ink-900 shadow-2xs placeholder:text-ink-400 focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/20"
        @input="update(index, { line2: ($event.target as HTMLInputElement).value })"
      />
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <input
          :value="row.city"
          :placeholder="t('crm.city')"
          class="w-full rounded-lg border border-border bg-surface-0 px-3 py-2 text-sm text-ink-900 shadow-2xs placeholder:text-ink-400 focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/20"
          @input="update(index, { city: ($event.target as HTMLInputElement).value })"
        />
        <input
          :value="row.state_province"
          :placeholder="t('crm.state_province')"
          class="w-full rounded-lg border border-border bg-surface-0 px-3 py-2 text-sm text-ink-900 shadow-2xs placeholder:text-ink-400 focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/20"
          @input="update(index, { state_province: ($event.target as HTMLInputElement).value })"
        />
        <input
          :value="row.postal_code"
          :placeholder="t('crm.postal_code')"
          class="w-full rounded-lg border border-border bg-surface-0 px-3 py-2 text-sm text-ink-900 shadow-2xs placeholder:text-ink-400 focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/20"
          @input="update(index, { postal_code: ($event.target as HTMLInputElement).value })"
        />
        <input
          :value="row.country"
          :placeholder="t('crm.country')"
          class="w-full rounded-lg border border-border bg-surface-0 px-3 py-2 text-sm text-ink-900 shadow-2xs placeholder:text-ink-400 focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/20"
          @input="update(index, { country: ($event.target as HTMLInputElement).value })"
        />
      </div>
    </div>
  </div>
</template>
