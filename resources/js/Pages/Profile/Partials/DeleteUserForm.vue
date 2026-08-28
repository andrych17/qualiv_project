<script setup lang="ts">
import { nextTick, ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { AlertTriangle, Trash2, Eye, EyeOff } from 'lucide-vue-next'
import DangerButton from '@/Components/DangerButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import Modal from '@/Components/Modal.vue'

const confirmingUserDeletion = ref(false)
const passwordInput = ref<HTMLInputElement | null>(null)
const showPassword = ref(false)

const form = useForm({
  password: '',
})

const confirmUserDeletion = () => {
  confirmingUserDeletion.value = true
  nextTick(() => passwordInput.value?.focus())
}

const deleteUser = () => {
  form.delete(route('profile.destroy'), {
    preserveScroll: true,
    onSuccess: () => closeModal(),
    onError: () => passwordInput.value?.focus(),
    onFinish: () => {
      form.reset()
    },
  })
}

const closeModal = () => {
  confirmingUserDeletion.value = false
  form.clearErrors()
  form.reset()
}
</script>

<template>
  <div class="space-y-4">
    <p class="text-sm text-ink-600">
      Setelah akun Anda dihapus, semua sumber daya dan data terkait akan dihapus secara permanen.
      Sebelum menghapus akun Anda, harap unduh data atau informasi penting yang ingin Anda simpan.
    </p>

    <div>
      <DangerButton @click="confirmUserDeletion" class="flex items-center gap-2">
        <Trash2 class="h-4 w-4" />
        <span>Hapus Akun</span>
      </DangerButton>
    </div>

    <Modal :show="confirmingUserDeletion" @close="closeModal">
      <div class="p-6">
        <div class="flex items-center gap-3 text-signal-danger">
          <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-red-100 text-red-600">
            <AlertTriangle class="h-5 w-5" />
          </div>
          <div>
            <h3 class="text-lg font-semibold text-ink-900">
              Apakah Anda yakin ingin menghapus akun?
            </h3>
            <p class="text-xs text-ink-600">Tindakan ini tidak dapat dibatalkan.</p>
          </div>
        </div>

        <p class="mt-4 text-sm text-ink-600">
          Semua data Anda akan dihapus secara permanen. Silakan masukkan kata sandi Anda untuk mengonfirmasi bahwa Anda ingin menghapus akun ini.
        </p>

        <div class="mt-4 space-y-1.5">
          <label for="delete_password" class="sr-only">Kata Sandi</label>
          <div class="relative">
            <input
              id="delete_password"
              ref="passwordInput"
              v-model="form.password"
              :type="showPassword ? 'text' : 'password'"
              class="w-full rounded-md border border-border bg-surface-0 text-ink-900 px-3 py-2 pr-10 text-sm shadow-sm outline-none transition focus:border-signal-danger focus:ring-2 focus:ring-signal-danger/10"
              :class="form.errors.password ? 'border-red-500' : ''"
              placeholder="Masukkan kata sandi untuk mengonfirmasi"
              @keyup.enter="deleteUser"
            />
            <button
              type="button"
              @click="showPassword = !showPassword"
              class="absolute right-3 top-1/2 -translate-y-1/2 text-ink-600 hover:text-ink-900 focus:outline-none"
            >
              <Eye v-if="!showPassword" class="h-4 w-4" />
              <EyeOff v-else class="h-4 w-4" />
            </button>
          </div>
          <p v-if="form.errors.password" class="text-sm text-red-600">
            {{ form.errors.password }}
          </p>
        </div>

        <div class="mt-6 flex justify-end gap-3 border-t border-border pt-4">
          <SecondaryButton @click="closeModal">
            Batal
          </SecondaryButton>

          <DangerButton
            :class="{ 'opacity-25': form.processing }"
            :disabled="form.processing"
            @click="deleteUser"
            class="flex items-center gap-2"
          >
            <Trash2 class="h-4 w-4" />
            <span>Ya, Hapus Akun Saya</span>
          </DangerButton>
        </div>
      </div>
    </Modal>
  </div>
</template>

