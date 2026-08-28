<script setup lang="ts">
import { ref, computed } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
import { Palette, Check, Settings, ChevronRight } from 'lucide-vue-next'
import { useTheme } from '@/Composables/useTheme'

const page = usePage()
const { activeTheme, availableThemes, setTheme } = useTheme()
const isOpen = ref(false)

const canManageTheme = computed(() => {
  return Boolean(page.props.canManageTheme ?? false)
})

const handleSelect = (themeId: string) => {
  setTheme(themeId, true)
  isOpen.value = false
}
</script>

<template>
  <!-- Only render for administrators/users with theme management permission -->
  <div v-if="canManageTheme" class="relative">
    <!-- Single Palette Button -->
    <button
      type="button"
      @click="isOpen = !isOpen"
      class="flex h-9 w-9 items-center justify-center rounded-lg border border-border bg-surface-0 text-ink-600 shadow-xs transition-colors hover:bg-surface-50 hover:text-ink-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-accent"
      title="Palet Warna & Tema Tenant (Admin)"
      :aria-expanded="isOpen"
    >
      <Palette class="h-4 w-4" />
      <span class="sr-only">Palet Warna Tenant</span>
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
      class="absolute right-0 z-20 mt-2 w-80 max-w-[calc(100vw-1.5rem)] rounded-lg border border-border bg-surface-0 p-2 shadow-xl ring-1 ring-black/5"
    >
      <div class="border-b border-border px-3 py-2">
        <p class="text-xs font-semibold uppercase tracking-wider text-ink-600">Palet Warna Tenant</p>
        <p class="mt-0.5 text-xs text-ink-600">Pilih skema warna visual perusahaan:</p>
      </div>

      <div class="py-1 max-h-80 overflow-y-auto space-y-1">
        <button
          v-for="theme in availableThemes"
          :key="theme.id"
          type="button"
          @click="handleSelect(theme.id)"
          class="flex w-full items-center justify-between rounded-md px-3 py-2 text-left text-xs transition-colors"
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

      <div class="border-t border-border pt-1 mt-1">
        <Link
          :href="route('config.theme.index')"
          @click="isOpen = false"
          class="flex w-full items-center justify-between rounded-md px-3 py-2 text-left text-xs text-ink-600 hover:bg-surface-50 hover:text-ink-900 transition-colors"
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
</template>
