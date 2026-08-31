<!-- ponytail: DMS §3B Document Entry — Upload. Drag-and-drop file box moved here from §3A's
     dashboard (which now just links to this page, same "Upload"-button-links-to-Create
     convention as CRM ServiceCases). Tags are a comma-separated FormInput, same convention
     CRM Contacts already uses — no chip/tag-input component exists anywhere in this codebase
     yet, and building one is out of scope for a document upload form. -->
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

const props = defineProps<{
  folders: Array<{ value: number; label: string }>
  docTypes: Array<{ id: number; name: string }>
  retentionPolicies: Array<{ value: number; label: string }>
  customFields: CustomFieldDef[]
  selectedFolderId: number | null
}>()

const docTypeOptions = props.docTypes.map((t) => ({ value: t.id, label: t.name }))

const customBag: Record<string, string> = {}
for (const f of props.customFields) customBag[f.code] = f.value ?? ''

const form = useForm({
  file: null as File | null,
  title: '',
  description: '',
  folder_id: props.selectedFolderId,
  doc_type_id: null as number | null,
  tags: '',
  subject_type: '',
  subject_id: '',
  effective_date: '',
  expiry_date: '',
  retention_policy_id: null as number | null,
  custom_fields: customBag,
})

const dragOver = ref(false)
const onDropFile = (event: DragEvent) => {
  dragOver.value = false
  const file = event.dataTransfer?.files?.[0] ?? null
  form.file = file
  if (file && !form.title) form.title = file.name.replace(/\.[^/.]+$/, '')
}

const submit = () => form.post(route('dms.documents.store'), { forceFormData: true })
</script>

<template>
  <AppLayout>
    <PageHeader title="Upload document" description="Add a document to the library, standalone or attached to a record." />

    <Panel class="mt-6 max-w-2xl">
      <form class="space-y-4" @submit.prevent="submit">
        <div
          class="rounded-md border-2 border-dashed border-border bg-surface-50 p-4 text-center transition"
          :class="dragOver ? 'border-accent bg-accent/5' : ''"
          @dragover.prevent="dragOver = true"
          @dragleave.prevent="dragOver = false"
          @drop.prevent="onDropFile"
        >
          <input id="dms-file" type="file" class="sr-only" @change="form.file = ($event.target as HTMLInputElement).files?.[0] ?? null" />
          <label for="dms-file" class="inline-block cursor-pointer rounded-sm p-2 text-sm text-ink-600 transition hover:text-accent">
            {{ form.file ? form.file.name : 'Drop a file here, or click to choose' }}
          </label>
          <p v-if="form.errors.file" class="mt-1 text-sm text-signal-danger">{{ form.errors.file }}</p>
        </div>

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
          <Link
            :href="route('dms.documents.index')"
            class="inline-flex items-center justify-center rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm font-semibold text-ink-900 shadow-sm transition hover:bg-surface-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            Cancel
          </Link>
          <PrimaryButton type="submit" :disabled="form.processing || !form.file">Upload</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
