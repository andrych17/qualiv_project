<!-- ponytail: Minimal user dropdown wrapper using native HTML/Inertia link for simpler code -->
<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3'
import { ref } from 'vue'
import { LogOut, User as UserIcon, ChevronDown } from 'lucide-vue-next'

const page = usePage()
const user = page.props.auth.user

const isOpen = ref(false)
</script>

<template>
  <div class="relative">
    <button 
      @click="isOpen = !isOpen"
      class="flex items-center gap-2 rounded-lg p-1 sm:px-2 sm:py-1.5 text-sm font-medium text-ink-900 transition-colors hover:bg-surface-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-accent cursor-pointer"
      :aria-expanded="isOpen"
    >
      <div class="flex h-8 w-8 items-center justify-center rounded-full border border-border bg-gray-900 font-semibold text-xs text-white shadow-sm shrink-0">
        {{ user?.name ? user.name.charAt(0).toUpperCase() : 'U' }}
      </div>
      <div class="hidden text-left md:block">
        <p class="text-xs font-semibold leading-none text-ink-900">{{ user?.name }}</p>
        <p class="mt-0.5 text-[11px] leading-none text-ink-600 truncate max-w-[120px]">{{ user?.email }}</p>
      </div>
      <ChevronDown class="h-4 w-4 text-ink-600 transition-transform hidden sm:block" :class="{ 'rotate-180': isOpen }" />
    </button>

    <div 
      v-if="isOpen" 
      @click="isOpen = false"
      class="fixed inset-0 z-10"
    ></div>

    <div 
      v-if="isOpen"
      class="absolute right-0 z-20 mt-2 w-56 max-w-[calc(100vw-1.5rem)] rounded-md border border-border bg-surface-0 py-1 shadow-lg ring-1 ring-black/5"
    >
      <div class="border-b border-border px-4 py-3">
        <p class="text-[11px] font-semibold uppercase tracking-wider text-ink-600">Terhubung sebagai</p>
        <p class="mt-0.5 text-sm font-semibold text-ink-900 truncate">{{ user?.name }}</p>
        <p class="text-xs text-ink-600 truncate">{{ user?.email }}</p>
      </div>

      <div class="py-1">
        <Link 
          :href="route('profile.edit')" 
          @click="isOpen = false"
          class="flex w-full items-center gap-2.5 px-4 py-2 text-left text-sm text-ink-900 transition-colors hover:bg-surface-50"
        >
          <UserIcon class="h-4 w-4 text-ink-600" />
          <span>Pengaturan Profil</span>
        </Link>
      </div>

      <div class="border-t border-border py-1">
        <Link 
          :href="route('logout')" 
          method="post" 
          as="button" 
          class="flex w-full items-center gap-2.5 px-4 py-2 text-left text-sm text-signal-danger transition-colors hover:bg-signal-danger/10 cursor-pointer"
        >
          <LogOut class="h-4 w-4" />
          <span>Keluar (Log Out)</span>
        </Link>
      </div>
    </div>
  </div>
</template>
