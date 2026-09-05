<!-- ponytail: Clean, responsive error page for 403, 404, 500, 503 -->
<script setup lang="ts">
import { computed } from 'vue'
import { Head } from '@inertiajs/vue3'
import ApplicationLogo from '@/Components/ApplicationLogo.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import { AlertTriangle, ShieldAlert, FileQuestion, ServerCrash, ArrowLeft } from 'lucide-vue-next'

const props = defineProps<{
  status: number
  message?: string
}>()

const title = computed(() => {
  return {
    403: '403: Akses Dibatasi / Modul Nonaktif',
    404: '404: Halaman Tidak Ditemukan',
    500: '500: Terjadi Kesalahan Sistem',
    503: '503: Layanan Sedang Pemeliharaan',
  }[props.status] || `${props.status}: Terjadi Kesalahan`
})

const description = computed(() => {
  if (props.message) return props.message

  return {
    403: 'Modul ini tidak aktif pada paket akun Anda atau Anda belum memiliki izin akses.',
    404: 'Halaman atau modul yang Anda tuju tidak ditemukan atau telah dipindahkan.',
    500: 'Terjadi kendala pada server kami. Tim teknis sedang menanganinya.',
    503: 'Sistem sedang dalam proses pemeliharaan. Silakan coba beberapa saat lagi.',
  }[props.status] || 'Terjadi kesalahan saat memproses permintaan Anda.'
})

const iconComponent = computed(() => {
  return {
    403: ShieldAlert,
    404: FileQuestion,
    500: ServerCrash,
    503: AlertTriangle,
  }[props.status] || AlertTriangle
})
</script>

<template>
  <Head :title="title" />

  <div class="min-h-screen bg-surface-50 flex flex-col justify-center items-center px-4 sm:px-6 lg:px-8">
    <div class="max-w-md w-full text-center space-y-6 bg-surface-0 border border-border p-8 rounded-xl shadow-sm">
      <div class="flex justify-center">
        <ApplicationLogo class="h-12 w-auto object-contain" />
      </div>

      <div class="mx-auto flex items-center justify-center h-16 w-16 rounded-full bg-accent/10 text-accent">
        <component :is="iconComponent" class="h-8 w-8" />
      </div>

      <div class="space-y-2">
        <h1 class="text-xl font-bold text-ink-900 tracking-tight">
          {{ title }}
        </h1>
        <p class="text-sm text-ink-600 leading-relaxed">
          {{ description }}
        </p>
      </div>

      <div class="pt-4 flex flex-col sm:flex-row gap-3 justify-center">
        <PrimaryButton href="/projects/1" class="w-full justify-center">
          <ArrowLeft class="w-4 h-4 mr-1.5" />
          Kembali ke Projects
        </PrimaryButton>
      </div>
    </div>
  </div>
</template>
