<!-- ponytail: §3L live preview — calls the same renderer the send path uses
     (MsgTemplateController@preview -> TemplateRenderingService), so preview can never show
     something a real send wouldn't produce. -->
<script setup lang="ts">
import { ref, watch } from 'vue'
import Panel from '@/Components/cards/Panel.vue'
import FormTextarea from '@/Components/forms/FormTextarea.vue'
import { debounce } from '@/Composables/debounce'

const props = defineProps<{
  subject: string
  body: string
}>()

const sampleData = ref('{\n  "employee_name": "Jane Doe",\n  "due_date": "2026-03-01"\n}')
const placeholderExample = '{{' + 'var' + '}}'
const renderedSubject = ref<string | null>(null)
const renderedBody = ref<string | null>(null)
const previewError = ref('')

// No <meta name="csrf-token"> in this app's layout — Inertia's own axios instance relies on
// the XSRF-TOKEN cookie Laravel sets on every response instead, so a raw fetch() does the same.
const xsrfToken = () => decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '')

const runPreview = debounce(async () => {
  let parsed: Record<string, unknown> = {}
  try {
    parsed = JSON.parse(sampleData.value || '{}')
  } catch {
    previewError.value = 'Sample data must be valid JSON.'
    return
  }
  previewError.value = ''

  const response = await fetch(route('wne.templates.preview'), {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrfToken() },
    body: JSON.stringify({ subject: props.subject, body: props.body, sample_data: JSON.stringify(parsed) }),
  })
  const result = await response.json()
  renderedSubject.value = result.subject
  renderedBody.value = result.body
}, 400)

watch([() => props.subject, () => props.body, sampleData], runPreview, { immediate: true })
</script>

<template>
  <Panel>
    <h2 class="font-serif text-lg font-semibold text-ink-900">Live preview</h2>
    <p class="mt-1 text-sm text-ink-600">A missing variable stays visible as <code class="font-mono">{{ placeholderExample }}</code> — never a silent blank.</p>

    <div class="mt-4 space-y-4">
      <FormTextarea v-model="sampleData" name="sample_data" label="Sample data (JSON)" :rows="6" :error="previewError" />

      <div class="rounded-md border border-border bg-surface-50 p-4">
        <p v-if="renderedSubject" class="text-sm font-semibold text-ink-900">{{ renderedSubject }}</p>
        <p class="mt-2 whitespace-pre-wrap text-sm text-ink-900">{{ renderedBody ?? 'Nothing to preview yet.' }}</p>
      </div>
    </div>
  </Panel>
</template>
