<!-- ponytail: Leave Management Index — leave requests, statutory types, balance tracking, and review workflows. -->
<script setup lang="ts">
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import HcmSubNav from '@/Components/hcm/HcmSubNav.vue'
import Modal from '@/Components/Modal.vue'
import { useConfirm } from '@/Composables/useConfirmDialog'

interface LeaveRequest {
  id: number
  employee_id: number
  leave_type_id: number
  start_date: string
  end_date: string
  reason?: string
  status: string
  days_count: number
  employee: { id: number; employee_no: string; full_name: string }
  leave_type: { name: string }
}

interface LeaveType {
  id: number
  code: string
  name: string
  is_paid: boolean
  requires_attachment: boolean
  policies?: Array<{
    contract_type?: string
    entitlement_days_per_year: string
    carry_over_max_days: string
  }>
}

const props = defineProps<{
  requests: { data: LeaveRequest[]; total: number }
  leaveTypes: LeaveType[]
  employees: Array<{ id: number; employee_no: string; full_name: string }>
  filters: { search?: string; status?: string }
}>()

const form = useForm({
  employee_id: '',
  leave_type_id: '',
  start_date: new Date().toISOString().split('T')[0],
  end_date: new Date().toISOString().split('T')[0],
  reason: '',
})

const showRequestModal = ref(false)

const submitRequest = () => {
  form.post(route('hcm.leave.requests.store'), {
    onSuccess: () => {
      showRequestModal.value = false
      form.reset()
    },
  })
}

const reviewRequest = (id: number, status: 'approved' | 'rejected') => {
  router.patch(route('hcm.leave.requests.review', id), { status })
}

const { confirm } = useConfirm()

const cancelRequest = (id: number) => {
  confirm({
    title: 'Cancel Leave Request?',
    description: 'Are you sure you want to cancel this leave request?',
    variant: 'destructive',
    confirmText: 'Cancel Request',
    onConfirm: () => router.post(route('hcm.leave.requests.cancel', id)),
  })
}
</script>

<template>
  <AppLayout title="Leave Management">
    <PageHeader title="Leave Management" subtitle="Manage employee leave requests, statutory leave types, and entitlements.">
      <template #actions>
        <PrimaryButton @click="showRequestModal = true">+ Submit Leave Request</PrimaryButton>
      </template>
    </PageHeader>

    <div class="space-y-6">
      <HcmSubNav active="leave" />

      <!-- Statutory Types Overview Cards -->
      <Panel title="Indonesian Statutory & Custom Leave Types">
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <div
            v-for="t in leaveTypes"
            :key="t.id"
            class="rounded-lg border border-border p-3 bg-surface-raised"
          >
            <div class="font-bold text-sm text-ink-900">{{ t.name }} ({{ t.code }})</div>
            <div class="text-xs text-ink-500 mt-1">
              {{ t.is_paid ? 'Paid Leave' : 'Unpaid Leave' }} &bull;
              {{ t.requires_attachment ? 'Doctor note required' : 'No attachment required' }}
            </div>
            <div v-if="t.policies && t.policies.length > 0" class="text-xs text-ink-700 mt-2 font-medium">
              Entitlement: {{ t.policies[0].entitlement_days_per_year }} days/yr (Carryover max: {{ t.policies[0].carry_over_max_days }}d)
            </div>
          </div>
        </div>
      </Panel>

      <!-- Requests Table -->
      <Panel title="Leave Requests">
        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-border text-left text-sm">
            <thead class="bg-surface-sunken text-xs font-medium text-ink-500 uppercase">
              <tr>
                <th class="px-4 py-3">Employee</th>
                <th class="px-4 py-3">Type</th>
                <th class="px-4 py-3">Dates</th>
                <th class="px-4 py-3">Days</th>
                <th class="px-4 py-3">Reason</th>
                <th class="px-4 py-3">Status</th>
                <th class="px-4 py-3 text-right">Actions</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-border">
              <tr v-if="requests.data.length === 0">
                <td colspan="7" class="p-4 text-center text-ink-500">No leave requests found.</td>
              </tr>
              <tr v-for="r in requests.data" :key="r.id" class="hover:bg-surface-raised transition">
                <td class="px-4 py-3 font-medium text-ink-900">{{ r.employee.full_name }}</td>
                <td class="px-4 py-3">{{ r.leave_type.name }}</td>
                <td class="px-4 py-3">{{ r.start_date }} to {{ r.end_date }}</td>
                <td class="px-4 py-3 font-semibold">{{ r.days_count }}</td>
                <td class="px-4 py-3 text-ink-600">{{ r.reason || '—' }}</td>
                <td class="px-4 py-3">
                  <StatusBadge :status="r.status" :variant="r.status === 'approved' ? 'success' : (r.status === 'pending' ? 'warning' : (r.status === 'cancelled' ? 'neutral' : 'danger'))">
                    {{ r.status }}
                  </StatusBadge>
                </td>
                <td class="px-4 py-3 text-right space-x-2">
                  <template v-if="r.status === 'pending'">
                    <button
                      type="button"
                      class="text-xs font-medium text-success hover:underline"
                      @click="reviewRequest(r.id, 'approved')"
                    >
                      Approve
                    </button>
                    <button
                      type="button"
                      class="text-xs font-medium text-danger hover:underline"
                      @click="reviewRequest(r.id, 'rejected')"
                    >
                      Reject
                    </button>
                  </template>
                  <button
                    v-if="r.status === 'approved'"
                    type="button"
                    class="text-xs font-medium text-ink-500 hover:text-ink-900 hover:underline"
                    @click="cancelRequest(r.id)"
                  >
                    Cancel
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </Panel>
    </div>

    <!-- Request Modal -->
    <Modal :show="showRequestModal" max-width="md" @close="showRequestModal = false">
      <div class="p-6 bg-white rounded-lg">
        <h3 class="text-lg font-bold text-ink-900">Submit Leave Request</h3>
        <form @submit.prevent="submitRequest" class="mt-4 space-y-4">
          <div>
            <label class="block text-xs font-medium text-ink-700">Employee *</label>
            <select
              v-model="form.employee_id"
              required
              class="mt-1 block w-full rounded-md border-border bg-white text-sm text-ink-900 shadow-sm focus:border-accent focus:ring-accent"
            >
              <option value="" disabled>-- Select Employee --</option>
              <option v-for="e in employees" :key="e.id" :value="e.id">
                {{ e.employee_no }} - {{ e.full_name }}
              </option>
            </select>
          </div>
          <div>
            <label class="block text-xs font-medium text-ink-700">Leave Type *</label>
            <select
              v-model="form.leave_type_id"
              required
              class="mt-1 block w-full rounded-md border-border bg-white text-sm text-ink-900 shadow-sm focus:border-accent focus:ring-accent"
            >
              <option value="" disabled>-- Select Type --</option>
              <option v-for="t in leaveTypes" :key="t.id" :value="t.id">{{ t.name }} ({{ t.code }})</option>
            </select>
          </div>
          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="block text-xs font-medium text-ink-700">Start Date *</label>
              <input
                v-model="form.start_date"
                type="date"
                required
                class="mt-1 block w-full rounded-md border-border bg-white text-sm text-ink-900 shadow-sm focus:border-accent focus:ring-accent"
              />
            </div>
            <div>
              <label class="block text-xs font-medium text-ink-700">End Date *</label>
              <input
                v-model="form.end_date"
                type="date"
                required
                class="mt-1 block w-full rounded-md border-border bg-white text-sm text-ink-900 shadow-sm focus:border-accent focus:ring-accent"
              />
            </div>
          </div>
          <div>
            <label class="block text-xs font-medium text-ink-700">Reason</label>
            <textarea
              v-model="form.reason"
              rows="2"
              class="mt-1 block w-full rounded-md border-border bg-white text-sm text-ink-900 shadow-sm focus:border-accent focus:ring-accent"
            ></textarea>
          </div>
          <div class="flex justify-end space-x-3 pt-2">
            <SecondaryButton type="button" @click="showRequestModal = false">Cancel</SecondaryButton>
            <PrimaryButton :disabled="form.processing">Submit Request</PrimaryButton>
          </div>
        </form>
      </div>
    </Modal>
  </AppLayout>
</template>
