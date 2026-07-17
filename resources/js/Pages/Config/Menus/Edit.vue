<!-- ponytail: Config menu edit form -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'

interface ConfigMenu {
  id: number
  code: string
  menu_caption: string
  menu_header: string | null
  menu_link: string | null
  icon: string | null
  parent_id: number | null
  seq: number
  status_code: string
}

const props = defineProps<{
  menu: ConfigMenu
  parents: Array<{ label: string; value: number }>
}>()

const form = useForm({
  code: props.menu.code,
  menu_caption: props.menu.menu_caption,
  menu_header: props.menu.menu_header ?? 'Main',
  menu_link: props.menu.menu_link ?? '',
  icon: props.menu.icon ?? '',
  parent_id: props.menu.parent_id,
  seq: props.menu.seq,
  status_code: props.menu.status_code,
})

const submit = () => {
  form.put(route('config.menus.update', props.menu.id))
}
</script>

<template>
  <AppLayout>
    <PageHeader
      title="Edit Menu"
      :description="`Update ${menu.menu_caption} (${menu.code}).`"
    />

    <div class="mt-6 max-w-2xl rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
      <form class="space-y-4" @submit.prevent="submit">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <FormInput
            v-model="form.code"
            name="code"
            label="Code"
            :error="form.errors.code"
            required
          />
          <FormInput
            v-model="form.menu_caption"
            name="menu_caption"
            label="Caption"
            :error="form.errors.menu_caption"
            required
          />
        </div>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <FormInput
            v-model="form.menu_header"
            name="menu_header"
            label="Header section"
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
            :error="form.errors.menu_link"
          />
          <FormInput
            v-model="form.icon"
            name="icon"
            label="Lucide icon"
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

        <div class="flex items-center justify-end gap-3 border-t border-gray-100 pt-4">
          <Link :href="route('config.menus.index')" class="text-sm font-semibold text-gray-900">
            Cancel
          </Link>
          <button
            type="submit"
            :disabled="form.processing"
            class="rounded-md bg-gray-900 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-gray-800 disabled:opacity-50"
          >
            Update Menu
          </button>
        </div>
      </form>
    </div>
  </AppLayout>
</template>
