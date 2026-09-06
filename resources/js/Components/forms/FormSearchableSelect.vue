<!-- ponytail: Reusable Searchable Dropdown / Combobox component with Teleport positioning -->
<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted, nextTick, watch } from 'vue'
import { Search, ChevronDown, Check, X } from 'lucide-vue-next'

export type SelectOption = {
  label: string
  value: string | number
  description?: string
}

const props = withDefaults(
  defineProps<{
    modelValue: string | number | null
    label?: string
    name: string
    options: SelectOption[]
    placeholder?: string
    searchPlaceholder?: string
    error?: string
    required?: boolean
    disabled?: boolean
    clearable?: boolean
  }>(),
  {
    label: '',
    placeholder: 'Pilih opsi...',
    searchPlaceholder: 'Cari opsi...',
    required: false,
    disabled: false,
    clearable: true,
  }
)

const emit = defineEmits<{
  'update:modelValue': [value: string | number | null]
}>()

const isOpen = ref(false)
const searchQuery = ref('')
const searchInput = ref<HTMLInputElement | null>(null)
const containerRef = ref<HTMLDivElement | null>(null)
const buttonRef = ref<HTMLButtonElement | null>(null)
const dropdownRef = ref<HTMLDivElement | null>(null)
const dropdownStyle = ref<Record<string, string>>({})

const selectedOption = computed(() => {
  return props.options.find((opt) => opt.value === props.modelValue) ?? null
})

const filteredOptions = computed(() => {
  if (!searchQuery.value) return props.options
  const q = searchQuery.value.toLowerCase()
  return props.options.filter(
    (opt) =>
      opt.label.toLowerCase().includes(q) ||
      (opt.description && opt.description.toLowerCase().includes(q))
  )
})

const updatePosition = () => {
  if (!isOpen.value || !buttonRef.value) return
  const rect = buttonRef.value.getBoundingClientRect()
  if (rect.width === 0 && rect.height === 0) return

  const spaceBelow = window.innerHeight - rect.bottom
  const spaceAbove = rect.top
  const dropdownHeight = 240
  const openUpwards = spaceBelow < dropdownHeight && spaceAbove > spaceBelow

  const maxHeight = openUpwards ? Math.min(spaceAbove - 16, dropdownHeight) : Math.min(spaceBelow - 16, dropdownHeight)
  const top = openUpwards ? Math.max(8, rect.top - maxHeight - 4) : rect.bottom + 4

  let left = rect.left
  const width = Math.max(rect.width, 240)
  if (left + width > window.innerWidth - 12) {
    left = Math.max(12, window.innerWidth - width - 12)
  }

  dropdownStyle.value = {
    position: 'fixed',
    top: `${top}px`,
    left: `${left}px`,
    width: `${width}px`,
    maxHeight: `${maxHeight}px`,
    zIndex: '99999',
  }
}

const toggleDropdown = async (e?: Event) => {
  e?.stopPropagation()
  if (props.disabled) return
  isOpen.value = !isOpen.value
  if (isOpen.value) {
    searchQuery.value = ''
    updatePosition()
    await nextTick()
    updatePosition()
    setTimeout(() => {
      searchInput.value?.focus()
    }, 50)
  }
}

const selectOption = (opt: SelectOption, e?: Event) => {
  e?.stopPropagation()
  emit('update:modelValue', opt.value)
  isOpen.value = false
  searchQuery.value = ''
}

const clearSelection = (e: MouseEvent) => {
  e.stopPropagation()
  emit('update:modelValue', null)
  searchQuery.value = ''
}

const handleClickOutside = (e: MouseEvent) => {
  const target = e.target as Node
  if (
    (containerRef.value && containerRef.value.contains(target)) ||
    (dropdownRef.value && dropdownRef.value.contains(target))
  ) {
    return
  }
  isOpen.value = false
}

const handleKeyDown = (e: KeyboardEvent) => {
  if (e.key === 'Escape') {
    isOpen.value = false
  }
}

watch(isOpen, (val) => {
  if (val) {
    window.addEventListener('scroll', updatePosition, true)
    window.addEventListener('resize', updatePosition)
  } else {
    window.removeEventListener('scroll', updatePosition, true)
    window.removeEventListener('resize', updatePosition)
  }
})

onMounted(() => {
  document.addEventListener('click', handleClickOutside)
  document.addEventListener('keydown', handleKeyDown)
})

onUnmounted(() => {
  document.removeEventListener('click', handleClickOutside)
  document.removeEventListener('keydown', handleKeyDown)
  window.removeEventListener('scroll', updatePosition, true)
  window.removeEventListener('resize', updatePosition)
})
</script>

<template>
  <div ref="containerRef" class="space-y-1.5 relative">
    <label v-if="label" class="text-sm font-medium text-ink-900">
      {{ label }}
      <span v-if="required" class="text-signal-danger">*</span>
    </label>

    <div class="relative">
      <button
        ref="buttonRef"
        type="button"
        @click.prevent.stop="toggleDropdown"
        @mousedown.stop
        :disabled="disabled"
        class="w-full flex items-center justify-between rounded-md border border-border bg-surface-0 px-3 py-2 text-sm shadow-xs outline-none transition focus:border-accent focus:ring-2 focus:ring-accent/20 text-left"
        :class="[
          error ? 'border-signal-danger focus:border-signal-danger focus:ring-signal-danger/20' : '',
          disabled ? 'bg-surface-50 cursor-not-allowed text-ink-600' : 'cursor-pointer'
        ]"
      >
        <span v-if="selectedOption" class="font-medium text-ink-900 truncate flex-1 min-w-0 pr-2">
          {{ selectedOption.label }}
        </span>
        <span v-else class="text-ink-600 truncate flex-1 min-w-0 pr-2">
          {{ placeholder }}
        </span>

        <div class="flex items-center gap-1.5 shrink-0 ml-2">
          <button
            v-if="clearable && selectedOption && !disabled"
            type="button"
            @click.prevent.stop="clearSelection"
            @mousedown.stop
            class="text-ink-600 hover:text-ink-900 p-0.5 rounded-full hover:bg-surface-50"
            title="Hapus pilihan"
          >
            <X class="h-3.5 w-3.5" />
          </button>
          <ChevronDown
            class="h-4 w-4 text-ink-600 transition-transform duration-200 shrink-0"
            :class="{ 'rotate-180': isOpen }"
          />
        </div>
      </button>

      <!-- Dropdown Panel (Teleported to body to avoid overflow clipping) -->
      <Teleport to="body">
        <div
          v-if="isOpen"
          ref="dropdownRef"
          :style="dropdownStyle"
          @click.prevent.stop
          @mousedown.stop
          class="overflow-hidden rounded-md border border-border bg-surface-0 shadow-xl ring-1 ring-black/10 flex flex-col"
        >
          <!-- Search Input -->
          <div class="p-2 border-b border-border bg-surface-50 flex items-center gap-2">
            <Search class="h-4 w-4 text-ink-600 shrink-0" />
            <input
              ref="searchInput"
              v-model="searchQuery"
              type="text"
              :placeholder="searchPlaceholder"
              @click.prevent.stop
              @keydown.stop="handleKeyDown"
              class="w-full bg-transparent text-sm outline-none text-ink-900 placeholder:text-ink-600"
            />
            <button
              v-if="searchQuery"
              type="button"
              @click.prevent.stop="searchQuery = ''"
              class="text-ink-600 hover:text-ink-900 p-0.5"
            >
              <X class="h-3.5 w-3.5" />
            </button>
          </div>

          <!-- Options List -->
          <div class="overflow-y-auto max-h-48 py-1">
            <div
              v-if="filteredOptions.length === 0"
              class="px-4 py-3 text-center text-xs text-ink-600"
            >
              Tidak ada opsi yang cocok dengan "{{ searchQuery }}"
            </div>

            <button
              v-for="opt in filteredOptions"
              :key="opt.value"
              type="button"
              @click.prevent.stop="selectOption(opt, $event)"
              @mousedown.stop
              class="w-full flex items-center justify-between px-3 py-2 text-left text-sm transition-colors hover:bg-surface-50"
              :class="opt.value === modelValue ? 'bg-surface-50 font-semibold text-accent' : 'text-ink-900'"
            >
              <div class="flex-1 min-w-0 pr-2">
                <div class="leading-tight truncate">{{ opt.label }}</div>
                <div v-if="opt.description" class="text-xs text-ink-600 mt-0.5 font-normal truncate">
                  {{ opt.description }}
                </div>
              </div>
              <Check v-if="opt.value === modelValue" class="h-4 w-4 text-accent shrink-0 ml-2" />
            </button>
          </div>
        </div>
      </Teleport>
    </div>

    <p v-if="error" class="text-sm text-signal-danger">
      {{ error }}
    </p>
  </div>
</template>
