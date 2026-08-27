<script setup lang="ts">
import { ref, computed } from 'vue'
import { Head } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import { Palette, Check, Sparkles, Layers, ShieldCheck, Sun, Moon, ArrowRightLeft } from 'lucide-vue-next'
import { useTheme, type ThemeDef } from '@/Composables/useTheme'

const props = defineProps<{
  themes: ThemeDef[]
  currentTheme: string
  defaultTheme: string
}>()

const { activeTheme, isDark, setTheme, toggleLightDark } = useTheme()

const modeFilter = ref<'all' | 'light' | 'dark'>('all')

const filteredThemes = computed(() => {
  if (modeFilter.value === 'all') return props.themes
  return props.themes.filter((t) => t.mode === modeFilter.value)
})

const currentThemeObj = computed(() => {
  return props.themes.find((t) => t.id === activeTheme.value) ?? props.themes[0]
})

const handleApplyTheme = (themeId: string) => {
  setTheme(themeId, true)
}

const handleToggleMode = () => {
  toggleLightDark(true)
}
</script>

<template>
  <Head title="Pengaturan Tema Tenant" />

  <AppLayout>
    <div class="space-y-6 pb-16">
      <!-- Page Header -->
      <PageHeader
        title="Pengaturan Tema & Mode Tampilan"
        subtitle="Kelola tema warna dan mode tampilan (Light / Dark Mode) untuk tenant ini. Seluruh tombol, formulir, tabel, kartu, dialog, dan navigasi akan otomatis menyesuaikan secara konsisten."
      >
        <template #actions>
          <div class="flex items-center gap-3">
            <!-- Fast Mode Switcher Button -->
            <button
              type="button"
              @click="handleToggleMode"
              class="inline-flex items-center gap-2 rounded-md border border-border bg-surface-0 px-3.5 py-2 text-xs font-semibold text-ink-900 shadow-xs transition hover:bg-surface-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-accent"
            >
              <Sun v-if="isDark" class="h-4 w-4 text-amber-400" />
              <Moon v-else class="h-4 w-4 text-sky-500" />
              <span>Beralih ke {{ isDark ? 'Mode Terang (Light)' : 'Mode Gelap (Dark)' }}</span>
            </button>

            <!-- Active Theme Pill -->
            <span class="inline-flex items-center gap-1.5 rounded-full border border-border bg-surface-0 px-3.5 py-1.5 text-xs font-semibold text-ink-900 shadow-xs">
              <span
                class="h-2.5 w-2.5 rounded-full ring-2 ring-white shadow-xs"
                :style="{ backgroundColor: currentThemeObj.primary_color }"
              />
              <span>Aktif: {{ currentThemeObj.name }} ({{ currentThemeObj.mode === 'dark' ? 'Dark' : 'Light' }})</span>
            </span>
          </div>
        </template>
      </PageHeader>

      <!-- Filter Mode Tabs -->
      <div class="flex items-center justify-between border-b border-border pb-3">
        <div class="flex items-center gap-2">
          <button
            type="button"
            @click="modeFilter = 'all'"
            class="rounded-md px-3 py-1.5 text-xs font-semibold transition-colors"
            :class="modeFilter === 'all' ? 'bg-accent text-white shadow-xs' : 'bg-surface-0 text-ink-600 border border-border hover:bg-surface-50 hover:text-ink-900'"
          >
            Semua Tema ({{ themes.length }})
          </button>
          <button
            type="button"
            @click="modeFilter = 'light'"
            class="inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-semibold transition-colors"
            :class="modeFilter === 'light' ? 'bg-accent text-white shadow-xs' : 'bg-surface-0 text-ink-600 border border-border hover:bg-surface-50 hover:text-ink-900'"
          >
            <Sun class="h-3.5 w-3.5" />
            <span>Mode Terang / Light ({{ themes.filter(t => t.mode === 'light').length }})</span>
          </button>
          <button
            type="button"
            @click="modeFilter = 'dark'"
            class="inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-semibold transition-colors"
            :class="modeFilter === 'dark' ? 'bg-accent text-white shadow-xs' : 'bg-surface-0 text-ink-600 border border-border hover:bg-surface-50 hover:text-ink-900'"
          >
            <Moon class="h-3.5 w-3.5" />
            <span>Mode Gelap / Dark ({{ themes.filter(t => t.mode === 'dark').length }})</span>
          </button>
        </div>
      </div>

      <!-- Theme Cards Grid -->
      <div class="grid grid-cols-1 gap-6 md:grid-cols-2 lg:grid-cols-3">
        <div
          v-for="theme in filteredThemes"
          :key="theme.id"
          class="relative flex flex-col justify-between overflow-hidden rounded-lg border bg-surface-0 p-5 shadow-xs transition-all hover:shadow-md"
          :class="activeTheme === theme.id ? 'border-accent ring-2 ring-accent/30' : 'border-border'"
        >
          <!-- Card Header & Badges -->
          <div>
            <div class="flex items-start justify-between">
              <div class="flex items-center gap-2.5">
                <!-- Swatch Palette Preview Circles -->
                <div class="flex items-center -space-x-1.5">
                  <span
                    v-for="(c, idx) in theme.preview_colors"
                    :key="idx"
                    class="inline-block h-5 w-5 rounded-full ring-2 ring-white shadow-xs"
                    :style="{ backgroundColor: c }"
                  />
                </div>
                <div>
                  <h3 class="font-semibold text-ink-900 flex items-center gap-1.5">
                    <span>{{ theme.name }}</span>
                    <span
                      class="rounded px-1.5 py-0.2 text-[9px] font-bold uppercase tracking-wider"
                      :class="theme.mode === 'dark' ? 'bg-slate-800 text-sky-300 border border-slate-700' : 'bg-amber-100 text-amber-900 border border-amber-200'"
                    >
                      {{ theme.mode === 'dark' ? 'Dark' : 'Light' }}
                    </span>
                  </h3>
                  <p class="text-xs text-ink-600">{{ theme.caption }}</p>
                </div>
              </div>

              <span
                v-if="activeTheme === theme.id"
                class="inline-flex items-center gap-1 rounded-full bg-accent px-2.5 py-0.5 text-xs font-semibold text-white shadow-xs"
              >
                <Check class="h-3.5 w-3.5" />
                <span>Aktif</span>
              </span>
              <span
                v-else-if="theme.badge"
                class="inline-flex items-center rounded-full bg-surface-50 px-2 py-0.5 text-[11px] font-medium text-ink-600 border border-border"
              >
                {{ theme.badge }}
              </span>
            </div>

            <!-- Description -->
            <p class="mt-3 text-xs leading-relaxed text-ink-600">
              {{ theme.description }}
            </p>

            <!-- Mini Component Visual Preview Box -->
            <div class="mt-4 rounded-md border border-border bg-surface-50 p-3 space-y-2.5">
              <p class="text-[10px] font-semibold uppercase tracking-wider text-ink-600">Pratinjau Elemen UI</p>
              <div class="flex items-center gap-2">
                <button
                  type="button"
                  class="inline-flex items-center justify-center rounded-sm px-2.5 py-1 text-xs font-semibold text-white shadow-xs"
                  :style="{ backgroundColor: theme.primary_color }"
                >
                  Primary CTA
                </button>
                <span class="inline-flex items-center rounded-full border border-border bg-surface-0 px-2 py-0.5 text-[10px] font-medium text-ink-900">
                  Secondary
                </span>
                <span
                  class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold text-white shadow-2xs"
                  :style="{ backgroundColor: theme.primary_color }"
                >
                  Status
                </span>
              </div>
            </div>
          </div>

          <!-- Bottom Action Button -->
          <div class="mt-5 pt-3 border-t border-border">
            <PrimaryButton
              v-if="activeTheme !== theme.id"
              class="w-full justify-center"
              @click="handleApplyTheme(theme.id)"
            >
              Terapkan Tema Ini
            </PrimaryButton>
            <div
              v-else
              class="flex items-center justify-center gap-1.5 rounded-sm bg-surface-50 py-2 text-xs font-semibold text-ink-900 border border-border"
            >
              <Check class="h-4 w-4 text-accent" />
              <span>Sedang Digunakan Sebagai Default Tenant</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Live Component Sandbox Panel under Current Theme -->
      <Panel
        title="Pratinjau Komponen Langsung (Live Sandbox)"
        subtitle="Berikut adalah simulasi tampilan komponen nyata sistem ERP yang langsung menyesuaikan dengan tema & mode aktif saat ini."
      >
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
          <!-- Button Samples -->
          <div class="space-y-3 rounded-md border border-border p-4 bg-surface-0">
            <h4 class="text-xs font-semibold uppercase tracking-wider text-ink-600">Tombol & Aksi (Buttons)</h4>
            <div class="flex flex-wrap gap-2">
              <PrimaryButton>Primary Button</PrimaryButton>
              <SecondaryButton>Secondary</SecondaryButton>
            </div>
          </div>

          <!-- Badges & Statuses -->
          <div class="space-y-3 rounded-md border border-border p-4 bg-surface-0">
            <h4 class="text-xs font-semibold uppercase tracking-wider text-ink-600">Lencana Status (Status Badges)</h4>
            <div class="flex flex-wrap gap-2">
              <StatusBadge status="active" label="Active" />
              <StatusBadge status="pending" label="Pending" />
              <StatusBadge status="danger" label="Urgent" />
            </div>
          </div>

          <!-- Form Input Sample -->
          <div class="space-y-3 rounded-md border border-border p-4 bg-surface-0">
            <h4 class="text-xs font-semibold uppercase tracking-wider text-ink-600">Form Input & Kontrol</h4>
            <FormInput
              name="sample_input"
              label="Contoh Input Field"
              model-value="PT NusaEvo Digital Solusi"
              placeholder="Ketik teks di sini..."
            />
          </div>
        </div>
      </Panel>
    </div>
  </AppLayout>
</template>
