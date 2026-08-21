<!-- ponytail: WNE §3L — new template. Category is a plain code field, not a picker — §3L
     pulls in only the minimum of category management it needs (firstOrCreate-by-code on
     save); full category CRUD (default channels, mandatory flag) is §3J's job. -->
<script setup lang="ts">
import { ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import FormTextarea from '@/Components/forms/FormTextarea.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import WneSubNav from '@/Components/wne/WneSubNav.vue'
import TemplatePreviewPanel from '@/Components/wne/TemplatePreviewPanel.vue'

const CHANNELS = ['email', 'sms', 'push', 'in_app']

const form = useForm({
  category_code: '',
  channel: 'email',
  locale: 'en',
  subject: '',
  body: '',
  variablesText: '', // comma-separated on the wire — parsed to an array at submit
})

const variables = ref('')

const submit = () => {
  form.transform((data) => ({
    ...data,
    variables: variables.value.split(',').map((v) => v.trim()).filter(Boolean),
  })).post(route('wne.templates.store'))
}
</script>

<template>
  <AppLayout>
    <PageHeader title="New template" description="Category is created automatically if the code doesn't exist yet." />

    <WneSubNav active="templates" class="mt-6" />

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
      <Panel>
        <form class="space-y-4" @submit.prevent="submit">
          <FormInput v-model="form.category_code" name="category_code" label="Category code" placeholder="e.g. wne.sla_breach" :error="form.errors.category_code" required />
          <FormSelect v-model="form.channel" name="channel" label="Channel" :options="CHANNELS.map((c) => ({ label: c, value: c }))" :error="form.errors.channel" required />
          <FormInput v-model="form.locale" name="locale" label="Locale" placeholder="en" :error="form.errors.locale" />
          <FormInput v-model="form.subject" name="subject" label="Subject (email/push title)" :error="form.errors.subject" />
          <FormTextarea v-model="form.body" name="body" label="Body" placeholder="Hi {{employee_name}}, your request is due {{due_date}}." :rows="8" :error="form.errors.body" required />
          <FormInput v-model="variables" name="variables" label="Documented variables (comma-separated)" placeholder="employee_name, due_date" />

          <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
            <PrimaryButton type="submit" :disabled="form.processing">Create template</PrimaryButton>
          </div>
        </form>
      </Panel>

      <TemplatePreviewPanel :subject="form.subject" :body="form.body" />
    </div>
  </AppLayout>
</template>
