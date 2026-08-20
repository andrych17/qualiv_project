<!-- ponytail: repeatable Address rows — shared by Contact/Company Create+Edit -->
<script setup lang="ts">
import { Plus, Trash2 } from 'lucide-vue-next'
import FormSelect from '@/Components/forms/FormSelect.vue'

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
      <p class="text-xs font-semibold uppercase tracking-wide text-ink-600">Addresses</p>
      <button
        type="button"
        class="inline-flex items-center gap-1 text-xs font-medium text-accent hover:underline"
        @click="addRow"
      >
        <Plus class="h-3.5 w-3.5" /> Add address
      </button>
    </div>

    <div v-if="modelValue.length === 0" class="text-sm text-ink-600">No addresses added.</div>

    <div
      v-for="(row, index) in modelValue"
      :key="index"
      class="space-y-3 rounded-md border border-border p-3"
    >
      <div class="flex items-center justify-between gap-3">
        <div class="w-40">
          <FormSelect
            :model-value="row.type"
            :name="`addresses.${index}.type`"
            label="Type"
            :options="[
              { label: 'Office', value: 'office' },
              { label: 'Billing', value: 'billing' },
              { label: 'Shipping', value: 'shipping' },
              { label: 'Other', value: 'other' },
            ]"
            @update:model-value="update(index, { type: String($event) })"
          />
        </div>
        <label class="flex items-center gap-2 text-xs text-ink-600">
          <input
            type="checkbox"
            :checked="row.is_primary"
            @change="update(index, { is_primary: ($event.target as HTMLInputElement).checked })"
          />
          Primary
        </label>
        <button type="button" class="text-signal-danger" @click="removeRow(index)">
          <Trash2 class="h-4 w-4" />
        </button>
      </div>

      <input
        :value="row.line1"
        placeholder="Address line 1"
        class="w-full rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm text-ink-900 shadow-sm focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/20"
        @input="update(index, { line1: ($event.target as HTMLInputElement).value })"
      />
      <input
        :value="row.line2"
        placeholder="Address line 2 (optional)"
        class="w-full rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm text-ink-900 shadow-sm focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/20"
        @input="update(index, { line2: ($event.target as HTMLInputElement).value })"
      />
      <div class="grid grid-cols-2 gap-3">
        <input
          :value="row.city"
          placeholder="City"
          class="w-full rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm text-ink-900 shadow-sm focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/20"
          @input="update(index, { city: ($event.target as HTMLInputElement).value })"
        />
        <input
          :value="row.state_province"
          placeholder="State/Province"
          class="w-full rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm text-ink-900 shadow-sm focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/20"
          @input="update(index, { state_province: ($event.target as HTMLInputElement).value })"
        />
        <input
          :value="row.postal_code"
          placeholder="Postal code"
          class="w-full rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm text-ink-900 shadow-sm focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/20"
          @input="update(index, { postal_code: ($event.target as HTMLInputElement).value })"
        />
        <input
          :value="row.country"
          placeholder="Country"
          class="w-full rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm text-ink-900 shadow-sm focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/20"
          @input="update(index, { country: ($event.target as HTMLInputElement).value })"
        />
      </div>
    </div>
  </div>
</template>
