<!-- ponytail: Time & Attendance Index — daily logs, exceptions, clock widget, and corrections. -->
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

interface Log {
  id: number
  employee_id: number
  clock_in_at?: string
  clock_out_at?: string
  source: string
  exception_flag: string
  employee: { id: number; employee_no: string; full_name: string }
}

interface Correction {
  id: number
  employee: { full_name: string }
  requested_clock_in_at?: string
  requested_clock_out_at?: string
  reason: string
  status: string
}

const props = defineProps<{
  logs: { data: Log[]; total: number }
  corrections: { data: Correction[] }
  shifts: Array<{ id: number; name: string }>
  employees: Array<{ id: number; employee_no: string; full_name: string }>
  filters: { search?: string; exception_flag?: string; date?: string }
}>()

const clockForm = useForm({
  employee_id: '',
})

const submitClockIn = () => {
  if (!clockForm.employee_id) return
  clockForm.post(route('hcm.attendance.clockIn'))
}

const submitClockOut = () => {
  if (!clockForm.employee_id) return
  clockForm.post(route('hcm.attendance.clockOut'))
}

const reviewCorrection = (id: number, status: 'approved' | 'rejected') => {
  router.patch(route('hcm.attendance.corrections.review', id), { status })
}

const flagVariant = (f: string) => {
  switch (f) {
    case 'on_time':
      return 'success'
    case 'late':
      return 'warning'
    case 'absent':
      return 'danger'
    default:
      return 'neutral'
  }
}
</script>

<template>
  <AppLayout title="Time & Attendance">
    <PageHeader title="Time & Attendance" subtitle="Daily clock-in/out logs, shift tracking, and exception management." />

    <div class="space-y-6">
      <HcmSubNav active="attendance" />

      <!-- Quick Clock In / Out Action Widget -->
      <Panel title="Web Clock In / Out">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center">
          <div class="flex-1">
            <label class="block text-xs font-medium text-ink-700">Select Employee</label>
            <select
              v-model="clockForm.employee_id"
              class="mt-1 block w-full rounded-md border-border bg-surface text-sm text-ink-900 shadow-sm focus:border-accent focus:ring-accent"
            >
              <option value="" disabled>-- Select Employee --</option>
              <option v-for="e in employees" :key="e.id" :value="e.id">
                {{ e.employee_no }} - {{ e.full_name }}
              </option>
            </select>
          </div>
          <div class="flex items-center gap-3 pt-5">
            <PrimaryButton
              type="button"
              :disabled="!clockForm.employee_id || clockForm.processing"
              @click="submitClockIn"
            >
              Clock In
            </PrimaryButton>
            <SecondaryButton
              type="button"
              :disabled="!clockForm.employee_id || clockForm.processing"
              @click="submitClockOut"
            >
              Clock Out
            </SecondaryButton>
          </div>
        </div>
      </Panel>

      <!-- Pending Corrections Review -->
      <Panel v-if="corrections.data.length > 0" title="Pending Attendance Correction Requests">
        <div class="divide-y divide-border">
          <div
            v-for="c in corrections.data"
            :key="c.id"
            class="flex items-center justify-between p-3"
          >
            <div>
              <div class="font-medium text-ink-900">{{ c.employee.full_name }}</div>
              <div class="text-xs text-ink-500">
                Requested: In {{ c.requested_clock_in_at || '-' }} / Out {{ c.requested_clock_out_at || '-' }} &bull; Reason: {{ c.reason }}
              </div>
            </div>
            <div class="flex items-center space-x-2">
              <button
                type="button"
                class="rounded bg-success/15 px-2 py-1 text-xs font-medium text-success hover:bg-success/25"
                @click="reviewCorrection(c.id, 'approved')"
              >
                Approve
              </button>
              <button
                type="button"
                class="rounded bg-danger/15 px-2 py-1 text-xs font-medium text-danger hover:bg-danger/25"
                @click="reviewCorrection(c.id, 'rejected')"
              >
                Reject
              </button>
            </div>
          </div>
        </div>
      </Panel>

      <!-- Logs Table -->
      <Panel>
        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-border text-left text-sm">
            <thead class="bg-surface-sunken text-xs font-medium text-ink-500 uppercase">
              <tr>
                <th class="px-4 py-3">Employee</th>
                <th class="px-4 py-3">Clock In</th>
                <th class="px-4 py-3">Clock Out</th>
                <th class="px-4 py-3">Source</th>
                <th class="px-4 py-3">Status / Exception</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-border">
              <tr v-if="logs.data.length === 0">
                <td colspan="5" class="p-4 text-center text-ink-500">No attendance records found.</td>
              </tr>
              <tr v-for="log in logs.data" :key="log.id" class="hover:bg-surface-raised transition">
                <td class="px-4 py-3 font-medium text-ink-900">{{ log.employee.full_name }}</td>
                <td class="px-4 py-3">{{ log.clock_in_at || '—' }}</td>
                <td class="px-4 py-3">{{ log.clock_out_at || '—' }}</td>
                <td class="px-4 py-3 capitalize text-ink-600">{{ log.source }}</td>
                <td class="px-4 py-3">
                  <StatusBadge :status="log.exception_flag" :variant="flagVariant(log.exception_flag)">
                    {{ log.exception_flag.replace('_', ' ') }}
                  </StatusBadge>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </Panel>
    </div>
  </AppLayout>
</template>
