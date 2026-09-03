<!-- ponytail: Edit Resource Group (PP_SPECS.md §3E) -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSwitch from '@/Components/forms/FormSwitch.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import ResourceGroupMemberListInput, { type ResourceGroupMemberRow } from '@/Components/pp/ResourceGroupMemberListInput.vue'

const props = defineProps<{
  group: {
    id: number
    code: string
    name: string
    is_active: boolean
    members: ResourceGroupMemberRow[]
  }
  resourceOptions: Array<{ value: number; label: string }>
}>()

const form = useForm({
  code: props.group.code,
  name: props.group.name,
  is_active: props.group.is_active,
  members: props.group.members,
})

const submit = () => form.put(route('pp.resourceGroups.update', props.group.id))
</script>

<template>
  <AppLayout>
    <PageHeader title="Edit Resource Group" :description="group.code" />

    <Panel class="mt-6 max-w-3xl">
      <form class="space-y-4" @submit.prevent="submit">
        <div class="grid grid-cols-2 gap-4">
          <FormInput v-model="form.code" name="code" label="Code" :error="form.errors.code" required />
          <FormInput v-model="form.name" name="name" label="Name" :error="form.errors.name" required />
        </div>

        <FormSwitch v-model="form.is_active" name="is_active" label="Active" />

        <ResourceGroupMemberListInput v-model="form.members" :resource-options="resourceOptions" />
        <p v-if="form.errors.members" class="text-sm text-signal-danger">{{ form.errors.members }}</p>

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <Link
            :href="route('pp.resourceGroups.index')"
            class="inline-flex items-center justify-center rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm font-semibold text-ink-900 shadow-sm transition hover:bg-surface-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            Cancel
          </Link>
          <PrimaryButton type="submit" :disabled="form.processing">Save Resource Group</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
