<!-- ponytail: Config group create — rights/users set on edit -->
<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'

const form = useForm({
  code: '',
  descr: '',
  status_code: 'A',
})

const submit = () => form.post(route('config.groups.store'))
</script>

<template>
  <AppLayout>
    <PageHeader
      title="Create Group"
      description="After save you can set menu access and members."
    />

    <div class="mt-6 max-w-xl">
      <Panel>
        <form class="space-y-4" @submit.prevent="submit">
          <FormInput
            v-model="form.code"
            name="code"
            label="Code"
            placeholder="e.g. STAFF"
            :error="form.errors.code"
            required
          />
          <FormInput
            v-model="form.descr"
            name="descr"
            label="Description"
            placeholder="What this group can do"
            :error="form.errors.descr"
          />
          <FormSelect
            v-model="form.status_code"
            name="status_code"
            label="Status"
            :options="[
              { label: 'Active', value: 'A' },
              { label: 'Inactive', value: 'I' },
            ]"
            :error="form.errors.status_code"
            required
          />

          <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
            <SecondaryButton :href="route('config.groups.index')">
              Cancel
            </SecondaryButton>
            <PrimaryButton
              type="submit"
              :disabled="form.processing"
            >
              Continue
            </PrimaryButton>
          </div>
        </form>
      </Panel>
    </div>
  </AppLayout>
</template>
