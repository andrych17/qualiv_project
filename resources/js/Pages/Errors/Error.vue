<script setup lang="ts">
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import {
  ShieldAlert,
  FileQuestion,
  ServerCrash,
  Hammer,
  AlertTriangle,
  ArrowLeft
} from 'lucide-vue-next';

const props = defineProps<{
  status: number;
}>();

const title = computed(() => {
  return (
    {
      403: 'Akses Ditolak',
      404: 'Halaman Tidak Ditemukan',
      500: 'Terjadi Kesalahan Server',
      503: 'Layanan Sedang Pemeliharaan',
    }[props.status] || 'Terjadi Kesalahan'
  );
});

const description = computed(() => {
  return (
    {
      403: 'Maaf, Anda tidak memiliki izin untuk mengakses halaman atau modul ini.',
      404: 'Halaman yang Anda tuju tidak ditemukan atau URL mungkin telah berubah.',
      500: 'Terjadi kendala pada sistem kami. Tim teknis sedang menangani masalah ini.',
      503: 'Sistem sedang dalam proses pemeliharaan atau peningkatan performa. Silakan coba beberapa saat lagi.',
    }[props.status] || 'Terjadi kesalahan yang tidak terduga pada aplikasi.'
  );
});

const iconComponent = computed(() => {
  return (
    {
      403: ShieldAlert,
      404: FileQuestion,
      500: ServerCrash,
      503: Hammer,
    }[props.status] || AlertTriangle
  );
});
</script>

<template>
  <Head :title="title" />

  <div class="min-h-screen bg-surface-50 flex items-center justify-center p-6 select-none">
    <div class="max-w-md w-full text-center space-y-6 bg-surface-0 border border-border p-8 rounded-2xl shadow-sm">
      <div class="w-16 h-16 bg-accent/10 text-accent rounded-full flex items-center justify-center mx-auto mb-4">
        <component :is="iconComponent" class="w-8 h-8" />
      </div>

      <div class="space-y-2">
        <h1 class="text-4xl font-bold tracking-tight text-ink-900">{{ status }}</h1>
        <h2 class="text-lg font-semibold text-ink-800">{{ title }}</h2>
        <p class="text-sm text-ink-500 leading-relaxed">{{ description }}</p>
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
