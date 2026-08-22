<!-- ponytail: DMS §3D Folder / Category Management — edit form. -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import FormSearchableSelect from '@/Components/forms/FormSearchableSelect.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'

interface FolderDetail {
  id: number
  name: string
  parent_folder_id: number | null
  default_doc_type_id: number | null
  default_retention_policy_id: number | null
  access_flag: string
}

const props = defineProps<{
  folder: FolderDetail
  parents: Array<{ value: number; label: string }>
  docTypes: Array<{ id: number; name: string }>
  retentionPolicies: Array<{ value: number; label: string }>
}>()

const docTypeOptions = props.docTypes.map((t) => ({ value: t.id, label: t.name }))

const form = useForm({
  name: props.folder.name,
  parent_folder_id: props.folder.parent_folder_id,
  default_doc_type_id: props.folder.default_doc_type_id,
  default_retention_policy_id: props.folder.default_retention_policy_id,
  access_flag: props.folder.access_flag,
})

const submit = () => form.put(route('dms.folders.update', props.folder.id))
</script>

<template>
  <AppLayout>
    <PageHeader :title="`Edit — ${folder.name}`" description="Standalone folder for browsing and organizing documents." />

    <Panel class="mt-6 max-w-xl">
      <form class="space-y-4" @submit.prevent="submit">
        <FormInput v-model="form.name" name="name" label="Name" :error="form.errors.name" required />
        <FormSearchableSelect v-model="form.parent_folder_id" name="parent_folder_id" label="Parent folder" placeholder="No parent (root folder)" :options="parents" :error="form.errors.parent_folder_id" />
        <FormSearchableSelect v-model="form.default_doc_type_id" name="default_doc_type_id" label="Default doc type" placeholder="None" :options="docTypeOptions" :error="form.errors.default_doc_type_id" />
        <FormSearchableSelect
          v-model="form.default_retention_policy_id"
          name="default_retention_policy_id"
          label="Default retention policy"
          placeholder="None"
          :options="retentionPolicies"
          :error="form.errors.default_retention_policy_id"
        />
        <FormSelect
          v-model="form.access_flag"
          name="access_flag"
          label="Access"
          :options="[
            { label: 'Tenant-wide', value: 'tenant' },
            { label: 'Team', value: 'team' },
            { label: 'Private (creator only)', value: 'private' },
          ]"
          :error="form.errors.access_flag"
          required
        />

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <Link
            :href="route('dms.folders.index')"
            class="inline-flex items-center justify-center rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm font-semibold text-ink-900 shadow-sm transition hover:bg-surface-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            Cancel
          </Link>
          <PrimaryButton type="submit" :disabled="form.processing">Save changes</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
