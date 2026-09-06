<script setup lang="ts">
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import { Link } from '@inertiajs/vue3';
</script>

<template>
    <div
        class="flex min-h-screen flex-col items-center bg-surface-50 text-ink-900 px-4 pt-6 sm:justify-center sm:pt-0 font-sans"
    >
        <div>
            <Link href="/">
                <ApplicationLogo size="lg" />
            </Link>
        </div>

        <div
            class="mt-6 w-full max-w-sm sm:max-w-md overflow-hidden bg-surface-0 border border-border px-6 py-6 shadow-md rounded-lg"
        >
            <slot />
        </div>
    </div>
</template>
