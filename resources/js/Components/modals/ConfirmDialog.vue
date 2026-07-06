<!-- ponytail: Simple global ConfirmDialog displaying active confirmation state ref -->
<script setup lang="ts">
import { confirmState, useConfirm } from '@/Composables/useConfirmDialog'

const { close } = useConfirm()

const handleConfirm = () => {
  if (confirmState.value) {
    confirmState.value.onConfirm()
  }
}
</script>

<template>
  <div 
    v-if="confirmState?.open" 
    class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity z-50 flex items-center justify-center p-4"
  >
    <div class="relative transform overflow-hidden rounded-lg bg-white text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg">
      <div class="bg-white px-4 pb-4 pt-5 sm:p-6 sm:pb-4">
        <div class="sm:flex sm:items-start">
          <div class="mt-3 text-center sm:ml-4 sm:mt-0 sm:text-left">
            <h3 class="text-base font-semibold leading-6 text-gray-900" id="modal-title">
              {{ confirmState.title }}
            </h3>
            <div class="mt-2">
              <p class="text-sm text-gray-500">
                {{ confirmState.description }}
              </p>
            </div>
          </div>
        </div>
      </div>
      <div class="bg-gray-50 px-4 py-3 sm:flex sm:flex-row-reverse sm:px-6 gap-2">
        <button 
          type="button" 
          @click="handleConfirm"
          :class="[
            confirmState.variant === 'destructive' 
              ? 'bg-red-600 hover:bg-red-500' 
              : 'bg-gray-900 hover:bg-gray-800'
          ]"
          class="inline-flex w-full justify-center rounded-md px-3 py-2 text-sm font-semibold text-white shadow-sm sm:ml-3 sm:w-auto"
        >
          {{ confirmState.confirmText }}
        </button>
        <button 
          type="button" 
          @click="close"
          class="mt-3 inline-flex w-full justify-center rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 sm:mt-0 sm:w-auto"
        >
          {{ confirmState.cancelText }}
        </button>
      </div>
    </div>
  </div>
</template>
