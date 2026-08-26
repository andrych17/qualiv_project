<!-- ponytail: Create tenant user + group assign -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormMultiSelect from '@/Components/forms/FormMultiSelect.vue'

const props = defineProps<{
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

    <div class="mt-6 max-w-xl rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
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

        <div class="flex items-center justify-end gap-3 border-t border-gray-100 pt-4">
          <Link :href="route('config.users.index')" class="text-sm font-semibold text-gray-900">Cancel</Link>
          <button
            type="submit"
            :disabled="form.processing"
            class="rounded-md bg-gray-900 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-gray-800 disabled:opacity-50"
          >
            Save User
          </button>
        </div>
      </form>
    </div>
  </AppLayout>
</template>
