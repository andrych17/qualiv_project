<!-- ponytail: Edit Warehouse (§3C) — warehouse fields plus its location tree, depth-indented
     flat listing, same interaction pattern as DMS's Folders/Index.vue. -->
<script setup lang="ts">
import { useForm, Link, router, usePage } from '@inertiajs/vue3'
import { ref, computed, watch } from 'vue'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormTextarea from '@/Components/forms/FormTextarea.vue'
import FormSwitch from '@/Components/forms/FormSwitch.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import InventorySubNav from '@/Components/inventory/InventorySubNav.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import { useConfirm } from '@/Composables/useConfirmDialog'
import { showToast } from '@/Composables/useFlashToast'

interface LocationRow {
  id: number
  code: string
  type: string
  depth: number
  is_active: boolean
  child_count: number
}

const props = defineProps<{
  warehouse: { id: number; name: string; address: string | null; is_active: boolean }
  locations: LocationRow[]
}>()

const form = useForm({
  name: props.warehouse.name,
  address: props.warehouse.address ?? '',
  is_active: props.warehouse.is_active,
})

const submit = () => form.put(route('inventory.warehouses.update', props.warehouse.id))

const search = ref('')
const filteredLocations = computed(() => {
  const q = search.value.trim().toLowerCase()
  if (!q) return props.locations
  return props.locations.filter((l) => l.code.toLowerCase().includes(q))
})

const { confirm } = useConfirm()
const confirmDeleteLocation = (location: LocationRow) => {
  confirm({
    title: `Delete location "${location.code}"?`,
    description: location.child_count
      ? `This location has ${location.child_count} sub-location(s) — deletion will be blocked until it's empty.`
      : undefined,
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () => router.delete(route('inventory.warehouses.locations.destroy', [props.warehouse.id, location.id])),
  })
}

// Blocked-delete guard surfaces as a validation error (LocationService throws
// ValidationException), not a flash message — same pattern as DMS's Folders/Index.vue.
const page = usePage()
watch(() => (page.props.errors as { code?: string })?.code, (message) => {
  if (message) showToast(message, 'error')
})
</script>

<template>
  <AppLayout>
    <PageHeader title="Edit warehouse" :description="warehouse.name" />

    <InventorySubNav active="warehouses" class="mt-6" />

    <div class="mt-6 grid max-w-5xl grid-cols-1 gap-6 lg:grid-cols-3">
      <Panel class="lg:col-span-1">
        <form class="space-y-4" @submit.prevent="submit">
          <FormInput v-model="form.name" name="name" label="Name" :error="form.errors.name" required />
          <FormTextarea v-model="form.address" name="address" label="Address" :error="form.errors.address" />
          <FormSwitch v-model="form.is_active" label="Active" description="Inactive warehouses are hidden from new receipts/issues." />

          <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
            <Link
              :href="route('inventory.warehouses.index')"
              class="inline-flex items-center justify-center rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm font-semibold text-ink-900 shadow-sm transition hover:bg-surface-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
            >
              Cancel
            </Link>
            <PrimaryButton type="submit" :disabled="form.processing">Update warehouse</PrimaryButton>
          </div>
        </form>
      </Panel>

      <Panel class="lg:col-span-2" title="Locations" subtitle="Zone → aisle → bin tree for this warehouse">
        <template #actions>
          <PrimaryButton :href="route('inventory.warehouses.locations.create', warehouse.id)">Add location</PrimaryButton>
        </template>

        <input
          v-model="search"
          type="text"
          placeholder="Search locations…"
          class="mb-4 w-full max-w-xs rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm text-ink-900 shadow-sm focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/20"
        />

        <table class="w-full text-sm">
          <thead>
            <tr class="border-b border-border text-left text-xs uppercase text-ink-600">
              <th class="py-2">Code</th>
              <th class="py-2">Type</th>
              <th class="py-2">Status</th>
              <th class="py-2 text-right">Sub-locations</th>
              <th class="py-2 text-right">Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="l in filteredLocations" :key="l.id" class="border-b border-border hover:bg-surface-50">
              <td class="py-2 text-ink-900" :style="{ paddingLeft: `${8 + l.depth * 16}px` }">{{ l.code }}</td>
              <td class="py-2 text-ink-700 capitalize">{{ l.type }}</td>
              <td class="py-2"><StatusBadge :status="l.is_active ? 'active' : 'inactive'" /></td>
              <td class="py-2 text-right text-ink-700">{{ l.child_count }}</td>
              <td class="py-2 text-right">
                <Link :href="route('inventory.warehouses.locations.edit', [warehouse.id, l.id])" class="mr-3 text-sm font-medium text-accent hover:underline">Edit</Link>
                <button type="button" class="text-sm font-medium text-signal-danger hover:underline" @click="confirmDeleteLocation(l)">Delete</button>
              </td>
            </tr>
            <tr v-if="!filteredLocations.length"><td colspan="5" class="py-6 text-center text-ink-600">No locations yet.</td></tr>
          </tbody>
        </table>
      </Panel>
    </div>
  </AppLayout>
</template>
