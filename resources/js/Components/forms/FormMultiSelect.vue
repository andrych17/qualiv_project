<!-- Reusable Searchable Multi-Select Dropdown Component -->
<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { Search, ChevronDown, Check, X } from 'lucide-vue-next'

export type MultiSelectOption = {
  label: string
  value: string | number
  description?: string
  [key: string]: any
}

const props = withDefaults(
  defineProps<{
    modelValue: Array<string | number>
    label?: string
    name?: string
    options: Array<any>
    placeholder?: string
    searchPlaceholder?: string
    error?: string
    required?: boolean
    disabled?: boolean
    clearable?: boolean
    selectAllButton?: boolean
    maxTags?: number
  }>(),
  {
    modelValue: () => [],
    label: '',
    name: '',
    placeholder: 'Pilih opsi...',
    searchPlaceholder: 'Cari opsi...',
    required: false,
    disabled: false,
    clearable: true,
    selectAllButton: true,
    maxTags: 5,
  }
)

const emit = defineEmits<{
  'update:modelValue': [value: Array<string | number>]
}>()

const isOpen = ref(false)
const searchQuery = ref('')
const searchInput = ref<HTMLInputElement | null>(null)
const containerRef = ref<HTMLDivElement | null>(null)

// Normalize options whether they pass { label, value } or { id, name }
const normalizedOptions = computed<MultiSelectOption[]>(() => {
  return (props.options || []).map((opt) => {
    if (typeof opt === 'object' && opt !== null) {
      if ('value' in opt && 'label' in opt) {
        return {
          label: String(opt.label),
          value: opt.value,
          description: opt.description,
        }
      }
      if ('id' in opt && 'name' in opt) {
        return {
          label: String(opt.name),
          value: opt.id,
          description: opt.description,
        }
      }
    }
    return {
      label: String(opt),
      value: opt,
    }
  })
})

const optionsMap = computed(() => {
  const map = new Map<string | number, MultiSelectOption>()
  for (const opt of normalizedOptions.value) {
    map.set(opt.value, opt)
  }
  return map
})

const getOptionLabel = (val: string | number) => {
  return optionsMap.value.get(val)?.label ?? String(val)
}

const isSelected = (val: string | number) => {
  return props.modelValue.includes(val)
}

const filteredOptions = computed(() => {
  if (!searchQuery.value.trim()) return normalizedOptions.value
  const q = searchQuery.value.toLowerCase().trim()
  return normalizedOptions.value.filter(
    (opt) =>
      opt.label.toLowerCase().includes(q) ||
      (opt.description && opt.description.toLowerCase().includes(q))
  )
})

const visibleSelectedValues = computed(() => {
  if (!props.maxTags || props.modelValue.length <= props.maxTags) {
    return props.modelValue
  }
  return props.modelValue.slice(0, props.maxTags)
})

const hiddenTagsCount = computed(() => {
  if (!props.maxTags || props.modelValue.length <= props.maxTags) {
    return 0
  }
  return props.modelValue.length - props.maxTags
})

const toggleDropdown = (e?: Event) => {
  e?.stopPropagation()
  if (props.disabled) return
  isOpen.value = !isOpen.value
  if (isOpen.value) {
    searchQuery.value = ''
    setTimeout(() => {
      searchInput.value?.focus()
    }, 50)
  }
}

const toggleOption = (val: string | number, e?: Event) => {
  e?.stopPropagation()
  if (props.disabled) return

  const current = [...props.modelValue]
  const idx = current.indexOf(val)
  if (idx === -1) {
    current.push(val)
  } else {
    current.splice(idx, 1)
  }
  emit('update:modelValue', current)
}

const removeValue = (val: string | number) => {
  if (props.disabled) return
  const current = props.modelValue.filter((v) => v !== val)
  emit('update:modelValue', current)
}

const clearSelection = (e?: MouseEvent) => {
  e?.stopPropagation()
  if (props.disabled) return
  emit('update:modelValue', [])
}

const toggleSelectAll = (e?: Event) => {
  e?.stopPropagation()
  if (props.disabled) return

  // If there is active search filter, act on filtered items
  const targetOptions = filteredOptions.value.length > 0 ? filteredOptions.value : normalizedOptions.value
  const targetValues = targetOptions.map((o) => o.value)
  const allSelected = targetValues.every((v) => props.modelValue.includes(v))

  if (allSelected) {
    // Deselect target items
    const remaining = props.modelValue.filter((v) => !targetValues.includes(v))
    emit('update:modelValue', remaining)
  } else {
    // Union of current + target items
    const combined = Array.from(new Set([...props.modelValue, ...targetValues]))
    emit('update:modelValue', combined)
  }
}

const areAllSelected = computed(() => {
  const targetOptions = filteredOptions.value.length > 0 ? filteredOptions.value : normalizedOptions.value
  if (targetOptions.length === 0) return false
  return targetOptions.every((o) => props.modelValue.includes(o.value))
})

const handleClickOutside = (e: MouseEvent) => {
  if (containerRef.value && !containerRef.value.contains(e.target as Node)) {
    isOpen.value = false
  }
}

const handleKeyDown = (e: KeyboardEvent) => {
  if (e.key === 'Escape') {
    isOpen.value = false
  }
}

onMounted(() => {
  document.addEventListener('click', handleClickOutside)
  document.addEventListener('keydown', handleKeyDown)
})

onUnmounted(() => {
  document.removeEventListener('click', handleClickOutside)
  document.removeEventListener('keydown', handleKeyDown)
})
</script>

<template>
  <div ref="containerRef" class="space-y-1.5 relative">
    <div v-if="label" class="flex justify-between items-center">
      <label class="text-sm font-medium text-ink-900">
        {{ label }}
        <span v-if="required" class="text-signal-danger">*</span>
      </label>
      <span v-if="modelValue.length > 0" class="text-xs text-ink-600">
        {{ modelValue.length }} terpilih
      </span>
    </div>

    <div class="relative">
      <!-- Input Trigger Button -->
      <div
        role="button"
        tabindex="0"
        @click="toggleDropdown"
        @keydown.enter.prevent="toggleDropdown"
        @keydown.space.prevent="toggleDropdown"
        class="w-full min-h-[42px] flex items-center justify-between rounded-md border border-border bg-white px-3 py-1.5 text-sm shadow-sm outline-none transition focus-within:border-ink-900 focus-within:ring-2 focus-within:ring-ink-900/10 cursor-pointer"
        :class="[
          error ? 'border-signal-danger focus-within:border-signal-danger focus-within:ring-signal-danger/10' : '',
          disabled ? 'bg-surface-50 cursor-not-allowed text-ink-600' : 'hover:border-ink-600/50'
        ]"
      >
        <!-- Chips or Placeholder -->
        <div class="flex-1 flex flex-wrap items-center gap-1.5 min-w-0 pr-2">
          <template v-if="modelValue.length > 0">
            <span
              v-for="val in visibleSelectedValues"
              :key="val"
              class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded bg-surface-50 border border-border text-ink-900 text-xs font-medium"
            >
              <span class="max-w-[180px] sm:max-w-[240px] truncate">{{ getOptionLabel(val) }}</span>
              <button
                v-if="!disabled"
                type="button"
                @click.stop.prevent="removeValue(val)"
                class="text-ink-600 hover:text-ink-900 focus:outline-none p-0.5 rounded hover:bg-border/40"
                title="Hapus"
              >
                <X class="h-3 w-3" />
              </button>
            </span>

            <span
              v-if="hiddenTagsCount > 0"
              class="inline-flex items-center px-2 py-0.5 rounded bg-surface-50 border border-border text-ink-600 text-xs font-semibold"
            >
              +{{ hiddenTagsCount }} lainnya
            </span>
          </template>

          <span v-else class="text-ink-600 select-none py-1">
            {{ placeholder }}
          </span>
        </div>

        <!-- Controls: Clear & Chevron -->
        <div class="flex items-center gap-1.5 shrink-0 ml-2">
          <button
            v-if="clearable && modelValue.length > 0 && !disabled"
            type="button"
            @click.stop.prevent="clearSelection"
            class="text-ink-600 hover:text-ink-900 p-1 rounded-full hover:bg-surface-50 transition"
            title="Hapus semua pilihan"
          >
            <X class="h-3.5 w-3.5" />
          </button>
          <ChevronDown
            class="h-4 w-4 text-ink-600 transition-transform duration-200"
            :class="{ 'rotate-180': isOpen }"
          />
        </div>
      </div>

      <!-- Dropdown Panel -->
      <div
        v-if="isOpen"
        @click.stop
        class="absolute left-0 right-0 z-40 mt-1 max-h-72 overflow-hidden rounded-md border border-border bg-white shadow-lg ring-1 ring-black/5 flex flex-col"
      >
        <!-- Search Input -->
        <div class="p-2 border-b border-border bg-surface-50 flex items-center gap-2">
          <Search class="h-4 w-4 text-ink-600 shrink-0" />
          <input
            ref="searchInput"
            v-model="searchQuery"
            type="text"
            :placeholder="searchPlaceholder"
            @keydown.stop="handleKeyDown"
            class="w-full bg-transparent text-sm outline-none text-ink-900 placeholder:text-ink-600 border-none p-0 focus:ring-0"
          />
          <button
            v-if="searchQuery"
            type="button"
            @click.stop.prevent="searchQuery = ''"
            class="text-ink-600 hover:text-ink-900 p-0.5"
          >
            <X class="h-3.5 w-3.5" />
          </button>
        </div>

        <!-- Action Bar: Select All & Status -->
        <div
          v-if="selectAllButton && normalizedOptions.length > 0"
          class="flex items-center justify-between px-3 py-1.5 bg-surface-50/70 border-b border-border text-xs"
        >
          <span class="text-ink-600">
            {{ modelValue.length }} dari {{ normalizedOptions.length }} dipilih
          </span>
          <button
            type="button"
            @click.stop.prevent="toggleSelectAll"
            class="font-semibold text-accent hover:underline focus:outline-none"
          >
            {{ areAllSelected ? 'Batal Pilih Semua' : 'Pilih Semua' }}
          </button>
        </div>

        <!-- Options List -->
        <div class="overflow-y-auto max-h-48 py-1 divide-y divide-border/30">
          <div
            v-if="filteredOptions.length === 0"
            class="px-4 py-4 text-center text-xs text-ink-600"
          >
            Tidak ada opsi yang cocok dengan "{{ searchQuery }}"
          </div>

          <div
            v-for="opt in filteredOptions"
            :key="opt.value"
            @click.stop.prevent="toggleOption(opt.value, $event)"
            class="w-full flex items-center gap-2.5 px-3 py-2 text-left text-sm transition-colors cursor-pointer select-none hover:bg-surface-50"
            :class="isSelected(opt.value) ? 'bg-accent/5 font-medium text-ink-900' : 'text-ink-900'"
          >
            <input
              type="checkbox"
              :checked="isSelected(opt.value)"
              class="rounded border-border text-accent focus:ring-accent shrink-0 cursor-pointer pointer-events-none"
            />
            <div class="flex-1 min-w-0">
              <div class="leading-tight truncate">{{ opt.label }}</div>
              <div v-if="opt.description" class="text-xs text-ink-600 mt-0.5 font-normal truncate">
                {{ opt.description }}
              </div>
            </div>
            <Check v-if="isSelected(opt.value)" class="h-4 w-4 text-accent shrink-0" />
          </div>
        </div>
      </div>
    </div>

    <p v-if="error" class="text-sm text-signal-danger">
      {{ error }}
    </p>
  </div>
</template>
