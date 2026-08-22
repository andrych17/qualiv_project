<!-- ponytail: DMS §3B Document Entry — Edit. Metadata form (no file field — that's a separate
     "new version" action below, since re-uploading is a versioning event, not a metadata
     edit) plus a second, independent form for uploading a new version. -->
<script setup lang="ts">
import { ref } from 'vue'
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormTextarea from '@/Components/forms/FormTextarea.vue'
import FormSearchableSelect from '@/Components/forms/FormSearchableSelect.vue'
import CustomFieldInputs, { type CustomFieldDef } from '@/Components/forms/CustomFieldInputs.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'

interface DocumentDetail {
  id: number
  title: string
  description: string | null
  folder_id: number | null
  doc_type_id: number | null
  tags: string
  subject_type: string | null
  subject_id: number | null
  effective_date: string | null
  expiry_date: string | null
  retention_policy_id: number | null
  status: string
  current_filename: string | null
}

const props = defineProps<{
  document: DocumentDetail
  folders: Array<{ value: number; label: string }>
  docTypes: Array<{ id: number; name: string }>
  retentionPolicies: Array<{ value: number; label: string }>
  customFields: CustomFieldDef[]
}>()

const docTypeOptions = props.docTypes.map((t) => ({ value: t.id, label: t.name }))

const customBag: Record<string, string> = {}
for (const f of props.customFields) customBag[f.code] = f.value ?? ''

const form = useForm({
  title: props.document.title,
  description: props.document.description ?? '',
  folder_id: props.document.folder_id,
  doc_type_id: props.document.doc_type_id,
  tags: props.document.tags,
  subject_type: props.document.subject_type ?? '',
  subject_id: props.document.subject_id ?? '',
  effective_date: props.document.effective_date ?? '',
  expiry_date: props.document.expiry_date ?? '',
  retention_policy_id: props.document.retention_policy_id,
  custom_fields: customBag,
})

const submit = () => form.put(route('dms.documents.update', props.document.id))

// --- Upload new version (separate action from the metadata form above) ---
const versionForm = useForm({ file: null as File | null, version_note: '' })
const dragOver = ref(false)

const submitVersion = () => {
  if (!versionForm.file) return
  versionForm.post(route('dms.documents.versions.store', props.document.id), {
    forceFormData: true,
    onSuccess: () => versionForm.reset(),
  })
}

const onDropFile = (event: DragEvent) => {
  dragOver.value = false
  versionForm.file = event.dataTransfer?.files?.[0] ?? null
}
</script>

<template>
  <AppLayout>
    <PageHeader :title="document.title" description="Edit document metadata, or upload a new version.">
      <template #actions>
        <Link :href="route('dms.dashboard')" class="text-sm font-medium text-accent hover:underline">← Back to library</Link>
      </template>
    </PageHeader>

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
      <Panel title="Metadata" class="lg:col-span-2">
        <form class="space-y-4" @submit.prevent="submit">
          <FormInput v-model="form.title" name="title" label="Title" :error="form.errors.title" required />
          <FormTextarea v-model="form.description" name="description" label="Description" :rows="3" :error="form.errors.description" />

          <div class="grid grid-cols-2 gap-4">
            <FormSearchableSelect v-model="form.folder_id" name="folder_id" label="Folder" placeholder="No folder" :options="folders" :error="form.errors.folder_id" />
            <FormSearchableSelect v-model="form.doc_type_id" name="doc_type_id" label="Doc type" placeholder="No type" :options="docTypeOptions" :error="form.errors.doc_type_id" />
          </div>

          <FormInput v-model="form.tags" name="tags" label="Tags" placeholder="Comma-separated, e.g. urgent, confidential" :error="form.errors.tags" />

          <div class="grid grid-cols-2 gap-4">
            <FormInput v-model="form.subject_type" name="subject_type" label="Owning record type" placeholder="e.g. legal.matters" :error="form.errors.subject_type" />
            <FormInput v-model="form.subject_id" name="subject_id" label="Owning record ID" type="number" :error="form.errors.subject_id" />
          </div>

          <div class="grid grid-cols-2 gap-4">
            <FormInput v-model="form.effective_date" name="effective_date" label="Effective date" type="date" :error="form.errors.effective_date" />
            <FormInput v-model="form.expiry_date" name="expiry_date" label="Expiry date" type="date" :error="form.errors.expiry_date" />
          </div>

          <FormSearchableSelect
            v-model="form.retention_policy_id"
            name="retention_policy_id"
            label="Retention policy"
            placeholder="Use doc type default"
            :options="retentionPolicies"
            :error="form.errors.retention_policy_id"
          />

          <CustomFieldInputs v-model="form.custom_fields" :fields="customFields" :errors="form.errors" />

          <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
            <PrimaryButton type="submit" :disabled="form.processing">Save changes</PrimaryButton>
          </div>
        </form>
      </Panel>

      <Panel title="Upload new version">
        <p class="text-xs text-ink-600">Current file: {{ document.current_filename ?? '—' }}</p>
        <Link :href="route('dms.documents.versions', document.id)" class="mt-1 block text-xs font-medium text-accent hover:underline">View version history →</Link>

        <form class="mt-3 space-y-3" @submit.prevent="submitVersion">
          <div
            class="rounded-md border-2 border-dashed border-border bg-surface-50 p-4 text-center transition"
            :class="dragOver ? 'border-accent bg-accent/5' : ''"
            @dragover.prevent="dragOver = true"
            @dragleave.prevent="dragOver = false"
            @drop.prevent="onDropFile"
          >
            <input id="dms-version-file" type="file" class="sr-only" @change="versionForm.file = ($event.target as HTMLInputElement).files?.[0] ?? null" />
            <label for="dms-version-file" class="inline-block cursor-pointer rounded-sm p-2 text-sm text-ink-600 transition hover:text-accent">
              {{ versionForm.file ? versionForm.file.name : 'Drop a file here, or click to choose' }}
            </label>
            <p v-if="versionForm.errors.file" class="mt-1 text-sm text-signal-danger">{{ versionForm.errors.file }}</p>
          </div>

          <FormInput v-model="versionForm.version_note" name="version_note" label="Version note" :error="versionForm.errors.version_note" />

          <PrimaryButton type="submit" class="w-full" :disabled="versionForm.processing || !versionForm.file">Upload new version</PrimaryButton>
        </form>
      </Panel>
    </div>
  </AppLayout>
</template>
