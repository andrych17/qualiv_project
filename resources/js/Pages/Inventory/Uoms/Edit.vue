<!-- ponytail: Edit UoM (§3B) -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSwitch from '@/Components/forms/FormSwitch.vue'
import InventorySubNav from '@/Components/inventory/InventorySubNav.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'

const props = defineProps<{
  uom: { id: number; code: string; name: string; is_active: boolean }
}>()

const form = useForm({
  code: props.uom.code,
  name: props.uom.name,
  is_active: props.uom.is_active,
})

const submit = () => form.put(route('inventory.uoms.update', props.uom.id))
</script>

<template>
  <AppLayout>
    <PageHeader title="Edit UoM" :description="uom.code" />

    <InventorySubNav active="uoms" class="mt-6" />

    <Panel class="mt-6 max-w-xl">
      <form class="space-y-4" @submit.prevent="submit">
        <FormInput v-model="form.code" name="code" label="Code" :error="form.errors.code" required />
        <FormInput v-model="form.name" name="name" label="Name" :error="form.errors.name" required />
        <FormSwitch v-model="form.is_active" label="Active" description="Inactive UoMs are hidden from the product form." />

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <Link
            :href="route('inventory.uoms.index')"
            class="inline-flex items-center justify-center rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm font-semibold text-ink-900 shadow-sm transition hover:bg-surface-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            Cancel
          </Link>
          <PrimaryButton type="submit" :disabled="form.processing">Update UoM</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
