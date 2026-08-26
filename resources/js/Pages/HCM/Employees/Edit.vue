<!-- ponytail: Edit Employee Master record. -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'

interface Employee {
  id: number
  employee_no: string
  full_name: string
  date_of_birth?: string
  gender?: string
  nik?: string
  npwp?: string
  bpjs_kesehatan_no?: string
  bpjs_ketenagakerjaan_no?: string
  address?: string
  marital_status?: string
  dependents_count: number
  religion?: string
  hire_date: string
  employment_status: string
  position_id?: number
  bank_name?: string
  bank_account_no?: string
  bank_account_holder_name?: string
}

const props = defineProps<{
  employee: Employee
  positions: Array<{ id: number; job?: { title: string }; org_unit?: { name: string } }>
  orgUnits: Array<{ id: number; name: string }>
}>()

const form = useForm({
  employee_no: props.employee.employee_no,
  full_name: props.employee.full_name,
  date_of_birth: props.employee.date_of_birth || '',
  gender: props.employee.gender || 'male',
  nik: props.employee.nik || '',
  npwp: props.employee.npwp || '',
  bpjs_kesehatan_no: props.employee.bpjs_kesehatan_no || '',
  bpjs_ketenagakerjaan_no: props.employee.bpjs_ketenagakerjaan_no || '',
  address: props.employee.address || '',
  marital_status: props.employee.marital_status || 'single',
  dependents_count: props.employee.dependents_count ?? 0,
  religion: props.employee.religion || 'Islam',
  hire_date: props.employee.hire_date,
  employment_status: props.employee.employment_status,
  position_id: props.employee.position_id || '',
  bank_name: props.employee.bank_name || '',
  bank_account_no: props.employee.bank_account_no || '',
  bank_account_holder_name: props.employee.bank_account_holder_name || '',
})

const submit = () => {
  form.put(route('hcm.employees.update', props.employee.id))
}
</script>

<template>
  <AppLayout :title="`Edit ${employee.full_name}`">
    <PageHeader :title="`Edit ${employee.full_name}`" :subtitle="`Employee No: ${employee.employee_no}`" />

    <form @submit.prevent="submit" class="max-w-4xl space-y-6">
      <Panel title="Personal & Identity">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div>
            <label class="block text-xs font-medium text-ink-700">Full Name *</label>
            <input
              v-model="form.full_name"
              type="text"
              required
              class="mt-1 block w-full rounded-md border-border bg-surface text-sm text-ink-900 shadow-sm focus:border-accent focus:ring-accent"
            />
          </div>
          <div>
            <label class="block text-xs font-medium text-ink-700">Employee Number *</label>
            <input
              v-model="form.employee_no"
              type="text"
              required
              class="mt-1 block w-full rounded-md border-border bg-surface text-sm text-ink-900 shadow-sm focus:border-accent focus:ring-accent"
            />
          </div>

          <div>
            <label class="block text-xs font-medium text-ink-700">NIK (16 Digits)</label>
            <input
              v-model="form.nik"
              type="text"
              maxlength="16"
              class="mt-1 block w-full rounded-md border-border bg-surface text-sm text-ink-900 shadow-sm focus:border-accent focus:ring-accent"
            />
          </div>
          <div>
            <label class="block text-xs font-medium text-ink-700">NPWP</label>
            <input
              v-model="form.npwp"
              type="text"
              class="mt-1 block w-full rounded-md border-border bg-surface text-sm text-ink-900 shadow-sm focus:border-accent focus:ring-accent"
            />
          </div>

          <div>
            <label class="block text-xs font-medium text-ink-700">Employment Status *</label>
            <select
              v-model="form.employment_status"
              class="mt-1 block w-full rounded-md border-border bg-surface text-sm text-ink-900 shadow-sm focus:border-accent focus:ring-accent"
            >
              <option value="active">Active</option>
              <option value="on_leave">On Leave</option>
              <option value="suspended">Suspended</option>
              <option value="terminated">Terminated</option>
            </select>
          </div>
          <div>
            <label class="block text-xs font-medium text-ink-700">Position Assignment</label>
            <select
              v-model="form.position_id"
              class="mt-1 block w-full rounded-md border-border bg-surface text-sm text-ink-900 shadow-sm focus:border-accent focus:ring-accent"
            >
              <option value="">-- No Position --</option>
              <option v-for="pos in positions" :key="pos.id" :value="pos.id">
                {{ pos.job?.title }} &bull; {{ pos.org_unit?.name }}
              </option>
            </select>
          </div>

          <div>
            <label class="block text-xs font-medium text-ink-700">BPJS Kesehatan No</label>
            <input
              v-model="form.bpjs_kesehatan_no"
              type="text"
              class="mt-1 block w-full rounded-md border-border bg-surface text-sm text-ink-900 shadow-sm focus:border-accent focus:ring-accent"
            />
          </div>
          <div>
            <label class="block text-xs font-medium text-ink-700">BPJS Ketenagakerjaan No</label>
            <input
              v-model="form.bpjs_ketenagakerjaan_no"
              type="text"
              class="mt-1 block w-full rounded-md border-border bg-surface text-sm text-ink-900 shadow-sm focus:border-accent focus:ring-accent"
            />
          </div>
        </div>
      </Panel>

      <div class="flex items-center justify-end space-x-3">
        <Link :href="route('hcm.employees.show', employee.id)">
          <SecondaryButton>Cancel</SecondaryButton>
        </Link>
        <PrimaryButton :disabled="form.processing">Save Changes</PrimaryButton>
      </div>
    </form>
  </AppLayout>
</template>
