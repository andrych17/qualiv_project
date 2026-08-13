<script setup lang="ts">
import { Link, useForm, usePage } from '@inertiajs/vue3'
import { CheckCircle2, Save } from 'lucide-vue-next'
import FormInput from '@/Components/forms/FormInput.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'

defineProps<{
  mustVerifyEmail?: boolean
  status?: string
}>()

const page = usePage()
const user = page.props.auth.user

const form = useForm({
  name: user.name,
  email: user.email,
})

const submit = () => {
  form.patch(route('profile.update'), {
    preserveScroll: true,
  })
}
</script>

<template>
  <form @submit.prevent="submit" class="space-y-5">
    <FormInput
      v-model="form.name"
      label="Nama Lengkap"
      name="name"
      placeholder="Masukkan nama lengkap"
      :error="form.errors.name"
      required
    />

    <FormInput
      v-model="form.email"
      type="email"
      label="Alamat Email"
      name="email"
      placeholder="nama@perusahaan.com"
      :error="form.errors.email"
      required
    />

    <div v-if="mustVerifyEmail && user.email_verified_at === null" class="rounded-md border border-amber-200 bg-amber-50 p-4">
      <p class="text-sm text-amber-800">
        Alamat email Anda belum diverifikasi.
        <Link
          :href="route('verification.send')"
          method="post"
          as="button"
          class="ml-1 text-sm font-semibold underline text-amber-900 hover:text-amber-700"
        >
          Kirim ulang email verifikasi.
        </Link>
      </p>

      <div
        v-show="status === 'verification-link-sent'"
        class="mt-2 text-sm font-medium text-signal-success flex items-center gap-1.5"
      >
        <CheckCircle2 class="h-4 w-4" />
        Tautan verifikasi baru telah dikirimkan ke email Anda.
      </div>
    </div>

    <div class="flex items-center gap-4 pt-2">
      <PrimaryButton :disabled="form.processing" class="flex items-center gap-2">
        <Save class="h-4 w-4" />
        <span>Simpan Perubahan</span>
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
          <CheckCircle2 class="h-4 w-4 text-signal-success" />
          Tersimpan
        </p>
      </Transition>
    </div>
  </form>
</template>

