<!-- ponytail: Employee Reimbursements Index — claims list, submission, and review workflow. -->
<script setup lang="ts">
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import PayrollSubNav from '@/Components/payroll/PayrollSubNav.vue'

interface Claim {
  id: number
  claim_date: string
  amount: string
  description?: string
  status: string
  employee: { id: number; employee_no: string; full_name: string }
  category: { name: string }
  reviewer?: { name: string }
}

const props = defineProps<{
  claims: { data: Claim[]; total: number }
  categories: Array<{ id: number; name: string }>
  employees: Array<{ id: number; employee_no: string; full_name: string }>
  filters: { status?: string }
}>()

const form = useForm({
  employee_id: '',
  reimbursement_category_id: '',
  claim_date: new Date().toISOString().split('T')[0],
  amount: 0,
  description: '',
})

const showModal = ref(false)

const submit = () => {
  form.post(route('payroll.reimbursements.store'), {
    onSuccess: () => {
      showModal.value = false
      form.reset()
    },
  })
}

const reviewClaim = (id: number, status: 'approved' | 'rejected') => {
  router.patch(route('payroll.reimbursements.review', id), { status })
}

const statusVariant = (st: string) => {
  switch (st) {
    case 'paid':
    case 'approved':
      return 'success'
    case 'pending':
      return 'warning'
    case 'rejected':
      return 'danger'
    default:
      return 'neutral'
  }
}
</script>

<template>
  <AppLayout title="Reimbursements">
    <PageHeader title="Reimbursements" subtitle="Employee expense claims, approvals, and payroll inclusion.">
      <template #actions>
        <PrimaryButton @click="showModal = true">+ Submit Claim</PrimaryButton>
      </template>
    </PageHeader>

    <div class="space-y-6">
      <PayrollSubNav active="reimbursements" />

      <Panel>
        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-border text-left text-sm">
            <thead class="bg-surface-sunken text-xs font-medium text-ink-500 uppercase">
              <tr>
                <th class="px-4 py-3">Employee</th>
                <th class="px-4 py-3">Category</th>
                <th class="px-4 py-3">Claim Date</th>
                <th class="px-4 py-3">Amount</th>
                <th class="px-4 py-3">Description</th>
                <th class="px-4 py-3">Status</th>
                <th class="px-4 py-3 text-right">Actions</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-border">
              <tr v-if="claims.data.length === 0">
                <td colspan="7" class="p-4 text-center text-ink-500">No reimbursement claims found.</td>
              </tr>
              <tr v-for="c in claims.data" :key="c.id" class="hover:bg-surface-raised transition">
                <td class="px-4 py-3">
                  <div class="font-medium text-ink-900">{{ c.employee.full_name }}</div>
                  <div class="text-xs text-ink-500">{{ c.employee.employee_no }}</div>
                </td>
                <td class="px-4 py-3 text-ink-700">{{ c.category.name }}</td>
                <td class="px-4 py-3 text-xs">{{ c.claim_date }}</td>
                <td class="px-4 py-3 font-semibold text-ink-900">Rp {{ Number(c.amount).toLocaleString('id-ID') }}</td>
                <td class="px-4 py-3 text-ink-600 text-xs">{{ c.description || '—' }}</td>
                <td class="px-4 py-3">
                  <StatusBadge :status="c.status" :variant="statusVariant(c.status)">
                    {{ c.status }}
                  </StatusBadge>
                </td>
                <td class="px-4 py-3 text-right space-x-2">
                  <template v-if="c.status === 'pending'">
                    <button
                      type="button"
                      class="text-xs font-medium text-success hover:underline"
                      @click="reviewClaim(c.id, 'approved')"
                    >
                      Approve
                    </button>
                    <button
                      type="button"
                      class="text-xs font-medium text-danger hover:underline"
                      @click="reviewClaim(c.id, 'rejected')"
                    >
                      Reject
                    </button>
                  </template>
                  <span v-else class="text-xs text-ink-400">Reviewed</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </Panel>
    </div>

    <!-- Submit Claim Modal -->
    <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center bg-ink-900/50 p-4">
      <div class="w-full max-w-md rounded-lg bg-surface p-6 shadow-xl border border-border">
        <h3 class="text-lg font-bold text-ink-900">Submit Reimbursement Claim</h3>
        <form @submit.prevent="submit" class="mt-4 space-y-4">
          <div>
            <label class="block text-xs font-medium text-ink-700">Employee *</label>
            <select
              v-model="form.employee_id"
              required
              class="mt-1 block w-full rounded-md border-border bg-surface text-sm text-ink-900 shadow-sm focus:border-accent focus:ring-accent"
            >
              <option value="" disabled>-- Select Employee --</option>
              <option v-for="e in employees" :key="e.id" :value="e.id">{{ e.employee_no }} - {{ e.full_name }}</option>
            </select>
          </div>
          <div>
            <label class="block text-xs font-medium text-ink-700">Category *</label>
            <select
              v-model="form.reimbursement_category_id"
              required
              class="mt-1 block w-full rounded-md border-border bg-surface text-sm text-ink-900 shadow-sm focus:border-accent focus:ring-accent"
            >
              <option value="" disabled>-- Select Category --</option>
              <option v-for="cat in categories" :key="cat.id" :value="cat.id">{{ cat.name }}</option>
            </select>
          </div>
          <div>
            <label class="block text-xs font-medium text-ink-700">Claim Date *</label>
            <input
              v-model="form.claim_date"
              type="date"
              required
              class="mt-1 block w-full rounded-md border-border bg-surface text-sm text-ink-900 shadow-sm focus:border-accent focus:ring-accent"
            />
          </div>
          <div>
            <label class="block text-xs font-medium text-ink-700">Amount (IDR) *</label>
            <input
              v-model.number="form.amount"
              type="number"
              min="1"
              required
              class="mt-1 block w-full rounded-md border-border bg-surface text-sm text-ink-900 shadow-sm focus:border-accent focus:ring-accent"
            />
          </div>
          <div>
            <label class="block text-xs font-medium text-ink-700">Description</label>
            <textarea
              v-model="form.description"
              rows="2"
              class="mt-1 block w-full rounded-md border-border bg-surface text-sm text-ink-900 shadow-sm focus:border-accent focus:ring-accent"
            ></textarea>
          </div>
          <div class="flex justify-end space-x-3 pt-2">
            <SecondaryButton type="button" @click="showModal = false">Cancel</SecondaryButton>
            <PrimaryButton :disabled="form.processing">Submit Claim</PrimaryButton>
          </div>
        </form>
      </div>
    </div>
  </AppLayout>
</template>
