<!-- ponytail: Edit tenant user + group assign -->
<script setup lang="ts">
import { useForm, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormMultiSelect from '@/Components/forms/FormMultiSelect.vue'
import { useConfirm } from '@/Composables/useConfirmDialog'

const props = defineProps<{
  user: { id: number; name: string; email: string }
  groups: Array<{ label: string; value: number }>
  group_ids: number[]
}>()

const form = useForm({
  name: props.user.name,
  email: props.user.email,
  group_ids: [...props.group_ids] as number[],
})

const submit = () => form.put(route('config.users.update', props.user.id))

const { confirm } = useConfirm()

const resetPassword = () => {
  confirm({
    title: `Reset password for ${props.user.email}?`,
    description: 'A new password is generated immediately and the old one stops working.',
    variant: 'destructive',
    confirmText: 'Reset',
    onConfirm: () => router.patch(route('config.users.resetPassword', props.user.id)),
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader title="Edit User" :description="user.email" />

    <div class="mt-6 max-w-xl">
      <Panel>
        <form class="space-y-4" @submit.prevent="submit">
          <FormInput v-model="form.name" name="name" label="Name" :error="form.errors.name" required />
          <FormInput v-model="form.email" name="email" label="Email" type="email" :error="form.errors.email" required />

          <div class="flex items-center justify-between rounded-md border border-border bg-surface-50 px-3 py-2">
            <p class="text-xs sm:text-sm text-ink-600">Password is admin-generated, not visible after creation.</p>
            <button type="button" class="text-xs sm:text-sm font-medium text-accent hover:underline shrink-0 ml-2 cursor-pointer" @click="resetPassword">
              Reset Password
            </button>
          </div>

          <FormMultiSelect
            v-model="form.group_ids"
            name="group_ids"
            label="Groups"
            placeholder="Pilih grup user..."
            search-placeholder="Cari grup..."
            :options="groups"
            :error="form.errors.group_ids"
          />

          <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
            <SecondaryButton :href="route('config.users.index')">Cancel</SecondaryButton>
            <PrimaryButton
              type="submit"
              :disabled="form.processing"
            >
              Update User
            </PrimaryButton>
          </div>
        </form>
      </Panel>
    </div>
  </AppLayout>
</template>
