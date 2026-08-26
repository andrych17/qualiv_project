<!-- ponytail: Employment Contracts Index — list contracts, renewal workflows, and compliance expiry monitoring. -->
<script setup lang="ts">
import { ref } from 'vue'
import { router, useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import HcmSubNav from '@/Components/hcm/HcmSubNav.vue'
import Modal from '@/Components/Modal.vue'

interface Contract {
  id: number
  employee_id: number
  contract_type: string
  start_date: string
  end_date?: string
  base_salary: string
  work_location?: string
  probation_end_date?: string
  status: string
  employee: {
    id: number
    employee_no: string
    full_name: string
    position?: { job?: { title: string }; org_unit?: { name: string } }
  }
}

const props = defineProps<{
  contracts: { data: Contract[]; total: number }
  expiringContracts: Contract[]
  filters: { search?: string; contract_type?: string; status?: string }
}>()

const renewForm = useForm({
  contract_type: 'PKWT',
  start_date: new Date().toISOString().split('T')[0],
  end_date: '',
  base_salary: 0,
  work_location: '',
  probation_end_date: '',
})

const selectedContract = ref<Contract | null>(null)
const showRenewModal = ref(false)

const openRenew = (c: Contract) => {
  selectedContract.value = c
  renewForm.contract_type = c.contract_type
  renewForm.start_date = c.end_date || new Date().toISOString().split('T')[0]
  renewForm.end_date = ''
  renewForm.base_salary = Number(c.base_salary)
  renewForm.work_location = c.work_location || ''
  renewForm.probation_end_date = ''
  showRenewModal.value = true
}

const submitRenew = () => {
  if (!selectedContract.value) return
  renewForm.post(route('hcm.contracts.renew', selectedContract.value.id), {
    onSuccess: () => {
      showRenewModal.value = false
    },
  })
}

const terminateContract = (c: Contract) => {
  if (confirm(`Terminate contract for ${c.employee.full_name}?`)) {
    router.post(route('hcm.contracts.terminate', c.id))
  }
}
</script>

<template>
  <AppLayout title="Employment Contracts">
    <PageHeader title="Employment Contracts" subtitle="Track PKWT/PKWTT contracts, compliance durations, and renewals." />

    <div class="space-y-6">
      <HcmSubNav active="contracts" />

      <!-- Expiring warning alert -->
      <div v-if="expiringContracts.length > 0" class="rounded-lg border border-warning/40 bg-warning/10 p-4 text-sm text-ink-900">
        <div class="font-bold flex items-center gap-2">
          <span>⚠️</span> {{ expiringContracts.length }} Contract(s) Expiring Soon (Next 60 Days)
        </div>
        <div class="mt-2 flex flex-wrap gap-2">
          <span
            v-for="c in expiringContracts"
            :key="c.id"
            class="rounded bg-surface px-2 py-1 text-xs border border-border"
          >
            {{ c.employee.full_name }} (Ends {{ c.end_date }})
          </span>
        </div>
      </div>

      <Panel>
        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-border text-left text-sm">
            <thead class="bg-surface-sunken text-xs font-medium text-ink-500 uppercase">
              <tr>
                <th class="px-4 py-3">Employee</th>
                <th class="px-4 py-3">Contract Type</th>
                <th class="px-4 py-3">Start Date</th>
                <th class="px-4 py-3">End Date</th>
                <th class="px-4 py-3">Base Salary</th>
                <th class="px-4 py-3">Status</th>
                <th class="px-4 py-3 text-right">Actions</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-border">
              <tr v-if="contracts.data.length === 0">
                <td colspan="7" class="p-4 text-center text-ink-500">No contracts found.</td>
              </tr>
              <tr v-for="c in contracts.data" :key="c.id" class="hover:bg-surface-raised transition">
                <td class="px-4 py-3">
                  <Link :href="route('hcm.employees.show', c.employee.id)" class="font-medium text-ink-900 hover:text-accent">
                    {{ c.employee.full_name }}
                  </Link>
                  <div class="text-xs text-ink-500">{{ c.employee.employee_no }}</div>
                </td>
                <td class="px-4 py-3 font-medium">{{ c.contract_type }}</td>
                <td class="px-4 py-3">{{ c.start_date }}</td>
                <td class="px-4 py-3">{{ c.end_date || 'Permanent (PKWTT)' }}</td>
                <td class="px-4 py-3">Rp {{ Number(c.base_salary).toLocaleString('id-ID') }}</td>
                <td class="px-4 py-3">
                  <StatusBadge :status="c.status" :variant="c.status === 'active' ? 'success' : (c.status === 'expired' ? 'warning' : 'neutral')">
                    {{ c.status }}
                  </StatusBadge>
                </td>
                <td class="px-4 py-3 text-right space-x-2">
                  <button
                    v-if="c.status === 'active'"
                    type="button"
                    class="text-xs font-medium text-accent hover:underline"
                    @click="openRenew(c)"
                  >
                    Renew
                  </button>
                  <button
                    v-if="c.status === 'active'"
                    type="button"
                    class="text-xs font-medium text-danger hover:underline"
                    @click="terminateContract(c)"
                  >
                    Terminate
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </Panel>
    </div>

    <!-- Renew Modal -->
    <Modal :show="showRenewModal" max-width="md" @close="showRenewModal = false">
      <div class="p-6 bg-white rounded-lg">
        <h3 class="text-lg font-bold text-ink-900">Renew Employment Contract</h3>
        <p class="mt-1 text-sm text-ink-600">Employee: {{ selectedContract?.employee.full_name }}</p>

        <form @submit.prevent="submitRenew" class="mt-4 space-y-4">
          <div>
            <label class="block text-xs font-medium text-ink-700">Contract Type *</label>
            <select
              v-model="renewForm.contract_type"
              class="mt-1 block w-full rounded-md border-border bg-white text-sm text-ink-900 shadow-sm focus:border-accent focus:ring-accent"
            >
              <option value="PKWT">PKWT (Fixed Term)</option>
              <option value="PKWTT">PKWTT (Permanent Conversion)</option>
            </select>
          </div>
          <div>
            <label class="block text-xs font-medium text-ink-700">Start Date *</label>
            <input
              v-model="renewForm.start_date"
              type="date"
              required
              class="mt-1 block w-full rounded-md border-border bg-white text-sm text-ink-900 shadow-sm focus:border-accent focus:ring-accent"
            />
          </div>
          <div v-if="renewForm.contract_type === 'PKWT'">
            <label class="block text-xs font-medium text-ink-700">End Date *</label>
            <input
              v-model="renewForm.end_date"
              type="date"
              required
              class="mt-1 block w-full rounded-md border-border bg-white text-sm text-ink-900 shadow-sm focus:border-accent focus:ring-accent"
            />
          </div>
          <div>
            <label class="block text-xs font-medium text-ink-700">Base Salary (IDR) *</label>
            <input
              v-model.number="renewForm.base_salary"
              type="number"
              min="0"
              required
              class="mt-1 block w-full rounded-md border-border bg-white text-sm text-ink-900 shadow-sm focus:border-accent focus:ring-accent"
            />
          </div>

          <div class="flex justify-end space-x-3 pt-2">
            <SecondaryButton type="button" @click="showRenewModal = false">Cancel</SecondaryButton>
            <PrimaryButton :disabled="renewForm.processing">Renew Contract</PrimaryButton>
          </div>
        </form>
      </div>
    </Modal>
  </AppLayout>
</template>
