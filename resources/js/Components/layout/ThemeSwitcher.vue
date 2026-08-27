<script setup lang="ts">
import { ref, computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import { Palette, Check, Settings, ChevronRight, Sun, Moon } from 'lucide-vue-next'
import { useTheme } from '@/Composables/useTheme'

const { activeTheme, availableThemes, isDark, setTheme, toggleLightDark } = useTheme()
const isOpen = ref(false)

const lightThemes = computed(() => availableThemes.value.filter((t) => t.mode === 'light'))
const darkThemes = computed(() => availableThemes.value.filter((t) => t.mode === 'dark'))

const handleSelect = (themeId: string) => {
  setTheme(themeId, true)
  isOpen.value = false
}

const handleToggle = () => {
  toggleLightDark(true)
}
</script>

<template>
  <div class="relative flex items-center gap-1.5">
    <!-- Quick Light / Dark Mode Toggle Button -->
    <button
      type="button"
      @click="handleToggle"
      class="flex h-9 w-9 items-center justify-center rounded-lg border border-border bg-surface-0 text-ink-600 shadow-xs transition-colors hover:bg-surface-50 hover:text-ink-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-accent"
      :title="isDark ? 'Beralih ke Mode Terang (Light)' : 'Beralih ke Mode Gelap (Dark)'"
    >
      <Sun v-if="isDark" class="h-4 w-4 text-amber-400 transition-transform hover:rotate-45" />
      <Moon v-else class="h-4 w-4 text-ink-600 transition-transform hover:-rotate-12" />
      <span class="sr-only">Toggle Light / Dark Mode</span>
    </button>

    <!-- Palette Dropdown for specific theme selection -->
    <div class="relative">
      <button
        type="button"
        @click="isOpen = !isOpen"
        class="flex h-9 w-9 items-center justify-center rounded-lg border border-border bg-surface-0 text-ink-600 shadow-xs transition-colors hover:bg-surface-50 hover:text-ink-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-accent"
        title="Pilih Tema Warna Tenant"
        :aria-expanded="isOpen"
      >
        <Palette class="h-4 w-4" />
        <span class="sr-only">Pilih Tema</span>
      </button>

      <!-- Backdrop Click-catcher -->
      <div
        v-if="isOpen"
        @click="isOpen = false"
        class="fixed inset-0 z-10"
      />

      <!-- Dropdown Menu -->
      <div
        v-if="isOpen"
        class="absolute right-0 z-20 mt-2 w-80 rounded-lg border border-border bg-surface-0 p-2 shadow-xl ring-1 ring-black/5"
      >
        <div class="border-b border-border px-3 py-2 flex items-center justify-between">
          <div>
            <p class="text-xs font-semibold uppercase tracking-wider text-ink-600">Tema Tenant</p>
            <p class="mt-0.5 text-xs text-ink-600">Pilih tema visual & mode tampilan:</p>
          </div>
          <span
            class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-semibold border border-border bg-surface-50 text-ink-900"
          >
            <Sun v-if="!isDark" class="h-3 w-3 text-amber-500" />
            <Moon v-else class="h-3 w-3 text-sky-400" />
            <span>{{ isDark ? 'Dark Mode' : 'Light Mode' }}</span>
          </span>
        </div>

        <div class="py-1 max-h-80 overflow-y-auto space-y-3">
          <!-- Light Themes Group -->
          <div>
            <p class="px-3 py-1 text-[10px] font-bold uppercase tracking-wider text-ink-600 flex items-center gap-1">
              <Sun class="h-3 w-3 text-amber-500" />
              <span>Tema Terang (Light)</span>
            </p>
            <div class="space-y-1">
              <button
                v-for="theme in lightThemes"
                :key="theme.id"
                type="button"
                @click="handleSelect(theme.id)"
                class="flex w-full items-center justify-between rounded-md px-3 py-1.5 text-left text-xs transition-colors"
                :class="activeTheme === theme.id ? 'bg-surface-50 font-semibold text-ink-900 ring-1 ring-border' : 'text-ink-600 hover:bg-surface-50 hover:text-ink-900'"
              >
                <div class="flex items-center gap-2.5">
                  <div class="flex items-center -space-x-1">
                    <span
                      class="inline-block h-3.5 w-3.5 rounded-full ring-1 ring-white shadow-xs"
                      :style="{ backgroundColor: theme.primary_color }"
                    />
                    <span
                      class="inline-block h-3.5 w-3.5 rounded-full ring-1 ring-white shadow-xs"
                      :style="{ backgroundColor: theme.preview_colors[2] ?? '#f4f6f8' }"
                    />
                  </div>
                  <div>
                    <p class="font-medium text-ink-900 leading-tight">{{ theme.name }}</p>
                    <p class="text-[10px] text-ink-600">{{ theme.badge ?? theme.caption }}</p>
                  </div>
                </div>

                <Check v-if="activeTheme === theme.id" class="h-4 w-4 text-accent" />
              </button>
            </div>
          </div>

          <!-- Dark Themes Group -->
          <div class="border-t border-border pt-2">
            <p class="px-3 py-1 text-[10px] font-bold uppercase tracking-wider text-ink-600 flex items-center gap-1">
              <Moon class="h-3 w-3 text-sky-400" />
              <span>Tema Gelap (Dark)</span>
            </p>
            <div class="space-y-1">
              <button
                v-for="theme in darkThemes"
                :key="theme.id"
                type="button"
                @click="handleSelect(theme.id)"
                class="flex w-full items-center justify-between rounded-md px-3 py-1.5 text-left text-xs transition-colors"
                :class="activeTheme === theme.id ? 'bg-surface-50 font-semibold text-ink-900 ring-1 ring-border' : 'text-ink-600 hover:bg-surface-50 hover:text-ink-900'"
              >
                <div class="flex items-center gap-2.5">
                  <div class="flex items-center -space-x-1">
                    <span
                      class="inline-block h-3.5 w-3.5 rounded-full ring-1 ring-white shadow-xs"
                      :style="{ backgroundColor: theme.primary_color }"
                    />
                    <span
                      class="inline-block h-3.5 w-3.5 rounded-full ring-1 ring-white shadow-xs"
                      :style="{ backgroundColor: theme.preview_colors[2] ?? '#0f172a' }"
                    />
                  </div>
                  <div>
                    <p class="font-medium text-ink-900 leading-tight">{{ theme.name }}</p>
                    <p class="text-[10px] text-ink-600">{{ theme.badge ?? theme.caption }}</p>
                  </div>
                </div>

                <Check v-if="activeTheme === theme.id" class="h-4 w-4 text-accent" />
              </button>
            </div>
          </div>
        </div>

        <div class="border-t border-border pt-1 mt-1">
          <Link
            :href="route('config.theme.index')"
            @click="isOpen = false"
            class="flex w-full items-center justify-between rounded-md px-3 py-1.5 text-left text-xs text-ink-600 hover:bg-surface-50 hover:text-ink-900 transition-colors"
          >
            <span class="flex items-center gap-2">
              <Settings class="h-3.5 w-3.5" />
              <span>Pengaturan Tema Lengkap</span>
            </span>
            <ChevronRight class="h-3.5 w-3.5 text-ink-600" />
          </Link>
        </div>
      </div>
    </div>
  </div>
</template>
