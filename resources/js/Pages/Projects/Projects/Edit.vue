<!-- ponytail: Edit project — mirrors Legal/Cases/Edit -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'

const props = defineProps<{
  project: {
    id: number
    code: string
    name: string
    description: string | null
    status: string
    lead_id: number | null
  }
  users: Array<{ id: number; name: string }>
}>()

const form = useForm({
  code: props.project.code,
  name: props.project.name,
  description: props.project.description ?? '',
  status: props.project.status,
  lead_id: (props.project.lead_id ?? '') as number | '',
})

const submit = () => form.put(route('projects.update', props.project.id))
</script>

<template>
  <AppLayout>
    <PageHeader title="Edit project" :description="project.code" />

    <Panel class="mt-6 max-w-xl">
      <form class="space-y-4" @submit.prevent="submit">
        <FormInput v-model="form.code" name="code" label="Code" :error="form.errors.code" required />
        <FormInput v-model="form.name" name="name" label="Name" :error="form.errors.name" required />
        <FormSelect
          v-model="form.status"
          name="status"
          label="Status"
          :options="[
            { label: 'Active', value: 'active' },
            { label: 'Archived', value: 'archived' },
          ]"
          :error="form.errors.status"
          required
        />
        <FormSelect
          v-model="form.lead_id"
          name="lead_id"
          label="Lead"
          placeholder="No lead"
          :options="users.map((u) => ({ label: u.name, value: u.id }))"
          :error="form.errors.lead_id"
        />
        <div class="space-y-1.5">
          <label class="text-sm font-medium text-ink-900">Description</label>
          <textarea
            v-model="form.description"
            rows="3"
            class="w-full rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm text-ink-900 shadow-sm focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/20"
          />
        </div>
        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <Link
            :href="route('projects.index')"
            class="inline-flex items-center justify-center rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm font-semibold text-ink-900 shadow-sm transition hover:bg-surface-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            Cancel
          </Link>
          <PrimaryButton type="submit" :disabled="form.processing">Update project</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
