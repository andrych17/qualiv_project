<!-- ponytail: Edit Perspective (§3C) -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSwitch from '@/Components/forms/FormSwitch.vue'
import PerformanceSubNav from '@/Components/performance/PerformanceSubNav.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'

const props = defineProps<{
  perspective: { id: number; name: string; description: string | null; is_active: boolean }
}>()

const form = useForm({
  name: props.perspective.name,
  description: props.perspective.description ?? '',
  is_active: props.perspective.is_active,
})

const submit = () => form.put(route('performance.perspectives.update', props.perspective.id))
</script>

<template>
  <AppLayout>
    <PageHeader title="Edit perspective" />

    <PerformanceSubNav active="perspectives" class="mt-6" />

    <Panel class="mt-6 max-w-xl">
      <form class="space-y-4" @submit.prevent="submit">
        <FormInput v-model="form.name" name="name" label="Name" :error="form.errors.name" required />
        <FormInput v-model="form.description" name="description" label="Description" :error="form.errors.description" />
        <FormSwitch v-model="form.is_active" label="Active" description="Inactive perspectives are hidden from new KPI assignment." />

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <Link
            :href="route('performance.perspectives.index')"
            class="inline-flex items-center justify-center rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm font-semibold text-ink-900 shadow-sm transition hover:bg-surface-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            Cancel
          </Link>
          <PrimaryButton type="submit" :disabled="form.processing">Update perspective</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
