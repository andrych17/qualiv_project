<!-- ponytail: Add Badge Definition (§3I) -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import { computed } from 'vue'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import PerformanceSubNav from '@/Components/performance/PerformanceSubNav.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'

const triggerOptions = [
  { label: 'Target hit — KPI actual lands on_track for a period', value: 'target_hit' },
  { label: 'OKR completed — an Objective transitions to completed', value: 'okr_completed' },
  { label: 'Streak on track — N consecutive on_track periods for a KPI', value: 'streak_on_track' },
]

const form = useForm({
  name: '',
  trigger_type: 'target_hit' as string,
  streak_length: 3 as number | null,
  icon: '',
  is_active: true,
})

const isStreak = computed(() => form.trigger_type === 'streak_on_track')

const submit = () =>
  form
    .transform(({ streak_length, ...rest }) => ({
      ...rest,
      trigger_params: rest.trigger_type === 'streak_on_track' ? { streak_length } : null,
    }))
    .post(route('performance.badgeDefinitions.store'))
</script>

<template>
  <AppLayout>
    <PageHeader title="Add Badge" />

    <PerformanceSubNav active="badgeDefinitions" class="mt-6" />

    <Panel class="mt-6 max-w-xl">
      <form class="space-y-4" @submit.prevent="submit">
        <FormInput v-model="form.name" name="name" label="Name" placeholder="e.g. Target Crusher" :error="form.errors.name" required />
        <FormSelect v-model="form.trigger_type" name="trigger_type" label="Trigger" :options="triggerOptions" :error="form.errors.trigger_type" required />
        <FormInput
          v-if="isStreak"
          v-model.number="form.streak_length"
          name="streak_length"
          type="number"
          label="Streak length"
          placeholder="3"
          :error="(form.errors as Record<string, string>)['trigger_params.streak_length']"
          required
        />
        <FormInput v-model="form.icon" name="icon" label="Icon (optional)" placeholder="lucide-vue-next name, e.g. Trophy" :error="form.errors.icon" />

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <Link
            :href="route('performance.badgeDefinitions.index')"
            class="inline-flex items-center justify-center rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm font-semibold text-ink-900 shadow-sm transition hover:bg-surface-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            Cancel
          </Link>
          <PrimaryButton type="submit" :disabled="form.processing">Save badge</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
