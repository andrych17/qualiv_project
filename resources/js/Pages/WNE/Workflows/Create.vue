<!-- ponytail: WNE §3B — new workflow header (code/name/description/category); steps come after, on Edit. -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import FormTextarea from '@/Components/forms/FormTextarea.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import WneSubNav from '@/Components/wne/WneSubNav.vue'

defineProps<{
  categories: Array<{ id: number; name: string }>
}>()

const form = useForm({
  code: '',
  name: '',
  description: '',
  category_id: null as number | null,
})

const submit = () => form.post(route('wne.workflows.store'))
</script>

<template>
  <AppLayout>
    <PageHeader title="New workflow" description="What a calling module will reference — steps come next." />

    <WneSubNav active="workflows" class="mt-6" />

    <Panel class="mt-6 max-w-2xl">
      <form class="space-y-4" @submit.prevent="submit">
        <FormInput
          v-model="form.code"
          name="code"
          label="Code"
          placeholder="e.g. hcm.leave_approval"
          :error="form.errors.code"
          required
        />
        <FormInput v-model="form.name" name="name" label="Name" :error="form.errors.name" required />
        <FormTextarea v-model="form.description" name="description" label="Description" :error="form.errors.description" />
        <FormSelect
          v-model="form.category_id"
          name="category_id"
          label="Category"
          placeholder="Uncategorized"
          :options="categories.map((c) => ({ label: c.name, value: c.id }))"
          :error="form.errors.category_id"
        />

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <Link
            :href="route('wne.workflows.index')"
            class="inline-flex items-center justify-center rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm font-semibold text-ink-900 shadow-sm transition hover:bg-surface-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            Cancel
          </Link>
          <PrimaryButton type="submit" :disabled="form.processing">Create & add steps</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
