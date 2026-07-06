<!-- ponytail: Minimal user dropdown wrapper using native HTML/Inertia link for simpler code -->
<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3'
import { ref } from 'vue'
import { LogOut, User as UserIcon } from 'lucide-vue-next'

const page = usePage()
const user = page.props.auth.user

const isOpen = ref(false)
</script>

<template>
  <div class="relative">
    <button 
      @click="isOpen = !isOpen"
      class="flex items-center gap-2 text-sm font-medium text-gray-700 hover:text-gray-900 focus:outline-none"
    >
      <div class="h-8 w-8 rounded-full bg-gray-100 flex items-center justify-center border border-gray-200">
        <UserIcon class="h-4 w-4 text-gray-500" />
      </div>
      <span class="hidden sm:inline">{{ user.name }}</span>
    </button>

    <div 
      v-if="isOpen" 
      @click="isOpen = false"
      class="fixed inset-0 z-10"
    ></div>

    <div 
      v-if="isOpen"
      class="absolute right-0 mt-2 w-48 rounded-md border border-gray-100 bg-white py-1 shadow-lg ring-1 ring-black ring-opacity-5 z-20"
    >
      <div class="px-4 py-2 border-b border-gray-50">
        <p class="text-xs text-gray-400">Signed in as</p>
        <p class="text-sm font-medium text-gray-700 truncate">{{ user.email }}</p>
      </div>

      <Link 
        :href="route('logout')" 
        method="post" 
        as="button" 
        class="w-full flex items-center gap-2 px-4 py-2 text-left text-sm text-red-600 hover:bg-gray-50"
      >
        <LogOut class="h-4 w-4" />
        Log Out
      </Link>
    </div>
  </div>
</template>
