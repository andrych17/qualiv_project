<!-- ponytail: Create tenant user + group assign -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import FormInput from '@/Components/forms/FormInput.vue'

const props = defineProps<{
  groups: Array<{ label: string; value: number }>
}>()

const form = useForm({
  name: '',
  email: '',
  group_ids: [] as number[],
})

const toggleGroup = (id: number) => {
  const idx = form.group_ids.indexOf(id)
  if (idx >= 0) form.group_ids.splice(idx, 1)
  else form.group_ids.push(id)
}

const submit = () => form.post(route('config.users.store'))
</script>

<template>
  <AppLayout>
    <PageHeader title="Create User" description="Add a user to this tenant. A password is generated automatically — you'll see it once after saving to share with them." />

    <div class="mt-6 max-w-xl rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
      <form class="space-y-4" @submit.prevent="submit">
        <FormInput v-model="form.name" name="name" label="Name" :error="form.errors.name" required />
        <FormInput v-model="form.email" name="email" label="Email" type="email" :error="form.errors.email" required />

        <div class="space-y-2">
          <p class="text-sm font-medium text-gray-700">Groups</p>
          <label
            v-for="g in groups"
            :key="g.value"
            class="flex items-center gap-2 rounded-md border border-gray-100 px-3 py-2 text-sm"
          >
            <input
              type="checkbox"
              class="rounded border-gray-300"
              :checked="form.group_ids.includes(g.value)"
              @change="toggleGroup(g.value)"
            />
            {{ g.label }}
          </label>
        </div>

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
