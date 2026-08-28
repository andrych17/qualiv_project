<!-- ponytail: §3P shipment package picker — checkbox list of packed, unassigned packages
     (or already on this shipment, when editing), shared by Create/Edit. -->
<script setup lang="ts">
export interface EligiblePackList {
  id: number
  warehouse_id: number
  pick_list_id: number
  package_type: 'carton' | 'pallet'
  weight: number | null
  weight_uom: string | null
}

const props = defineProps<{
  modelValue: number[]
  eligiblePackLists: EligiblePackList[]
}>()

const emit = defineEmits<{ 'update:modelValue': [number[]] }>()

const toggle = (id: number, checked: boolean) => {
  emit('update:modelValue', checked ? [...props.modelValue, id] : props.modelValue.filter((v) => v !== id))
}
</script>

<template>
  <div class="space-y-2">
    <p v-if="eligiblePackLists.length === 0" class="text-sm text-ink-600">No packed, unassigned packages in this warehouse.</p>
    <label
      v-for="p in eligiblePackLists"
      :key="p.id"
      class="flex items-center gap-3 rounded-md border border-border p-3"
    >
      <input
        type="checkbox"
        :checked="modelValue.includes(p.id)"
        class="h-4 w-4 rounded border-border text-accent focus:ring-accent"
        @change="toggle(p.id, ($event.target as HTMLInputElement).checked)"
      />
      <div class="flex-1 text-sm">
        <span class="font-semibold text-ink-900">Package #{{ p.id }}</span>
        <span class="text-ink-600"> — {{ p.package_type }}, from Pick List #{{ p.pick_list_id }}</span>
        <span v-if="p.weight !== null" class="text-ink-600"> — {{ p.weight }} {{ p.weight_uom }}</span>
      </div>
    </label>
  </div>
</template>
