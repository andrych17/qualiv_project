<!-- ponytail: Edit Employee Master record. -->
<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import FormNumberInput from '@/Components/forms/FormNumberInput.vue'

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
  position_id: props.employee.position_id ?? (null as number | null),
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

    <form @submit.prevent="submit" class="mt-6 max-w-4xl space-y-6">
      <Panel title="Personal & Identity">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div>
            <FormInput
              label="Full Name"
              name="full_name"
              v-model="form.full_name"
              :error="form.errors.full_name"
              required
            />
          </div>
          <div>
            <FormInput
              label="Employee Number"
              name="employee_no"
              v-model="form.employee_no"
              :error="form.errors.employee_no"
              required
            />
          </div>

          <div>
            <FormInput
              label="NIK (16 Digits)"
              name="nik"
              v-model="form.nik"
              :error="form.errors.nik"
              maxlength="16"
            />
          </div>
          <div>
            <FormInput
              label="NPWP"
              name="npwp"
              v-model="form.npwp"
              :error="form.errors.npwp"
            />
          </div>

          <div>
            <FormSelect
              label="Employment Status"
              name="employment_status"
              v-model="form.employment_status"
              :error="form.errors.employment_status"
              :options="[
                { label: 'Active', value: 'active' },
                { label: 'On Leave', value: 'on_leave' },
                { label: 'Suspended', value: 'suspended' },
                { label: 'Terminated', value: 'terminated' },
              ]"
              required
            />
          </div>
          <div>
            <FormSelect
              label="Position"
              name="position_id"
              v-model="form.position_id"
              :error="form.errors.position_id"
              :options="positions.map(pos => ({ label: `${pos.job?.title ?? 'Role'} • ${pos.org_unit?.name ?? 'Unit'}`, value: pos.id }))"
              placeholder="Unassigned"
            />
          </div>

          <div>
            <FormSelect
              label="Gender"
              name="gender"
              v-model="form.gender"
              :options="[
                { label: 'Male', value: 'male' },
                { label: 'Female', value: 'female' },
                { label: 'Other', value: 'other' },
              ]"
            />
          </div>
          <div>
            <FormSelect
              label="Marital Status"
              name="marital_status"
              v-model="form.marital_status"
              :options="[
                { label: 'Single', value: 'single' },
                { label: 'Married', value: 'married' },
                { label: 'Divorced', value: 'divorced' },
                { label: 'Widowed', value: 'widowed' },
              ]"
            />
          </div>

          <div>
            <FormNumberInput
              label="Dependents Count"
              name="dependents_count"
              v-model="form.dependents_count"
              :min="0"
              :max="10"
            />
          </div>
          <div>
            <FormInput
              label="Hire Date"
              name="hire_date"
              type="date"
              v-model="form.hire_date"
              :error="form.errors.hire_date"
              required
            />
          </div>
        </div>
      </Panel>

      <!-- Bank Details -->
      <Panel title="Bank Account for Payroll">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
          <div>
            <FormInput
              label="Bank Name"
              name="bank_name"
              v-model="form.bank_name"
              :error="form.errors.bank_name"
            />
          </div>
          <div>
            <FormInput
              label="Bank Account Number"
              name="bank_account_no"
              v-model="form.bank_account_no"
              :error="form.errors.bank_account_no"
            />
          </div>
          <div>
            <FormInput
              label="Account Holder Name"
              name="bank_account_holder_name"
              v-model="form.bank_account_holder_name"
              :error="form.errors.bank_account_holder_name"
            />
          </div>
        </div>
      </Panel>

      <div class="flex items-center justify-end gap-3">
        <SecondaryButton :href="route('hcm.employees.show', employee.id)">Cancel</SecondaryButton>
        <PrimaryButton type="submit" :disabled="form.processing">Save Changes</PrimaryButton>
      </div>
    </form>
  </AppLayout>
</template>
