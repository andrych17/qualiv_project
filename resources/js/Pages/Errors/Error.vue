<script setup lang="ts">
import { computed } from 'vue'
import { Head } from '@inertiajs/vue3'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import { useI18n } from '@/Composables/useI18n'
import {
  ShieldAlert,
  FileQuestion,
  ServerCrash,
  Hammer,
  AlertTriangle,
  ArrowLeft
} from 'lucide-vue-next'

const { t } = useI18n()

const props = defineProps<{
  status: number
}>()

const title = computed(() => {
  return (
    {
      403: t('error.403_title'),
      404: t('error.404_title'),
      500: t('error.500_title'),
      503: t('error.503_title'),
    }[props.status] || t('error.generic_title')
  )
})

const description = computed(() => {
  return (
    {
      403: t('error.403_desc'),
      404: t('error.404_desc'),
      500: t('error.500_desc'),
      503: t('error.503_desc'),
    }[props.status] || t('error.generic_desc')
  )
})

const iconComponent = computed(() => {
  return (
    {
      403: ShieldAlert,
      404: FileQuestion,
      500: ServerCrash,
      503: Hammer,
    }[props.status] || AlertTriangle
  )
})
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
        <PrimaryButton href="/dashboard" class="w-full justify-center">
          <ArrowLeft class="w-4 h-4 mr-1.5" />
          {{ t('error.back_to_dashboard') }}
        </PrimaryButton>
      </div>
    </div>
  </div>
</template>
