<script setup lang="ts">
import { computed } from 'vue'
import { Link, useForm, usePage } from '@inertiajs/vue3'
import { CheckCircle2, Save } from 'lucide-vue-next'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import { useTheme } from '@/Composables/useTheme'
import { useI18n } from '@/Composables/useI18n'

defineProps<{
  mustVerifyEmail?: boolean
  status?: string
}>()

const page = usePage()
const user = (page.props as any).auth?.user
const { availableThemes } = useTheme()
const { availableLocales, t } = useI18n()

const localeOptions = computed(() => {
  return availableLocales.value.map((l) => ({
    value: l.code,
    label: `${l.flag} ${l.name} (${l.code.toUpperCase()})`,
  }))
})

const themeOptions = computed(() => {
  return availableThemes.value.map((theme) => ({
    value: theme.id,
    label: `${theme.name} — ${theme.caption}`,
  }))
})

const form = useForm({
  name: user?.name || '',
  email: user?.email || '',
  locale: user?.locale || (page.props as any).locale || 'id',
  theme: user?.theme || (page.props as any).theme || 'classic-navy',
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
      :label="t('profile.name')"
      name="name"
      :placeholder="t('profile.name_placeholder')"
      :error="form.errors.name"
      required
    />

    <FormInput
      v-model="form.email"
      type="email"
      :label="t('profile.email')"
      name="email"
      :placeholder="t('profile.email_placeholder')"
      :error="form.errors.email"
      required
    />

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
      <FormSelect
        v-model="form.locale"
        :label="t('profile.language')"
        name="locale"
        :options="localeOptions"
        :error="form.errors.locale"
        required
      />

      <FormSelect
        v-model="form.theme"
        :label="t('profile.theme')"
        name="theme"
        :options="themeOptions"
        :error="form.errors.theme"
        required
      />
    </div>

    <div v-if="mustVerifyEmail && user?.email_verified_at === null" class="rounded-md border border-amber-200 bg-amber-50 p-4">
      <p class="text-sm text-amber-800">
        {{ t('profile.unverified_email') }}
        <Link
          :href="route('verification.send')"
          method="post"
          as="button"
          class="ml-1 text-sm font-semibold underline text-amber-900 hover:text-amber-700"
        >
          {{ t('profile.resend_verification') }}
        </Link>
      </p>

      <div
        v-show="status === 'verification-link-sent'"
        class="mt-2 text-sm font-medium text-signal-success flex items-center gap-1.5"
      >
        <CheckCircle2 class="h-4 w-4" />
        {{ t('profile.verification_sent') }}
      </div>
    </div>

    <div class="flex items-center gap-4 pt-2">
      <PrimaryButton :disabled="form.processing" class="flex items-center gap-2">
        <Save class="h-4 w-4" />
        <span>{{ t('profile.save_changes') }}</span>
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
          {{ t('profile.saved') }}
        </p>
      </Transition>
    </div>
  </form>
</template>
