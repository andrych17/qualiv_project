<!-- ponytail: Positions management — seat definitions, reporting lines, and org mapping. -->
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

interface Position {
  id: number
  job_id: number
  org_unit_id: number
  reports_to_position_id?: number
  headcount_cap?: number
  is_active: boolean
  job?: { code: string; title: string }
  org_unit?: { name: string }
  reports_to?: { job?: { title: string } }
}

const props = defineProps<{
  positions: { data: Position[]; total: number }
  jobs: Array<{ id: number; title: string }>
  orgUnits: Array<{ id: number; name: string }>
  allPositions: Position[]
}>()

const form = useForm({
  id: null as number | null,
  job_id: '' as string | number,
  org_unit_id: '' as string | number,
  reports_to_position_id: '' as string | number,
  headcount_cap: 1,
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

const openEdit = (pos: Position) => {
  form.id = pos.id
  form.job_id = pos.job_id
  form.org_unit_id = pos.org_unit_id
  form.reports_to_position_id = pos.reports_to_position_id || ''
  form.headcount_cap = pos.headcount_cap || 1
  form.is_active = pos.is_active
  isEditing.value = true
  showModal.value = true
}

const submit = () => {
  if (isEditing.value && form.id) {
    form.put(route('hcm.positions.update', form.id), {
      onSuccess: () => {
        showModal.value = false
      },
    })
  } else {
    form.post(route('hcm.positions.store'), {
      onSuccess: () => {
        showModal.value = false
      },
    })
  }
}

const { confirm } = useConfirm()
const deletePos = (pos: Position) => {
  confirm({
    title: `Delete Position "${pos.job?.title}" in ${pos.org_unit?.name}?`,
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () => router.delete(route('hcm.positions.destroy', pos.id)),
  })
}
</script>

<template>
  <AppLayout title="Positions">
    <PageHeader title="Positions" subtitle="Manage role seats, reporting hierarchy, and department mapping.">
      <template #actions>
        <PrimaryButton @click="openCreate">+ Add Position</PrimaryButton>
      </template>
    </PageHeader>

    <div class="space-y-6">
      <HcmSubNav active="positions" />

      <Panel>
        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-border text-left text-sm">
            <thead class="bg-surface-sunken text-xs font-medium text-ink-500 uppercase">
              <tr>
                <th class="px-4 py-3">Job Role</th>
                <th class="px-4 py-3">Org Unit</th>
                <th class="px-4 py-3">Reports To</th>
                <th class="px-4 py-3">Cap</th>
                <th class="px-4 py-3">Status</th>
                <th class="px-4 py-3 text-right">Actions</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-border">
              <tr v-if="positions.data.length === 0">
                <td colspan="6" class="p-4 text-center text-ink-500">No positions found.</td>
              </tr>
              <tr v-for="pos in positions.data" :key="pos.id" class="hover:bg-surface-raised transition">
                <td class="px-4 py-3 font-medium text-ink-900">{{ pos.job?.title }}</td>
                <td class="px-4 py-3 text-ink-600">{{ pos.org_unit?.name }}</td>
                <td class="px-4 py-3 text-ink-600">{{ pos.reports_to?.job?.title ?? '—' }}</td>
                <td class="px-4 py-3 text-ink-700">{{ pos.headcount_cap ?? '—' }}</td>
                <td class="px-4 py-3">
                  <span
                    class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium"
                    :class="pos.is_active ? 'bg-success/15 text-success' : 'bg-neutral/15 text-neutral'"
                  >
                    {{ pos.is_active ? 'Active' : 'Inactive' }}
                  </span>
                </td>
                <td class="px-4 py-3 text-right space-x-2">
                  <button type="button" class="text-xs font-medium text-accent hover:underline" @click="openEdit(pos)">
                    Edit
                  </button>
                  <button type="button" class="text-xs font-medium text-danger hover:underline" @click="deletePos(pos)">
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
        <h3 class="text-lg font-bold text-ink-900">{{ isEditing ? 'Edit Position' : 'New Position' }}</h3>
        <form @submit.prevent="submit" class="mt-4 space-y-4">
          <div>
            <label class="block text-xs font-medium text-ink-700">Job Title / Role *</label>
            <select
              v-model="form.job_id"
              required
              class="mt-1 block w-full rounded-md border-border bg-surface text-sm text-ink-900 shadow-sm focus:border-accent focus:ring-accent"
            >
              <option value="" disabled>-- Select Job --</option>
              <option v-for="j in jobs" :key="j.id" :value="j.id">{{ j.title }}</option>
            </select>
          </div>
          <div>
            <label class="block text-xs font-medium text-ink-700">Org Unit / Department *</label>
            <select
              v-model="form.org_unit_id"
              required
              class="mt-1 block w-full rounded-md border-border bg-surface text-sm text-ink-900 shadow-sm focus:border-accent focus:ring-accent"
            >
              <option value="" disabled>-- Select Department --</option>
              <option v-for="u in orgUnits" :key="u.id" :value="u.id">{{ u.name }}</option>
            </select>
          </div>
          <div>
            <label class="block text-xs font-medium text-ink-700">Reports To (Direct Manager Position)</label>
            <select
              v-model="form.reports_to_position_id"
              class="mt-1 block w-full rounded-md border-border bg-surface text-sm text-ink-900 shadow-sm focus:border-accent focus:ring-accent"
            >
              <option value="">-- None (Top Level) --</option>
              <option v-for="p in allPositions" :key="p.id" :value="p.id" :disabled="form.id === p.id">
                {{ p.job?.title }} ({{ p.org_unit?.name }})
              </option>
            </select>
          </div>
          <div>
            <label class="block text-xs font-medium text-ink-700">Headcount Cap</label>
            <input
              v-model.number="form.headcount_cap"
              type="number"
              min="1"
              class="mt-1 block w-full rounded-md border-border bg-surface text-sm text-ink-900 shadow-sm focus:border-accent focus:ring-accent"
            />
          </div>
          <div class="flex justify-end space-x-3 pt-2">
            <SecondaryButton type="button" @click="showModal = false">Cancel</SecondaryButton>
            <PrimaryButton :disabled="form.processing">Save Position</PrimaryButton>
          </div>
        </form>
      </div>
    </div>
  </AppLayout>
</template>
