<!-- ponytail: Add UoM (§3B) -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import InventorySubNav from '@/Components/inventory/InventorySubNav.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'

const form = useForm({
  code: '',
  name: '',
})

const submit = () => form.post(route('inventory.uoms.store'))
</script>

<template>
  <AppLayout>
    <PageHeader title="Add UoM" />

    <InventorySubNav active="uoms" class="mt-6" />

    <Panel class="mt-6 max-w-xl">
      <form class="space-y-4" @submit.prevent="submit">
        <FormInput v-model="form.code" name="code" label="Code" placeholder="e.g. EA, BOX, KG" :error="form.errors.code" required />
        <FormInput v-model="form.name" name="name" label="Name" placeholder="e.g. Each, Box, Kilogram" :error="form.errors.name" required />

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <Link
            :href="route('inventory.uoms.index')"
            class="inline-flex items-center justify-center rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm font-semibold text-ink-900 shadow-sm transition hover:bg-surface-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            Cancel
          </Link>
          <PrimaryButton type="submit" :disabled="form.processing">Save UoM</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
