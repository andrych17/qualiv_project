<!-- ponytail: Simple floating toast notification rendering activeToast global state ref -->
<script setup lang="ts">
import { activeToast } from '@/Composables/useFlashToast'
import { X, CheckCircle, AlertTriangle } from 'lucide-vue-next'
</script>

<template>
  <Transition
    enter-active-class="transform ease-out duration-300 transition"
    enter-from-class="translate-y-2 opacity-0 sm:translate-y-0 sm:translate-x-2"
    enter-to-class="translate-y-0 opacity-100 sm:translate-x-0"
    leave-active-class="transition ease-in duration-100"
    leave-from-class="opacity-100"
    leave-to-class="opacity-0"
  >
    <div 
      v-if="activeToast"
      class="fixed bottom-5 right-5 z-50 flex w-full max-w-sm rounded-lg bg-white shadow-lg border border-gray-150 p-4"
    >
      <div class="flex items-start w-full">
        <div class="flex-shrink-0">
          <CheckCircle v-if="activeToast.type === 'success'" class="h-6 w-6 text-green-500" />
          <AlertTriangle v-else class="h-6 w-6 text-red-500" />
        </div>
        <div class="ml-3 w-0 flex-1 pt-0.5">
          <p class="text-sm font-medium text-gray-900">
            {{ activeToast.type === 'success' ? 'Success' : 'Error' }}
          </p>
          <p class="mt-1 text-sm text-gray-500">
            {{ activeToast.message }}
          </p>
        </div>
        <div class="ml-4 flex flex-shrink-0">
          <button 
            type="button" 
            @click="activeToast = null"
            class="inline-flex rounded-md bg-white text-gray-400 hover:text-gray-500 focus:outline-none"
          >
            <X class="h-5 w-5" />
          </button>
        </div>
      </div>
    </div>
  </Transition>
</template>
