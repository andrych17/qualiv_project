<!-- ponytail: Minimal Hire Onboarding form — creates Employee + Contract + Position in one step. -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'

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
  position_id: '',
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

    <form @submit.prevent="submit" class="max-w-4xl space-y-6">
      <!-- Identity & Master Information -->
      <Panel title="1. Personal & Statutory Identity">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div>
            <label class="block text-xs font-medium text-ink-700">Full Name *</label>
            <input
              v-model="form.full_name"
              type="text"
              required
              class="mt-1 block w-full rounded-md border-border bg-surface text-sm text-ink-900 shadow-sm focus:border-accent focus:ring-accent"
            />
            <span v-if="form.errors.full_name" class="text-xs text-danger">{{ form.errors.full_name }}</span>
          </div>
          <div>
            <label class="block text-xs font-medium text-ink-700">Employee Number (Leave blank to auto-generate)</label>
            <input
              v-model="form.employee_no"
              type="text"
              placeholder="e.g. EMP-0001"
              class="mt-1 block w-full rounded-md border-border bg-surface text-sm text-ink-900 shadow-sm focus:border-accent focus:ring-accent"
            />
            <span v-if="form.errors.employee_no" class="text-xs text-danger">{{ form.errors.employee_no }}</span>
          </div>

          <div>
            <label class="block text-xs font-medium text-ink-700">NIK (KTP 16 Digits)</label>
            <input
              v-model="form.nik"
              type="text"
              maxlength="16"
              placeholder="317xxxxxxxxxxxxx"
              class="mt-1 block w-full rounded-md border-border bg-surface text-sm text-ink-900 shadow-sm focus:border-accent focus:ring-accent"
            />
            <span v-if="form.errors.nik" class="text-xs text-danger">{{ form.errors.nik }}</span>
          </div>
          <div>
            <label class="block text-xs font-medium text-ink-700">NPWP</label>
            <input
              v-model="form.npwp"
              type="text"
              placeholder="xx.xxx.xxx.x-xxx.xxx"
              class="mt-1 block w-full rounded-md border-border bg-surface text-sm text-ink-900 shadow-sm focus:border-accent focus:ring-accent"
            />
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

          <div>
            <label class="block text-xs font-medium text-ink-700">Date of Birth</label>
            <input
              v-model="form.date_of_birth"
              type="date"
              class="mt-1 block w-full rounded-md border-border bg-surface text-sm text-ink-900 shadow-sm focus:border-accent focus:ring-accent"
            />
          </div>
          <div>
            <label class="block text-xs font-medium text-ink-700">Gender</label>
            <select
              v-model="form.gender"
              class="mt-1 block w-full rounded-md border-border bg-surface text-sm text-ink-900 shadow-sm focus:border-accent focus:ring-accent"
            >
              <option value="male">Male</option>
              <option value="female">Female</option>
              <option value="other">Other</option>
            </select>
          </div>

          <div>
            <label class="block text-xs font-medium text-ink-700">Marital Status</label>
            <select
              v-model="form.marital_status"
              class="mt-1 block w-full rounded-md border-border bg-surface text-sm text-ink-900 shadow-sm focus:border-accent focus:ring-accent"
            >
              <option value="single">Single</option>
              <option value="married">Married</option>
              <option value="divorced">Divorced</option>
              <option value="widowed">Widowed</option>
            </select>
          </div>
          <div>
            <label class="block text-xs font-medium text-ink-700">Dependents Count (Tanggungan)</label>
            <input
              v-model.number="form.dependents_count"
              type="number"
              min="0"
              max="10"
              class="mt-1 block w-full rounded-md border-border bg-surface text-sm text-ink-900 shadow-sm focus:border-accent focus:ring-accent"
            />
          </div>

          <div>
            <label class="block text-xs font-medium text-ink-700">Religion (For religious holiday THR timing)</label>
            <select
              v-model="form.religion"
              class="mt-1 block w-full rounded-md border-border bg-surface text-sm text-ink-900 shadow-sm focus:border-accent focus:ring-accent"
            >
              <option value="Islam">Islam</option>
              <option value="Kristen">Kristen</option>
              <option value="Katolik">Katolik</option>
              <option value="Hindu">Hindu</option>
              <option value="Buddha">Buddha</option>
              <option value="Konghucu">Konghucu</option>
            </select>
          </div>
          <div>
            <label class="block text-xs font-medium text-ink-700">Hire Date *</label>
            <input
              v-model="form.hire_date"
              type="date"
              required
              class="mt-1 block w-full rounded-md border-border bg-surface text-sm text-ink-900 shadow-sm focus:border-accent focus:ring-accent"
            />
          </div>
        </div>
      </Panel>

      <!-- Position Assignment -->
      <Panel title="2. Position & Organization Assignment">
        <div>
          <label class="block text-xs font-medium text-ink-700">Position</label>
          <select
            v-model="form.position_id"
            class="mt-1 block w-full rounded-md border-border bg-surface text-sm text-ink-900 shadow-sm focus:border-accent focus:ring-accent"
          >
            <option value="">-- Select Position --</option>
            <option v-for="pos in positions" :key="pos.id" :value="pos.id">
              {{ pos.job?.title }} &bull; {{ pos.org_unit?.name }}
            </option>
          </select>
        </div>
      </Panel>

      <!-- Initial Contract -->
      <Panel title="3. Initial Employment Contract">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div>
            <label class="block text-xs font-medium text-ink-700">Contract Type *</label>
            <select
              v-model="form.contract_type"
              class="mt-1 block w-full rounded-md border-border bg-surface text-sm text-ink-900 shadow-sm focus:border-accent focus:ring-accent"
            >
              <option value="PKWTT">PKWTT (Permanent)</option>
              <option value="PKWT">PKWT (Fixed Term)</option>
            </select>
          </div>
          <div v-if="form.contract_type === 'PKWT'">
            <label class="block text-xs font-medium text-ink-700">Contract End Date *</label>
            <input
              v-model="form.contract_end_date"
              type="date"
              required
              class="mt-1 block w-full rounded-md border-border bg-surface text-sm text-ink-900 shadow-sm focus:border-accent focus:ring-accent"
            />
          </div>
          <div v-if="form.contract_type === 'PKWTT'">
            <label class="block text-xs font-medium text-ink-700">Probation End Date (Max 3 months)</label>
            <input
              v-model="form.probation_end_date"
              type="date"
              class="mt-1 block w-full rounded-md border-border bg-surface text-sm text-ink-900 shadow-sm focus:border-accent focus:ring-accent"
            />
          </div>

          <div>
            <label class="block text-xs font-medium text-ink-700">Monthly Base Salary (IDR) *</label>
            <input
              v-model.number="form.base_salary"
              type="number"
              min="0"
              required
              class="mt-1 block w-full rounded-md border-border bg-surface text-sm text-ink-900 shadow-sm focus:border-accent focus:ring-accent"
            />
          </div>
          <div>
            <label class="block text-xs font-medium text-ink-700">Work Location</label>
            <input
              v-model="form.work_location"
              type="text"
              class="mt-1 block w-full rounded-md border-border bg-surface text-sm text-ink-900 shadow-sm focus:border-accent focus:ring-accent"
            />
          </div>
        </div>
      </Panel>

      <!-- Bank Details -->
      <Panel title="4. Bank Account for Payroll">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
          <div>
            <label class="block text-xs font-medium text-ink-700">Bank Name</label>
            <input
              v-model="form.bank_name"
              type="text"
              class="mt-1 block w-full rounded-md border-border bg-surface text-sm text-ink-900 shadow-sm focus:border-accent focus:ring-accent"
            />
          </div>
          <div>
            <label class="block text-xs font-medium text-ink-700">Bank Account Number</label>
            <input
              v-model="form.bank_account_no"
              type="text"
              class="mt-1 block w-full rounded-md border-border bg-surface text-sm text-ink-900 shadow-sm focus:border-accent focus:ring-accent"
            />
          </div>
          <div>
            <label class="block text-xs font-medium text-ink-700">Account Holder Name</label>
            <input
              v-model="form.bank_account_holder_name"
              type="text"
              class="mt-1 block w-full rounded-md border-border bg-surface text-sm text-ink-900 shadow-sm focus:border-accent focus:ring-accent"
            />
          </div>
        </div>
      </Panel>

      <div class="flex items-center justify-end space-x-3">
        <Link :href="route('hcm.employees.index')">
          <SecondaryButton>Cancel</SecondaryButton>
        </Link>
        <PrimaryButton :disabled="form.processing">Save & Onboard Hire</PrimaryButton>
      </div>
    </form>
  </AppLayout>
</template>
