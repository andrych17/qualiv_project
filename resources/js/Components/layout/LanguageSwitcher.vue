<script setup lang="ts">
import { ref } from 'vue'
import { Globe, Check } from 'lucide-vue-next'
import { useI18n } from '@/Composables/useI18n'

const { currentLocale, availableLocales, setLocale } = useI18n()
const isOpen = ref(false)

const handleSelect = (code: 'id' | 'en') => {
  setLocale(code)
  isOpen.value = false
}
</script>

<template>
  <div class="relative z-50">
    <!-- Trigger Button -->
    <button
      type="button"
      @click="isOpen = !isOpen"
      class="flex h-9 items-center gap-1.5 rounded-lg border border-border bg-surface-0 px-2.5 text-xs font-medium text-ink-700 shadow-xs transition-colors hover:bg-surface-50 hover:text-ink-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-accent cursor-pointer"
      title="Pilih Bahasa / Select Language"
      :aria-expanded="isOpen"
    >
      <Globe class="h-4 w-4 text-ink-500" />
      <span class="uppercase font-semibold tracking-wider text-[11px]">{{ currentLocale }}</span>
    </button>

    <!-- Backdrop Click-catcher -->
    <div
      v-if="isOpen"
      @click="isOpen = false"
      class="fixed inset-0 z-50"
    />

    <!-- Dropdown Menu -->
    <div
      v-if="isOpen"
      class="absolute right-0 z-50 mt-2 w-52 max-w-[calc(100vw-1.5rem)] rounded-lg border border-border bg-surface-0 p-1.5 shadow-2xl ring-1 ring-black/10"
    >
      <div class="border-b border-border px-2.5 py-2">
        <p class="text-[11px] font-semibold uppercase tracking-wider text-ink-600">Pilih Bahasa / Language</p>
      </div>

      <div class="py-1 space-y-1">
        <button
          v-for="loc in availableLocales"
          :key="loc.code"
          type="button"
          @click="handleSelect(loc.code)"
          class="flex w-full items-center justify-between rounded-md px-2.5 py-2 text-left text-xs transition-colors cursor-pointer"
          :class="currentLocale === loc.code ? 'bg-surface-50 font-semibold text-ink-900 ring-1 ring-border' : 'text-ink-600 hover:bg-surface-50 hover:text-ink-900'"
        >
          <div class="flex items-center gap-2">
            <span class="text-base leading-none">{{ loc.flag }}</span>
            <div>
              <p class="font-medium text-ink-900 leading-tight">{{ loc.name }}</p>
              <p class="text-[10px] text-ink-500 uppercase font-mono">{{ loc.code }}</p>
            </div>
          </div>

          <Check v-if="currentLocale === loc.code" class="h-4 w-4 text-accent" />
        </button>
      </div>
    </div>
  </div>
</template>
