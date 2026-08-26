<!-- ponytail: Shift Schedule Master — shift hours, break times, and active configuration. -->
<script setup lang="ts">
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import HcmSubNav from '@/Components/hcm/HcmSubNav.vue'
import Modal from '@/Components/Modal.vue'
import { useConfirm } from '@/Composables/useConfirmDialog'

interface Shift {
  id: number
  name: string
  start_time: string
  end_time: string
  break_minutes: number
  is_active: boolean
}

const props = defineProps<{
  shifts: { data: Shift[]; total: number }
}>()

const form = useForm({
  id: null as number | null,
  name: '',
  start_time: '09:00',
  end_time: '17:00',
  break_minutes: 60,
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

const openEdit = (shift: Shift) => {
  form.id = shift.id
  form.name = shift.name
  form.start_time = shift.start_time.substring(0, 5)
  form.end_time = shift.end_time.substring(0, 5)
  form.break_minutes = shift.break_minutes
  form.is_active = shift.is_active
  isEditing.value = true
  showModal.value = true
}

const submit = () => {
  if (isEditing.value && form.id) {
    form.put(route('hcm.shifts.update', form.id), {
      onSuccess: () => {
        showModal.value = false
      },
    })
  } else {
    form.post(route('hcm.shifts.store'), {
      onSuccess: () => {
        showModal.value = false
      },
    })
  }
}

const { confirm } = useConfirm()
const deleteShift = (shift: Shift) => {
  confirm({
    title: `Delete Shift "${shift.name}"?`,
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () => router.delete(route('hcm.shifts.destroy', shift.id)),
  })
}
</script>

<template>
  <AppLayout title="Shifts Master">
    <PageHeader title="Shifts" subtitle="Configure work schedules, start/end hours, and break rules.">
      <template #actions>
        <PrimaryButton @click="openCreate">+ Add Shift</PrimaryButton>
      </template>
    </PageHeader>

    <div class="space-y-6">
      <HcmSubNav active="shifts" />

      <Panel>
        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-border text-left text-sm">
            <thead class="bg-surface-sunken text-xs font-medium text-ink-500 uppercase">
              <tr>
                <th class="px-4 py-3">Shift Name</th>
                <th class="px-4 py-3">Start Time</th>
                <th class="px-4 py-3">End Time</th>
                <th class="px-4 py-3">Break (Mins)</th>
                <th class="px-4 py-3">Status</th>
                <th class="px-4 py-3 text-right">Actions</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-border">
              <tr v-if="shifts.data.length === 0">
                <td colspan="6" class="p-4 text-center text-ink-500">No shifts configured.</td>
              </tr>
              <tr v-for="s in shifts.data" :key="s.id" class="hover:bg-surface-raised transition">
                <td class="px-4 py-3 font-medium text-ink-900">{{ s.name }}</td>
                <td class="px-4 py-3">{{ s.start_time }}</td>
                <td class="px-4 py-3">{{ s.end_time }}</td>
                <td class="px-4 py-3">{{ s.break_minutes }}m</td>
                <td class="px-4 py-3">
                  <span
                    class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium"
                    :class="s.is_active ? 'bg-success/15 text-success' : 'bg-neutral/15 text-neutral'"
                  >
                    {{ s.is_active ? 'Active' : 'Inactive' }}
                  </span>
                </td>
                <td class="px-4 py-3 text-right space-x-2">
                  <button type="button" class="text-xs font-medium text-accent hover:underline" @click="openEdit(s)">
                    Edit
                  </button>
                  <button type="button" class="text-xs font-medium text-danger hover:underline" @click="deleteShift(s)">
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
    <Modal :show="showModal" max-width="md" @close="showModal = false">
      <div class="p-6 bg-white rounded-lg">
        <h3 class="text-lg font-bold text-ink-900">{{ isEditing ? 'Edit Shift' : 'New Shift' }}</h3>
        <form @submit.prevent="submit" class="mt-4 space-y-4">
          <div>
            <label class="block text-xs font-medium text-ink-700">Shift Name *</label>
            <input
              v-model="form.name"
              type="text"
              required
              class="mt-1 block w-full rounded-md border-border bg-white text-sm text-ink-900 shadow-sm focus:border-accent focus:ring-accent"
            />
          </div>
          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="block text-xs font-medium text-ink-700">Start Time (HH:MM) *</label>
              <input
                v-model="form.start_time"
                type="text"
                required
                placeholder="09:00"
                class="mt-1 block w-full rounded-md border-border bg-white text-sm text-ink-900 shadow-sm focus:border-accent focus:ring-accent"
              />
            </div>
            <div>
              <label class="block text-xs font-medium text-ink-700">End Time (HH:MM) *</label>
              <input
                v-model="form.end_time"
                type="text"
                required
                placeholder="17:00"
                class="mt-1 block w-full rounded-md border-border bg-white text-sm text-ink-900 shadow-sm focus:border-accent focus:ring-accent"
              />
            </div>
          </div>
          <div>
            <label class="block text-xs font-medium text-ink-700">Break Duration (Minutes)</label>
            <input
              v-model.number="form.break_minutes"
              type="number"
              min="0"
              class="mt-1 block w-full rounded-md border-border bg-white text-sm text-ink-900 shadow-sm focus:border-accent focus:ring-accent"
            />
          </div>
          <div class="flex justify-end space-x-3 pt-2">
            <SecondaryButton type="button" @click="showModal = false">Cancel</SecondaryButton>
            <PrimaryButton :disabled="form.processing">Save Shift</PrimaryButton>
          </div>
        </form>
      </div>
    </Modal>
  </AppLayout>
</template>
