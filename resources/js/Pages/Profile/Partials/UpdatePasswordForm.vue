<script setup lang="ts">
import { ref, computed } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { Eye, EyeOff, KeyRound, CheckCircle2 } from 'lucide-vue-next'
import PrimaryButton from '@/Components/PrimaryButton.vue'

const currentPasswordInput = ref<HTMLInputElement | null>(null)
const passwordInput = ref<HTMLInputElement | null>(null)

const showCurrentPassword = ref(false)
const showNewPassword = ref(false)
const showConfirmPassword = ref(false)

const form = useForm({
  current_password: '',
  password: '',
  password_confirmation: '',
})

const passwordStrength = computed(() => {
  const pwd = form.password
  if (!pwd) return { score: 0, label: '', color: '', text: '' }
  let score = 0
  if (pwd.length >= 8) score++
  if (/[A-Z]/.test(pwd)) score++
  if (/[0-9]/.test(pwd)) score++
  if (/[^A-Za-z0-9]/.test(pwd)) score++

  if (score <= 1) return { score: 25, label: 'Lemah', color: 'bg-red-500', text: 'text-red-600' }
  if (score === 2) return { score: 50, label: 'Sedang', color: 'bg-amber-500', text: 'text-amber-600' }
  if (score === 3) return { score: 75, label: 'Bagus', color: 'bg-blue-500', text: 'text-blue-600' }
  return { score: 100, label: 'Sangat Kuat', color: 'bg-emerald-500', text: 'text-emerald-600' }
})

const updatePassword = () => {
  form.put(route('password.update'), {
    preserveScroll: true,
    onSuccess: () => {
      form.reset()
    },
    onError: () => {
      if (form.errors.password) {
        form.reset('password', 'password_confirmation')
        passwordInput.value?.focus()
      }
      if (form.errors.current_password) {
        form.reset('current_password')
        currentPasswordInput.value?.focus()
      }
    },
  })
}
</script>

<template>
  <form @submit.prevent="updatePassword" class="space-y-5">
    <div class="space-y-1.5">
      <label for="current_password" class="text-sm font-medium text-ink-900">
        Kata Sandi Saat Ini <span class="text-red-500">*</span>
      </label>
      <div class="relative">
        <input
          id="current_password"
          ref="currentPasswordInput"
          v-model="form.current_password"
          :type="showCurrentPassword ? 'text' : 'password'"
          placeholder="Masukkan kata sandi saat ini"
          class="w-full rounded-md border border-border bg-surface-0 text-ink-900 px-3 py-2 pr-10 text-sm shadow-sm outline-none transition focus:border-accent focus:ring-2 focus:ring-accent/20"
          :class="form.errors.current_password ? 'border-red-500 focus:border-red-500 focus:ring-red-500/10' : ''"
          autocomplete="current-password"
        />
        <button
          type="button"
          @click="showCurrentPassword = !showCurrentPassword"
          class="absolute right-3 top-1/2 -translate-y-1/2 text-ink-600 hover:text-ink-900 focus:outline-none"
        >
          <Eye v-if="!showCurrentPassword" class="h-4 w-4" />
          <EyeOff v-else class="h-4 w-4" />
        </button>
      </div>
      <p v-if="form.errors.current_password" class="text-sm text-red-600">
        {{ form.errors.current_password }}
      </p>
    </div>

    <div class="space-y-1.5">
      <label for="password" class="text-sm font-medium text-ink-900">
        Kata Sandi Baru <span class="text-red-500">*</span>
      </label>
      <div class="relative">
        <input
          id="password"
          ref="passwordInput"
          v-model="form.password"
          :type="showNewPassword ? 'text' : 'password'"
          placeholder="Minimal 8 karakter"
          class="w-full rounded-md border border-border bg-surface-0 text-ink-900 px-3 py-2 pr-10 text-sm shadow-sm outline-none transition focus:border-accent focus:ring-2 focus:ring-accent/20"
          :class="form.errors.password ? 'border-red-500 focus:border-red-500 focus:ring-red-500/10' : ''"
          autocomplete="new-password"
        />
        <button
          type="button"
          @click="showNewPassword = !showNewPassword"
          class="absolute right-3 top-1/2 -translate-y-1/2 text-ink-600 hover:text-ink-900 focus:outline-none"
        >
          <Eye v-if="!showNewPassword" class="h-4 w-4" />
          <EyeOff v-else class="h-4 w-4" />
        </button>
      </div>
      <p v-if="form.errors.password" class="text-sm text-red-600">
        {{ form.errors.password }}
      </p>

      <div v-if="form.password" class="space-y-1 pt-1">
        <div class="flex items-center justify-between text-xs">
          <span class="text-ink-600">Kekuatan Kata Sandi:</span>
          <span class="font-semibold" :class="passwordStrength.text">{{ passwordStrength.label }}</span>
        </div>
        <div class="h-1.5 w-full overflow-hidden rounded-full bg-surface-50">
          <div
            class="h-full transition-all duration-300"
            :class="passwordStrength.color"
            :style="{ width: `${passwordStrength.score}%` }"
          ></div>
        </div>
      </div>
    </div>

    <div class="space-y-1.5">
      <label for="password_confirmation" class="text-sm font-medium text-ink-900">
        Konfirmasi Kata Sandi Baru <span class="text-red-500">*</span>
      </label>
      <div class="relative">
        <input
          id="password_confirmation"
          v-model="form.password_confirmation"
          :type="showConfirmPassword ? 'text' : 'password'"
          placeholder="Ulangi kata sandi baru"
          class="w-full rounded-md border border-border bg-surface-0 text-ink-900 px-3 py-2 pr-10 text-sm shadow-sm outline-none transition focus:border-accent focus:ring-2 focus:ring-accent/20"
          :class="form.errors.password_confirmation ? 'border-red-500 focus:border-red-500 focus:ring-red-500/10' : ''"
          autocomplete="new-password"
        />
        <button
          type="button"
          @click="showConfirmPassword = !showConfirmPassword"
          class="absolute right-3 top-1/2 -translate-y-1/2 text-ink-600 hover:text-ink-900 focus:outline-none"
        >
          <Eye v-if="!showConfirmPassword" class="h-4 w-4" />
          <EyeOff v-else class="h-4 w-4" />
        </button>
      </div>
      <p v-if="form.errors.password_confirmation" class="text-sm text-red-600">
        {{ form.errors.password_confirmation }}
      </p>
    </div>

    <div class="flex items-center gap-4 pt-2">
      <PrimaryButton :disabled="form.processing" class="flex items-center gap-2">
        <KeyRound class="h-4 w-4" />
        <span>Perbarui Kata Sandi</span>
      </PrimaryButton>

      <Transition
        enter-active-class="transition ease-in-out duration-200"
        enter-from-class="opacity-0"
        leave-active-class="transition ease-in-out duration-200"
        leave-to-class="opacity-0"
      >
        <p
          v-if="form.recentlySuccessful"
          class="text-sm font-medium text-signal-success flex items-center gap-1"
        >
          <CheckCircle2 class="h-4 w-4" />
          Kata sandi berhasil diubah.
        </p>
      </Transition>
    </div>
  </form>
</template>

