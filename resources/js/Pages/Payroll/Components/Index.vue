<!-- ponytail: Payroll Components Master — earnings, deductions, and tax/BPJS flags. -->
<script setup lang="ts">
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import PayrollSubNav from '@/Components/payroll/PayrollSubNav.vue'

interface Component {
  id: number
  code: string
  name: string
  type: string
  category: string
  calculation_basis: string
  is_taxable: boolean
  is_bpjs_basis: boolean
  gl_account_code?: string
  is_system_defined: boolean
  is_active: boolean
}

const props = defineProps<{
  components: Component[]
}>()

const form = useForm({
  id: null as number | null,
  code: '',
  name: '',
  type: 'earning',
  category: 'fixed',
  calculation_basis: 'flat',
  is_taxable: true,
  is_bpjs_basis: true,
  gl_account_code: '',
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

const openEdit = (comp: Component) => {
  form.id = comp.id
  form.code = comp.code
  form.name = comp.name
  form.type = comp.type
  form.category = comp.category
  form.calculation_basis = comp.calculation_basis
  form.is_taxable = comp.is_taxable
  form.is_bpjs_basis = comp.is_bpjs_basis
  form.gl_account_code = comp.gl_account_code || ''
  form.is_active = comp.is_active
  isEditing.value = true
  showModal.value = true
}

const submit = () => {
  if (isEditing.value && form.id) {
    form.put(route('payroll.components.update', form.id), {
      onSuccess: () => {
        showModal.value = false
      },
    })
  } else {
    form.post(route('payroll.components.store'), {
      onSuccess: () => {
        showModal.value = false
      },
    })
  }
}
</script>

<template>
  <AppLayout title="Payroll Components">
    <PageHeader title="Payroll Components" subtitle="Master catalog of earnings, allowances, statutory deductions, and tax flags.">
      <template #actions>
        <PrimaryButton @click="openCreate">+ Add Component</PrimaryButton>
      </template>
    </PageHeader>

    <div class="space-y-6">
      <PayrollSubNav active="components" />

      <Panel>
        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-border text-left text-sm">
            <thead class="bg-surface-sunken text-xs font-medium text-ink-500 uppercase">
              <tr>
                <th class="px-4 py-3">Code & Name</th>
                <th class="px-4 py-3">Type</th>
                <th class="px-4 py-3">Category</th>
                <th class="px-4 py-3">Taxable?</th>
                <th class="px-4 py-3">BPJS Basis?</th>
                <th class="px-4 py-3">Status</th>
                <th class="px-4 py-3 text-right">Actions</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-border">
              <tr v-for="c in components" :key="c.id" class="hover:bg-surface-raised transition">
                <td class="px-4 py-3">
                  <div class="font-medium text-ink-900">{{ c.name }}</div>
                  <div class="text-xs text-ink-500">{{ c.code }}</div>
                </td>
                <td class="px-4 py-3 capitalize">
                  <span
                    class="rounded px-2 py-0.5 text-xs font-medium"
                    :class="c.type === 'earning' ? 'bg-success/15 text-success' : 'bg-danger/15 text-danger'"
                  >
                    {{ c.type }}
                  </span>
                </td>
                <td class="px-4 py-3 capitalize text-xs text-ink-700">{{ c.category.replace('_', ' ') }}</td>
                <td class="px-4 py-3 text-xs">{{ c.is_taxable ? 'Yes' : 'No' }}</td>
                <td class="px-4 py-3 text-xs">{{ c.is_bpjs_basis ? 'Yes' : 'No' }}</td>
                <td class="px-4 py-3 text-xs">
                  <span :class="c.is_active ? 'text-success' : 'text-neutral'">
                    {{ c.is_active ? 'Active' : 'Inactive' }}
                  </span>
                </td>
                <td class="px-4 py-3 text-right">
                  <button
                    v-if="!c.is_system_defined"
                    type="button"
                    class="text-xs font-medium text-accent hover:underline"
                    @click="openEdit(c)"
                  >
                    Edit
                  </button>
                  <span v-else class="text-xs text-ink-400">System</span>
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
        <h3 class="text-lg font-bold text-ink-900">{{ isEditing ? 'Edit Component' : 'New Component' }}</h3>
        <form @submit.prevent="submit" class="mt-4 space-y-4">
          <div>
            <label class="block text-xs font-medium text-ink-700">Code *</label>
            <input
              v-model="form.code"
              type="text"
              :disabled="isEditing"
              required
              class="mt-1 block w-full rounded-md border-border bg-surface text-sm text-ink-900 shadow-sm focus:border-accent focus:ring-accent disabled:bg-surface-sunken"
            />
          </div>
          <div>
            <label class="block text-xs font-medium text-ink-700">Name *</label>
            <input
              v-model="form.name"
              type="text"
              required
              class="mt-1 block w-full rounded-md border-border bg-surface text-sm text-ink-900 shadow-sm focus:border-accent focus:ring-accent"
            />
          </div>
          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="block text-xs font-medium text-ink-700">Type *</label>
              <select
                v-model="form.type"
                class="mt-1 block w-full rounded-md border-border bg-surface text-sm text-ink-900 shadow-sm focus:border-accent focus:ring-accent"
              >
                <option value="earning">Earning</option>
                <option value="deduction">Deduction</option>
              </select>
            </div>
            <div>
              <label class="block text-xs font-medium text-ink-700">Category *</label>
              <select
                v-model="form.category"
                class="mt-1 block w-full rounded-md border-border bg-surface text-sm text-ink-900 shadow-sm focus:border-accent focus:ring-accent"
              >
                <option value="fixed">Fixed</option>
                <option value="variable_input">Variable Input</option>
                <option value="formula">Formula</option>
                <option value="statutory">Statutory</option>
              </select>
            </div>
          </div>
          <div class="flex items-center space-x-4 pt-2">
            <label class="flex items-center space-x-2">
              <input v-model="form.is_taxable" type="checkbox" class="rounded border-border text-accent focus:ring-accent" />
              <span class="text-xs text-ink-700">Taxable (PPh 21)</span>
            </label>
            <label class="flex items-center space-x-2">
              <input v-model="form.is_bpjs_basis" type="checkbox" class="rounded border-border text-accent focus:ring-accent" />
              <span class="text-xs text-ink-700">BPJS Basis</span>
            </label>
          </div>
          <div class="flex justify-end space-x-3 pt-2">
            <SecondaryButton type="button" @click="showModal = false">Cancel</SecondaryButton>
            <PrimaryButton :disabled="form.processing">Save Component</PrimaryButton>
          </div>
        </form>
      </div>
    </div>
  </AppLayout>
</template>
