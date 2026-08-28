<!-- ponytail: Edit OKR Cycle (§3E) -->
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
  cycle: { id: number; label: string; start_date: string; end_date: string; is_active: boolean }
}>()

const form = useForm({
  label: props.cycle.label,
  start_date: props.cycle.start_date,
  end_date: props.cycle.end_date,
  is_active: props.cycle.is_active,
})

const submit = () => form.put(route('performance.okrCycles.update', props.cycle.id))
</script>

<template>
  <AppLayout>
    <PageHeader title="Edit OKR Cycle" />

    <PerformanceSubNav active="okrCycles" class="mt-6" />

    <Panel class="mt-6 max-w-xl">
      <form class="space-y-4" @submit.prevent="submit">
        <FormInput v-model="form.label" name="label" label="Label" :error="form.errors.label" required />
        <div class="grid grid-cols-2 gap-4">
          <FormInput v-model="form.start_date" name="start_date" type="date" label="Start date" :error="form.errors.start_date" required />
          <FormInput v-model="form.end_date" name="end_date" type="date" label="End date" :error="form.errors.end_date" required />
        </div>
        <FormSwitch v-model="form.is_active" label="Active" />

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <Link
            :href="route('performance.okrCycles.index')"
            class="inline-flex items-center justify-center rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm font-semibold text-ink-900 shadow-sm transition hover:bg-surface-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            Cancel
          </Link>
          <PrimaryButton type="submit" :disabled="form.processing">Update cycle</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
