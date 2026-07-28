<!-- ponytail: Complex Async Searchable Dropdown / Combobox with API integration & 50 item limit -->
<script setup lang="ts">
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import axios from 'axios'
import { Search, ChevronDown, Check, X, Loader2, AlertCircle, RefreshCw } from 'lucide-vue-next'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'

export type AsyncSelectOption = {
  value: string | number
  label: string
  description?: string
  avatar?: string
  badge?: string
  [key: string]: any
}

const props = withDefaults(
  defineProps<{
    modelValue: string | number | null
    label?: string
    name: string
    apiUrl?: string
    apiEntity?: string
    fetchFn?: (query: string) => Promise<AsyncSelectOption[]>
    placeholder?: string
    searchPlaceholder?: string
    limit?: number
    debounceMs?: number
    error?: string
    required?: boolean
    disabled?: boolean
    clearable?: boolean
  }>(),
  {
    label: '',
    apiUrl: '/api/search',
    apiEntity: 'user',
    placeholder: 'Cari dan pilih data...',
    searchPlaceholder: 'Ketik untuk mencari (maks 50 hasil)...',
    limit: 50,
    debounceMs: 300,
    required: false,
    disabled: false,
    clearable: true,
  }
)

const emit = defineEmits<{
  'update:modelValue': [value: string | number | null]
  'select': [option: AsyncSelectOption | null]
}>()

const isOpen = ref(false)
const searchQuery = ref('')
const isLoading = ref(false)
const isHydrating = ref(false)
const fetchError = ref<string | null>(null)
const options = ref<AsyncSelectOption[]>([])
const selectedOption = ref<AsyncSelectOption | null>(null)
const totalResults = ref<number>(0)

const containerRef = ref<HTMLDivElement | null>(null)
const searchInput = ref<HTMLInputElement | null>(null)

let debounceTimer: ReturnType<typeof setTimeout> | null = null

// Hydrate selected item label on initial load if modelValue is provided
const hydrateSelectedValue = async () => {
  if (!props.modelValue) {
    selectedOption.value = null
    return
  }

  isHydrating.value = true
  try {
    if (props.fetchFn) {
      const res = await props.fetchFn('')
      const match = res.find((item) => String(item.value) === String(props.modelValue))
      if (match) selectedOption.value = match
    } else {
      const response = await axios.get(props.apiUrl, {
        params: {
          entity: props.apiEntity,
          selected_id: props.modelValue,
        },
      })
      const items: AsyncSelectOption[] = response.data?.items ?? []
      if (items.length > 0) {
        selectedOption.value = items[0]
      }
    }
  } catch (err) {
    console.error('Failed to hydrate selected value:', err)
  } finally {
    isHydrating.value = false
  }
}

// Fetch async search results with limit of 50 items
const performSearch = async (query: string) => {
  isLoading.value = true
  fetchError.value = null

  try {
    if (props.fetchFn) {
      const items = await props.fetchFn(query)
      options.value = items.slice(0, props.limit)
      totalResults.value = items.length
    } else {
      const response = await axios.get(props.apiUrl, {
        params: {
          entity: props.apiEntity,
          q: query,
          limit: Math.min(props.limit, 50),
        },
      })
      options.value = response.data?.items ?? []
      totalResults.value = response.data?.total ?? options.value.length
    }
  } catch (err: any) {
    fetchError.value = err?.response?.data?.message || 'Gagal mengambil data dari server.'
    options.value = []
  } finally {
    isLoading.value = false
  }
}

// Trigger debounced search when searchQuery changes
watch(searchQuery, (newQuery) => {
  if (debounceTimer) clearTimeout(debounceTimer)
  debounceTimer = setTimeout(() => {
    performSearch(newQuery)
  }, props.debounceMs)
})

// Watch modelValue changes externally
watch(() => props.modelValue, (newVal) => {
  if (!newVal) {
    selectedOption.value = null
  } else if (!selectedOption.value || String(selectedOption.value.value) !== String(newVal)) {
    hydrateSelectedValue()
  }
}, { immediate: true })

const toggleDropdown = () => {
  if (props.disabled) return
  isOpen.value = !isOpen.value
  if (isOpen.value) {
    searchQuery.value = ''
    performSearch('')
    setTimeout(() => {
      searchInput.value?.focus()
    }, 50)
  }
}

const selectItem = (opt: AsyncSelectOption) => {
  selectedOption.value = opt
  emit('update:modelValue', opt.value)
  emit('select', opt)
  isOpen.value = false
  searchQuery.value = ''
}

const clearSelection = (e: MouseEvent) => {
  e.stopPropagation()
  selectedOption.value = null
  emit('update:modelValue', null)
  emit('select', null)
  searchQuery.value = ''
}

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
  if (debounceTimer) clearTimeout(debounceTimer)
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
        type="button"
        @click="toggleDropdown"
        :disabled="disabled"
        class="w-full flex items-center justify-between rounded-md border border-border bg-white px-3 py-2 text-sm shadow-sm outline-none transition focus:border-ink-900 focus:ring-2 focus:ring-ink-900/10 text-left"
        :class="[
          error ? 'border-signal-danger focus:border-signal-danger focus:ring-signal-danger/10' : '',
          disabled ? 'bg-surface-50 cursor-not-allowed text-ink-600' : 'cursor-pointer'
        ]"
      >
        <div v-if="selectedOption" class="flex items-center gap-2 min-w-0 pr-2">
          <div
            v-if="selectedOption.avatar"
            class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-gray-900 font-semibold text-[10px] text-white"
          >
            {{ selectedOption.avatar }}
          </div>
          <span class="font-medium text-ink-900 truncate">
            {{ selectedOption.label }}
          </span>
          <span v-if="selectedOption.description" class="text-xs text-ink-600 truncate hidden sm:inline">
            — {{ selectedOption.description }}
          </span>
        </div>

        <span v-else-if="isHydrating" class="text-xs text-ink-600 flex items-center gap-1.5">
          <Loader2 class="h-3.5 w-3.5 animate-spin" /> Memuat detail pilihan...
        </span>

        <span v-else class="text-ink-600">
          {{ placeholder }}
        </span>

        <div class="flex items-center gap-1.5 shrink-0 ml-2">
          <button
            v-if="clearable && selectedOption && !disabled"
            type="button"
            @click="clearSelection"
            class="text-ink-600 hover:text-ink-900 p-0.5 rounded-full hover:bg-surface-50"
            title="Hapus pilihan"
          >
            <X class="h-3.5 w-3.5" />
          </button>
          <ChevronDown
            class="h-4 w-4 text-ink-600 transition-transform duration-200"
            :class="{ 'rotate-180': isOpen }"
          />
        </div>
      </button>

      <!-- Dropdown Panel -->
      <div
        v-if="isOpen"
        class="absolute left-0 right-0 z-30 mt-1 max-h-72 overflow-hidden rounded-md border border-border bg-white shadow-lg ring-1 ring-black/5 flex flex-col"
      >
        <!-- Search Input Header -->
        <div class="p-2 border-b border-border bg-surface-50 flex items-center gap-2">
          <Search class="h-4 w-4 text-ink-600 shrink-0 ml-1" />
          <input
            ref="searchInput"
            v-model="searchQuery"
            type="text"
            :placeholder="searchPlaceholder"
            class="w-full bg-transparent text-sm outline-none text-ink-900 placeholder:text-ink-600"
          />
          <Loader2 v-if="isLoading" class="h-4 w-4 animate-spin text-accent shrink-0" />
          <button
            v-else-if="searchQuery"
            type="button"
            @click="searchQuery = ''"
            class="text-ink-600 hover:text-ink-900 p-0.5"
          >
            <X class="h-3.5 w-3.5" />
          </button>
        </div>

        <!-- Options List -->
        <div class="overflow-y-auto flex-1 py-1 divide-y divide-border/40">
          <!-- Loading skeleton state -->
          <div v-if="isLoading && options.length === 0" class="p-4 space-y-2 text-center text-xs text-ink-600">
            <div class="flex items-center justify-center gap-2">
              <Loader2 class="h-4 w-4 animate-spin text-accent" />
              <span>Memuat hasil pencarian (maks 50 data)...</span>
            </div>
          </div>

          <!-- Error state -->
          <div v-else-if="fetchError" class="p-4 text-center text-xs text-signal-danger space-y-2">
            <div class="flex items-center justify-center gap-1.5 font-medium">
              <AlertCircle class="h-4 w-4" />
              <span>{{ fetchError }}</span>
            </div>
            <button
              type="button"
              @click="performSearch(searchQuery)"
              class="inline-flex items-center gap-1 text-[11px] text-accent underline hover:text-accent/80"
            >
              <RefreshCw class="h-3 w-3" /> Coba Lagi
            </button>
          </div>

          <!-- Empty state -->
          <div
            v-else-if="options.length === 0"
            class="px-4 py-6 text-center text-xs text-ink-600"
          >
            Data tidak ditemukan untuk "{{ searchQuery || 'pencarian' }}"
          </div>

          <!-- Option items -->
          <button
            v-for="opt in options"
            :key="opt.value"
            type="button"
            @click="selectItem(opt)"
            class="w-full flex items-center justify-between px-3 py-2.5 text-left text-sm transition-colors hover:bg-surface-50"
            :class="String(opt.value) === String(modelValue) ? 'bg-surface-50 font-semibold text-accent' : 'text-ink-900'"
          >
            <div class="flex items-center gap-3 min-w-0 pr-2">
              <div
                v-if="opt.avatar"
                class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-gray-900 font-bold text-xs text-white shadow-sm"
              >
                {{ opt.avatar }}
              </div>
              <div class="min-w-0">
                <div class="leading-snug truncate">{{ opt.label }}</div>
                <div v-if="opt.description" class="text-xs text-ink-600 mt-0.5 truncate font-normal">
                  {{ opt.description }}
                </div>
              </div>
            </div>

            <div class="flex items-center gap-2 shrink-0 ml-2">
              <StatusBadge v-if="opt.badge" :status="opt.badge === 'active' || opt.badge === 'open' ? 'active' : 'pending'" :label="opt.badge" />
              <Check v-if="String(opt.value) === String(modelValue)" class="h-4 w-4 text-accent shrink-0" />
            </div>
          </button>
        </div>

        <!-- Footer Notice Limit 50 -->
        <div class="border-t border-border bg-surface-50 px-3 py-1.5 text-[11px] text-ink-600 flex items-center justify-between">
          <span>Dibatasi maks 50 hasil per pencarian</span>
          <span v-if="totalResults > 0" class="font-mono text-[10px]">{{ options.length }} dari {{ totalResults }}</span>
        </div>
      </div>
    </div>

    <p v-if="error" class="text-sm text-signal-danger">
      {{ error }}
    </p>
  </div>
</template>
