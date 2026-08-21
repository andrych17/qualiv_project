<!-- ponytail: WNE §3L — edit template body/subject/variables + activate/deactivate.
     category/channel/locale are immutable once created (the unique triple identifies the
     row) — delete and recreate to change one of those. -->
<script setup lang="ts">
import { ref } from 'vue'
import { useForm, router, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormTextarea from '@/Components/forms/FormTextarea.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import WneSubNav from '@/Components/wne/WneSubNav.vue'
import TemplatePreviewPanel from '@/Components/wne/TemplatePreviewPanel.vue'

type TemplateDetail = {
  id: number
  category_code: string
  channel: string
  locale: string
  subject: string | null
  body: string
  variables: string[]
  is_active: boolean
}

const props = defineProps<{ template: TemplateDetail }>()

const form = useForm({
  subject: props.template.subject ?? '',
  body: props.template.body,
})

const variables = ref(props.template.variables.join(', '))

const submit = () => {
  form.transform((data) => ({
    ...data,
    variables: variables.value.split(',').map((v) => v.trim()).filter(Boolean),
  })).put(route('wne.templates.update', props.template.id))
}

const activationError = () => (usePage().props.errors as { variables?: string })?.variables

const toggleActive = () => {
  router.post(route(props.template.is_active ? 'wne.templates.deactivate' : 'wne.templates.activate', props.template.id))
}

const destroy = () => {
  if (confirm('Delete this template?')) {
    router.delete(route('wne.templates.destroy', props.template.id))
  }
}
</script>

<template>
  <AppLayout>
    <PageHeader :title="`${template.category_code} — ${template.channel} (${template.locale})`" description="Editing this template's content.">
      <template #actions>
        <StatusBadge :status="template.is_active ? 'active' : 'inactive'" />
        <button
          type="button"
          class="inline-flex items-center justify-center rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm font-semibold text-ink-900 shadow-sm transition hover:bg-surface-50"
          @click="toggleActive"
        >
          {{ template.is_active ? 'Deactivate' : 'Activate' }}
        </button>
        <button
          type="button"
          class="inline-flex items-center justify-center rounded-sm border border-signal-danger/30 bg-surface-0 px-3 py-2 text-sm font-semibold text-signal-danger shadow-sm transition hover:bg-signal-danger/10"
          @click="destroy"
        >
          Delete
        </button>
      </template>
    </PageHeader>

    <WneSubNav active="templates" class="mt-6" />

    <p v-if="activationError()" class="mt-4 text-sm text-signal-danger">{{ activationError() }}</p>

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
      <Panel>
        <form class="space-y-4" @submit.prevent="submit">
          <FormInput v-model="form.subject" name="subject" label="Subject (email/push title)" :error="form.errors.subject" />
          <FormTextarea v-model="form.body" name="body" label="Body" :rows="8" :error="form.errors.body" required />
          <FormInput v-model="variables" name="variables" label="Documented variables (comma-separated)" placeholder="employee_name, due_date" />

          <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
            <PrimaryButton type="submit" :disabled="form.processing">Save</PrimaryButton>
          </div>
        </form>
      </Panel>

      <TemplatePreviewPanel :subject="form.subject" :body="form.body" />
    </div>
  </AppLayout>
</template>
