<!-- ponytail: Create tenant user + group assign -->
<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormMultiSelect from '@/Components/forms/FormMultiSelect.vue'

defineProps<{
  groups: Array<{ label: string; value: number }>
}>()

const form = useForm({
  name: '',
  email: '',
  group_ids: [] as number[],
})

const submit = () => form.post(route('config.users.store'))
</script>

<template>
  <AppLayout>
    <PageHeader title="Create User" description="Add a user to this tenant. A password is generated automatically — you'll see it once after saving to share with them." />

    <div class="mt-6 max-w-xl">
      <Panel>
        <form class="space-y-4" @submit.prevent="submit">
          <FormInput v-model="form.name" name="name" label="Name" :error="form.errors.name" required />
          <FormInput v-model="form.email" name="email" label="Email" type="email" :error="form.errors.email" required />

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
              Save User
            </PrimaryButton>
          </div>
        </form>
      </Panel>
    </div>
  </AppLayout>
</template>
