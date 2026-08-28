<!-- ponytail: Config menu create form -->
<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
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

    <div class="mt-6 max-w-2xl">
      <Panel>
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

          <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
            <SecondaryButton :href="route('config.menus.index')">
              Cancel
            </SecondaryButton>
            <PrimaryButton
              type="submit"
              :disabled="form.processing"
            >
              Save Menu
            </PrimaryButton>
          </div>
        </form>
      </Panel>
    </div>
  </AppLayout>
</template>
