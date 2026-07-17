<!-- ponytail: Config group create — rights/users set on edit -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'

const form = useForm({
  code: '',
  descr: '',
  status_code: 'A',
})

const submit = () => form.post(route('config.groups.store'))
</script>

<template>
  <AppLayout>
    <PageHeader
      title="Create Group"
      description="After save you can set menu access and members."
    />

    <div class="mt-6 max-w-xl rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
      <form class="space-y-4" @submit.prevent="submit">
        <FormInput
          v-model="form.code"
          name="code"
          label="Code"
          placeholder="e.g. STAFF"
          :error="form.errors.code"
          required
        />
        <FormInput
          v-model="form.descr"
          name="descr"
          label="Description"
          placeholder="What this group can do"
          :error="form.errors.descr"
        />
        <FormSelect
          v-model="form.status_code"
          name="status_code"
          label="Status"
          :options="[
            { label: 'Active', value: 'A' },
            { label: 'Inactive', value: 'I' },
          ]"
          :error="form.errors.status_code"
          required
        />

        <div class="flex items-center justify-end gap-3 border-t border-gray-100 pt-4">
          <Link :href="route('config.groups.index')" class="text-sm font-semibold text-gray-900">
            Cancel
          </Link>
          <button
            type="submit"
            :disabled="form.processing"
            class="rounded-md bg-gray-900 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-gray-800 disabled:opacity-50"
          >
            Continue
          </button>
        </div>
      </form>
    </div>
  </AppLayout>
</template>
