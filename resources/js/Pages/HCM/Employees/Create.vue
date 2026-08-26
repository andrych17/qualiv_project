<!-- ponytail: Minimal Hire Onboarding form — creates Employee + Contract + Position in one step. -->
<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import FormCurrencyInput from '@/Components/forms/FormCurrencyInput.vue'
import FormNumberInput from '@/Components/forms/FormNumberInput.vue'

const props = defineProps<{
  positions: Array<{ id: number; job?: { title: string }; org_unit?: { name: string } }>
  orgUnits: Array<{ id: number; name: string }>
}>()

const form = useForm({
  employee_no: '',
  full_name: '',
  date_of_birth: '',
  gender: 'male',
  nik: '',
  npwp: '',
  bpjs_kesehatan_no: '',
  bpjs_ketenagakerjaan_no: '',
  address: '',
  marital_status: 'single',
  dependents_count: 0,
  religion: 'Islam',
  hire_date: new Date().toISOString().split('T')[0],
  position_id: null as number | null,
  bank_name: 'Bank BCA',
  bank_account_no: '',
  bank_account_holder_name: '',
  // Contract initial
  contract_type: 'PKWTT',
  contract_end_date: '',
  base_salary: 10000000,
  work_location: 'HQ Jakarta',
  probation_end_date: '',
})

const submit = () => {
  form.post(route('hcm.employees.store'))
}
</script>

<template>
  <AppLayout title="New Hire Onboarding">
    <PageHeader title="New Hire" subtitle="Create employee profile and initial employment contract." />

    <form @submit.prevent="submit" class="mt-6 max-w-4xl space-y-6">
      <!-- Identity & Master Information -->
      <Panel title="1. Personal & Statutory Identity">
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
              label="Employee Number (Leave blank to auto-generate)"
              name="employee_no"
              v-model="form.employee_no"
              :error="form.errors.employee_no"
              placeholder="e.g. EMP-0001"
            />
          </div>

          <div>
            <FormInput
              label="NIK (KTP 16 Digits)"
              name="nik"
              v-model="form.nik"
              :error="form.errors.nik"
              maxlength="16"
              placeholder="317xxxxxxxxxxxxx"
            />
          </div>
          <div>
            <FormInput
              label="NPWP"
              name="npwp"
              v-model="form.npwp"
              :error="form.errors.npwp"
              placeholder="xx.xxx.xxx.x-xxx.xxx"
            />
          </div>

          <div>
            <FormInput
              label="BPJS Kesehatan No"
              name="bpjs_kesehatan_no"
              v-model="form.bpjs_kesehatan_no"
              :error="form.errors.bpjs_kesehatan_no"
            />
          </div>
          <div>
            <FormInput
              label="BPJS Ketenagakerjaan No"
              name="bpjs_ketenagakerjaan_no"
              v-model="form.bpjs_ketenagakerjaan_no"
              :error="form.errors.bpjs_ketenagakerjaan_no"
            />
          </div>

          <div>
            <FormInput
              label="Date of Birth"
              name="date_of_birth"
              type="date"
              v-model="form.date_of_birth"
              :error="form.errors.date_of_birth"
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
              label="Dependents Count (Tanggungan)"
              name="dependents_count"
              v-model="form.dependents_count"
              :min="0"
              :max="10"
            />
          </div>

          <div>
            <FormSelect
              label="Religion (For religious holiday THR timing)"
              name="religion"
              v-model="form.religion"
              :options="[
                { label: 'Islam', value: 'Islam' },
                { label: 'Kristen', value: 'Kristen' },
                { label: 'Katolik', value: 'Katolik' },
                { label: 'Hindu', value: 'Hindu' },
                { label: 'Buddha', value: 'Buddha' },
                { label: 'Konghucu', value: 'Konghucu' },
              ]"
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

      <!-- Position Assignment -->
      <Panel title="2. Position & Organization Assignment">
        <div>
          <FormSelect
            label="Position"
            name="position_id"
            v-model="form.position_id"
            :error="form.errors.position_id"
            :options="positions.map(pos => ({ label: `${pos.job?.title ?? 'Role'} • ${pos.org_unit?.name ?? 'Unit'}`, value: pos.id }))"
            placeholder="Select Position…"
          />
        </div>
      </Panel>

      <!-- Initial Contract -->
      <Panel title="3. Initial Employment Contract">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div>
            <FormSelect
              label="Contract Type"
              name="contract_type"
              v-model="form.contract_type"
              :options="[
                { label: 'PKWTT (Permanent)', value: 'PKWTT' },
                { label: 'PKWT (Fixed Term)', value: 'PKWT' },
              ]"
              required
            />
          </div>
          <div v-if="form.contract_type === 'PKWT'">
            <FormInput
              label="Contract End Date"
              name="contract_end_date"
              type="date"
              v-model="form.contract_end_date"
              :error="form.errors.contract_end_date"
              required
            />
          </div>
          <div v-if="form.contract_type === 'PKWTT'">
            <FormInput
              label="Probation End Date (Max 3 months)"
              name="probation_end_date"
              type="date"
              v-model="form.probation_end_date"
              :error="form.errors.probation_end_date"
            />
          </div>

          <div>
            <FormCurrencyInput
              label="Monthly Base Salary"
              name="base_salary"
              v-model="form.base_salary"
              :error="form.errors.base_salary"
              required
            />
          </div>
          <div>
            <FormInput
              label="Work Location"
              name="work_location"
              v-model="form.work_location"
              :error="form.errors.work_location"
            />
          </div>
        </div>
      </Panel>

      <!-- Bank Details -->
      <Panel title="4. Bank Account for Payroll">
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
        <SecondaryButton :href="route('hcm.employees.index')">Cancel</SecondaryButton>
        <PrimaryButton type="submit" :disabled="form.processing">Save & Onboard Hire</PrimaryButton>
      </div>
    </form>
  </AppLayout>
</template>
