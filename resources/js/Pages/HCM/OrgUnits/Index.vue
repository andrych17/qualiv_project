<!-- ponytail: OrgUnits management — hierarchical org tree and department list. -->
<script setup lang="ts">
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import HcmSubNav from '@/Components/hcm/HcmSubNav.vue'
import { useConfirm } from '@/Composables/useConfirmDialog'

interface OrgUnit {
  id: number
  name: string
  parent_org_unit_id?: number
  parent?: { name: string }
  is_active: boolean
}

const props = defineProps<{
  orgUnits: {
    data: OrgUnit[]
    total: number
  }
  allOrgUnits: OrgUnit[]
  filters: { search?: string }
}>()

const form = useForm({
  id: null as number | null,
  name: '',
  parent_org_unit_id: '' as string | number,
  is_active: true,
})

const showModal = ref(false)
const isEditing = ref(false)

const openCreate = () => {
  form.reset()
  form.id = null
  isEditing.value = false
  showModal.value = true
}

const openEdit = (unit: OrgUnit) => {
  form.id = unit.id
  form.name = unit.name
  form.parent_org_unit_id = unit.parent_org_unit_id || ''
  form.is_active = unit.is_active
  isEditing.value = true
  showModal.value = true
}

const submit = () => {
  if (isEditing.value && form.id) {
    form.put(route('hcm.orgUnits.update', form.id), {
      onSuccess: () => {
        showModal.value = false
      },
    })
  } else {
    form.post(route('hcm.orgUnits.store'), {
      onSuccess: () => {
        showModal.value = false
      },
    })
  }
}

const { confirm } = useConfirm()
const deleteUnit = (unit: OrgUnit) => {
  confirm({
    title: `Delete Organizational Unit "${unit.name}"?`,
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () => router.delete(route('hcm.orgUnits.destroy', unit.id)),
  })
}
</script>

<template>
  <AppLayout title="Organizational Units">
    <PageHeader title="Org Units" subtitle="Manage departments, divisions, and reporting structure.">
      <template #actions>
        <PrimaryButton @click="openCreate">+ Add Org Unit</PrimaryButton>
      </template>
    </PageHeader>

    <div class="space-y-6">
      <HcmSubNav active="org" />

      <Panel>
        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-border text-left text-sm">
            <thead class="bg-surface-sunken text-xs font-medium text-ink-500 uppercase">
              <tr>
                <th class="px-4 py-3">Unit Name</th>
                <th class="px-4 py-3">Parent Unit</th>
                <th class="px-4 py-3">Status</th>
                <th class="px-4 py-3 text-right">Actions</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-border">
              <tr v-if="orgUnits.data.length === 0">
                <td colspan="4" class="p-4 text-center text-ink-500">No Org Units found.</td>
              </tr>
              <tr v-for="unit in orgUnits.data" :key="unit.id" class="hover:bg-surface-raised transition">
                <td class="px-4 py-3 font-medium text-ink-900">{{ unit.name }}</td>
                <td class="px-4 py-3 text-ink-600">{{ unit.parent?.name ?? '—' }}</td>
                <td class="px-4 py-3">
                  <span
                    class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium"
                    :class="unit.is_active ? 'bg-success/15 text-success' : 'bg-neutral/15 text-neutral'"
                  >
                    {{ unit.is_active ? 'Active' : 'Inactive' }}
                  </span>
                </td>
                <td class="px-4 py-3 text-right space-x-2">
                  <button type="button" class="text-xs font-medium text-accent hover:underline" @click="openEdit(unit)">
                    Edit
                  </button>
                  <button type="button" class="text-xs font-medium text-danger hover:underline" @click="deleteUnit(unit)">
                    Delete
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </Panel>
    </div>

    <!-- Create/Edit Modal -->
    <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center bg-ink-900/50 p-4">
      <div class="w-full max-w-md rounded-lg bg-surface p-6 shadow-xl border border-border">
        <h3 class="text-lg font-bold text-ink-900">{{ isEditing ? 'Edit Org Unit' : 'New Org Unit' }}</h3>
        <form @submit.prevent="submit" class="mt-4 space-y-4">
          <div>
            <label class="block text-xs font-medium text-ink-700">Unit Name *</label>
            <input
              v-model="form.name"
              type="text"
              required
              class="mt-1 block w-full rounded-md border-border bg-surface text-sm text-ink-900 shadow-sm focus:border-accent focus:ring-accent"
            />
          </div>
          <div>
            <label class="block text-xs font-medium text-ink-700">Parent Unit</label>
            <select
              v-model="form.parent_org_unit_id"
              class="mt-1 block w-full rounded-md border-border bg-surface text-sm text-ink-900 shadow-sm focus:border-accent focus:ring-accent"
            >
              <option value="">-- None (Top Level) --</option>
              <option v-for="u in allOrgUnits" :key="u.id" :value="u.id" :disabled="form.id === u.id">
                {{ u.name }}
              </option>
            </select>
          </div>
          <div class="flex items-center space-x-2">
            <input v-model="form.is_active" type="checkbox" id="unit_active" class="rounded border-border text-accent focus:ring-accent" />
            <label for="unit_active" class="text-xs text-ink-700">Active</label>
          </div>
          <div class="flex justify-end space-x-3 pt-2">
            <SecondaryButton type="button" @click="showModal = false">Cancel</SecondaryButton>
            <PrimaryButton :disabled="form.processing">Save</PrimaryButton>
          </div>
        </form>
      </div>
    </div>
  </AppLayout>
</template>
