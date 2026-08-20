<!-- ponytail: partner_roles many-to-many picker — shared by Contact/Company forms -->
<script setup lang="ts">
const props = defineProps<{
  modelValue: number[]
  roleTypes: Array<{ id: number; name: string }>
}>()

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
    <p class="text-xs font-semibold uppercase tracking-wide text-ink-600">Roles</p>
    <div v-if="roleTypes.length === 0" class="text-sm text-ink-600">
      No role types configured yet — add one under Config.
    </div>
    <div v-else class="flex flex-wrap gap-3">
      <label
        v-for="rt in roleTypes"
        :key="rt.id"
        class="flex items-center gap-1.5 rounded-full border border-border px-3 py-1.5 text-sm text-ink-900"
      >
        <input
          type="checkbox"
          :checked="modelValue.includes(rt.id)"
          @change="toggle(rt.id, ($event.target as HTMLInputElement).checked)"
        />
        {{ rt.name }}
      </label>
    </div>
  </div>
</template>
