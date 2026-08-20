<!-- ponytail: Config menu create form -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'

defineProps<{
  parents: Array<{ label: string; value: number }>
}>()

const form = useForm({
  code: '',
  menu_caption: '',
  menu_header: 'Main',
  menu_link: '',
  icon: '',
  parent_id: null as number | null,
  seq: 100,
  status_code: 'A',
  module_code: '',
})

const submit = () => {
  form.post(route('config.menus.store'))
}
</script>

<template>
  <AppLayout>
    <PageHeader
      title="Create Menu"
      description="Add a sidebar menu entry for this tenant."
    />

    <div class="mt-6 max-w-2xl rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
      <form class="space-y-4" @submit.prevent="submit">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <FormInput
            v-model="form.code"
            name="code"
            label="Code"
            placeholder="e.g. INVENTORY"
            :error="form.errors.code"
            required
          />
          <FormInput
            v-model="form.menu_caption"
            name="menu_caption"
            label="Caption"
            placeholder="e.g. Inventory"
            :error="form.errors.menu_caption"
            required
          />
        </div>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <FormInput
            v-model="form.menu_header"
            name="menu_header"
            label="Header section"
            placeholder="e.g. Operations"
            :error="form.errors.menu_header"
          />
          <FormInput
            v-model.number="form.seq"
            name="seq"
            label="Sequence"
            type="number"
            :error="form.errors.seq"
            required
          />
        </div>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <FormInput
            v-model="form.menu_link"
            name="menu_link"
            label="Link"
            placeholder="/inventory/items or #"
            :error="form.errors.menu_link"
          />
          <FormInput
            v-model="form.icon"
            name="icon"
            label="Lucide icon"
            placeholder="e.g. Boxes"
            :error="form.errors.icon"
          />
        </div>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <FormSelect
            v-model="form.parent_id"
            name="parent_id"
            label="Parent menu"
            placeholder="None (top-level)"
            :options="parents"
            :error="form.errors.parent_id"
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
        </div>
        <FormInput
          v-model="form.module_code"
          name="module_code"
          label="Module code"
          placeholder="e.g. LEGAL — empty = always visible"
          :error="form.errors.module_code"
        />

        <div class="flex items-center justify-end gap-3 border-t border-gray-100 pt-4">
          <Link :href="route('config.menus.index')" class="text-sm font-semibold text-gray-900">
            Cancel
          </Link>
          <button
            type="submit"
            :disabled="form.processing"
            class="rounded-md bg-gray-900 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-gray-800 disabled:opacity-50"
          >
            Save Menu
          </button>
        </div>
      </form>
    </div>
  </AppLayout>
</template>
