<!-- ponytail: Edit legal case — Panel + design buttons -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import CustomFieldInputs, { type CustomFieldDef } from '@/Components/forms/CustomFieldInputs.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'

const props = defineProps<{
  caseItem: {
    id: number
    code: string
    title: string
    status: string
    notes: string | null
  }
  customFields: CustomFieldDef[]
}>()

const customBag: Record<string, string> = {}
for (const f of props.customFields) {
  customBag[f.code] = f.value ?? ''
}

const form = useForm({
  code: props.caseItem.code,
  title: props.caseItem.title,
  status: props.caseItem.status,
  notes: props.caseItem.notes ?? '',
  custom_fields: customBag,
})

const submit = () => form.put(route('legal.cases.update', props.caseItem.id))
</script>

<template>
  <AppLayout>
    <PageHeader title="Edit case" :description="caseItem.code" />

    <Panel class="mt-6 max-w-xl">
      <form class="space-y-4" @submit.prevent="submit">
        <FormInput v-model="form.code" name="code" label="Case code" :error="form.errors.code" required />
        <FormInput v-model="form.title" name="title" label="Title" :error="form.errors.title" required />
        <FormSelect
          v-model="form.status"
          name="status"
          label="Status"
          :options="[
            { label: 'Open', value: 'open' },
            { label: 'Pending', value: 'pending' },
            { label: 'Closed', value: 'closed' },
          ]"
          :error="form.errors.status"
          required
        />
        <div class="space-y-1.5">
          <label class="text-sm font-medium text-ink-900">Notes</label>
          <textarea
            v-model="form.notes"
            rows="3"
            class="w-full rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm text-ink-900 shadow-sm focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/20"
          />
        </div>
        <CustomFieldInputs
          v-model="form.custom_fields"
          :fields="customFields"
          :errors="form.errors"
        />
        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <Link
            :href="route('legal.cases.index')"
            class="inline-flex items-center justify-center rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm font-semibold text-ink-900 shadow-sm transition hover:bg-surface-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            Cancel
          </Link>
          <PrimaryButton type="submit" :disabled="form.processing">Update case</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
