<!-- ponytail: generic id/name multi-picker — watchers/attendees (users) and resources,
     same shape as CRM's RoleTypeCheckboxes.vue -->
<script setup lang="ts">
const props = withDefaults(defineProps<{
  modelValue: number[]
  options: Array<{ id: number; name: string }>
  label?: string
  emptyText?: string
}>(), {
  emptyText: 'None available.',
})

const emit = defineEmits<{
  'update:modelValue': [value: number[]]
}>()

const toggle = (id: number, checked: boolean) => {
  const set = new Set(props.modelValue)
  checked ? set.add(id) : set.delete(id)
  emit('update:modelValue', Array.from(set))
}
</script>

<template>
  <div class="space-y-2">
    <p class="text-xs font-semibold uppercase tracking-wide text-ink-600">{{ label ?? 'Options' }}</p>
    <div v-if="options.length === 0" class="text-sm text-ink-600">{{ emptyText }}</div>
    <div v-else class="flex flex-wrap gap-3">
      <label
        v-for="o in options"
        :key="o.id"
        class="flex items-center gap-1.5 rounded-full border border-border px-3 py-1.5 text-sm text-ink-900"
      >
        <input
          type="checkbox"
          :checked="modelValue.includes(o.id)"
          @change="toggle(o.id, ($event.target as HTMLInputElement).checked)"
        />
        {{ o.name }}
      </label>
    </div>
  </div>
</template>
