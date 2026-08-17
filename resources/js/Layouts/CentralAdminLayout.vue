<!-- ponytail: Minimal top-nav shell for the platform-admin (central_admin guard) surface —
     deliberately doesn't reuse AppSidebar/AppHeader, which assume a logged-in tenant user. -->
<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3'
import Toast from '@/Components/feedback/Toast.vue'
import { useFlashToast } from '@/Composables/useFlashToast'

useFlashToast()

const logout = () => router.post(route('central.logout'))
</script>

<template>
  <div class="min-h-screen bg-gray-50 font-sans">
    <header class="border-b border-gray-200 bg-white">
      <div class="mx-auto flex max-w-6xl items-center justify-between px-6 py-4">
        <div class="flex items-center gap-6">
          <span class="font-serif text-lg font-semibold text-gray-900">Nusaevo Central</span>
          <nav class="flex items-center gap-4 text-sm font-medium text-gray-600">
            <Link :href="route('central.tenants.index')" class="hover:text-gray-900">Tenants</Link>
            <Link :href="route('central.plans.index')" class="hover:text-gray-900">Plans</Link>
            <Link :href="route('central.invoices.index')" class="hover:text-gray-900">Invoices</Link>
          </nav>
        </div>
        <button type="button" class="text-sm font-medium text-gray-600 hover:text-gray-900" @click="logout">
          Log out
        </button>
      </div>
    </header>

    <main class="mx-auto max-w-6xl px-6 py-8">
      <slot />
    </main>

    <Toast />
  </div>
</template>
