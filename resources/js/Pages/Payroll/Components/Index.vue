<!-- ponytail: Payroll Components Master — earnings, deductions, and tax/BPJS flags. -->
<script setup lang="ts">
import { ref, computed } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import PayrollSubNav from '@/Components/payroll/PayrollSubNav.vue'
import Modal from '@/Components/Modal.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import FormSwitch from '@/Components/forms/FormSwitch.vue'
import DataTable, { type FilterFieldDef, type SortState } from '@/Components/tables/DataTable.vue'

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

const search = ref('')
const filters = ref({
  type: '',
  category: '',
})
const sort = ref<SortState>(null)
const selected = ref<Array<string | number>>([])

const filterFields: FilterFieldDef[] = [
  {
    key: 'type',
    label: 'Type',
    type: 'select',
    options: [
      { label: 'Earning', value: 'earning' },
      { label: 'Deduction', value: 'deduction' },
    ],
  },
  {
    key: 'category',
    label: 'Category',
    type: 'select',
    options: [
      { label: 'Fixed', value: 'fixed' },
      { label: 'Variable Input', value: 'variable_input' },
      { label: 'Formula', value: 'formula' },
      { label: 'Statutory', value: 'statutory' },
    ],
  },
]

const columns = [
  { key: 'code', label: 'Code', sortable: true },
  { key: 'name', label: 'Component Name', sortable: true },
  { key: 'type', label: 'Type', sortable: true },
  { key: 'category', label: 'Category' },
  { key: 'is_taxable', label: 'Taxable?' },
  { key: 'is_bpjs_basis', label: 'BPJS Basis?' },
  { key: 'status', label: 'Status' },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

const filteredComponents = computed(() => {
  return props.components.filter((c) => {
    if (search.value) {
      const q = search.value.toLowerCase()
      if (!c.name.toLowerCase().includes(q) && !c.code.toLowerCase().includes(q)) {
        return false
      }
    }
    if (filters.value.type && c.type !== filters.value.type) {
      return false
    }
    if (filters.value.category && c.category !== filters.value.category) {
      return false
    }
    return true
  })
})

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
  form.is_taxable = Boolean(comp.is_taxable)
  form.is_bpjs_basis = Boolean(comp.is_bpjs_basis)
  form.gl_account_code = comp.gl_account_code || ''
  form.is_active = Boolean(comp.is_active)
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
        <PrimaryButton type="button" @click="openCreate">+ Add Component</PrimaryButton>
      </template>
    </PageHeader>

    <div class="mt-4">
      <PayrollSubNav active="components" />
    </div>

    <div class="mt-6">
      <DataTable
        :columns="columns"
        :items="filteredComponents"
        v-model:sort="sort"
        v-model:selected="selected"
        v-model:search="search"
        v-model:filters="filters"
        sticky-header
        storage-key="payroll.components"
        search-placeholder="Search components…"
        :filter-fields="filterFields"
        export-filename="payroll-components"
        status-rail-key="is_active"
        empty-title="No payroll components found"
        empty-description="Create salary components, allowances, or deductions."
      >
        <template #cell-code="{ item }">
          <span class="font-mono font-medium text-ink-900">{{ (item as Component).code }}</span>
        </template>

        <template #cell-name="{ item }">
          <span class="font-semibold text-ink-900">{{ (item as Component).name }}</span>
        </template>

        <template #cell-type="{ item }">
          <span
            class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium capitalize"
            :class="(item as Component).type === 'earning' ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700'"
          >
            {{ (item as Component).type }}
          </span>
        </template>

        <template #cell-category="{ item }">
          <span class="text-xs capitalize text-ink-700">{{ (item as Component).category.replace('_', ' ') }}</span>
        </template>

        <template #cell-is_taxable="{ item }">
          <span class="text-xs font-mono" :class="(item as Component).is_taxable ? 'text-emerald-700 font-semibold' : 'text-ink-400'">
            {{ (item as Component).is_taxable ? 'Yes' : 'No' }}
          </span>
        </template>

        <template #cell-is_bpjs_basis="{ item }">
          <span class="text-xs font-mono" :class="(item as Component).is_bpjs_basis ? 'text-emerald-700 font-semibold' : 'text-ink-400'">
            {{ (item as Component).is_bpjs_basis ? 'Yes' : 'No' }}
          </span>
        </template>

        <template #cell-status="{ item }">
          <StatusBadge :status="(item as Component).is_active ? 'active' : 'inactive'" />
        </template>

        <template #cell-actions="{ item }">
          <div class="flex items-center justify-end">
            <button
              v-if="!(item as Component).is_system_defined"
              type="button"
              class="text-xs font-semibold text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
              @click="openEdit(item as Component)"
            >
              Edit
            </button>
            <span v-else class="text-xs text-ink-400 font-mono">System</span>
          </div>
        </template>
      </DataTable>
    </div>

    <!-- Create/Edit Modal -->
    <Modal :show="showModal" max-width="md" @close="showModal = false">
      <div class="p-6 bg-surface-0 border border-border text-ink-900 rounded-lg">
        <h3 class="text-lg font-bold text-ink-900">{{ isEditing ? 'Edit Component' : 'New Component' }}</h3>
        <form @submit.prevent="submit" class="mt-4 space-y-4">
          <div>
            <FormInput
              label="Component Code"
              name="code"
              v-model="form.code"
              :error="form.errors.code"
              :disabled="isEditing"
              placeholder="e.g. BASIC_SALARY, MEAL_ALLOWANCE"
              required
            />
          </div>

          <div>
            <FormInput
              label="Component Name"
              name="name"
              v-model="form.name"
              :error="form.errors.name"
              placeholder="e.g. Gaji Pokok, Tunjangan Makan"
              required
            />
          </div>

          <div class="grid grid-cols-2 gap-4">
            <div>
              <FormSelect
                label="Type"
                name="type"
                v-model="form.type"
                :options="[
                  { label: 'Earning', value: 'earning' },
                  { label: 'Deduction', value: 'deduction' },
                ]"
                required
              />
            </div>
            <div>
              <FormSelect
                label="Category"
                name="category"
                v-model="form.category"
                :options="[
                  { label: 'Fixed', value: 'fixed' },
                  { label: 'Variable Input', value: 'variable_input' },
                  { label: 'Formula', value: 'formula' },
                  { label: 'Statutory', value: 'statutory' },
                ]"
                required
              />
            </div>
          </div>

          <div class="space-y-2 pt-2">
            <FormSwitch
              v-model="form.is_taxable"
              name="is_taxable"
              label="Taxable (PPh 21)"
              description="Include in monthly taxable gross calculation."
            />
            <FormSwitch
              v-model="form.is_bpjs_basis"
              name="is_bpjs_basis"
              label="BPJS Calculation Basis"
              description="Subject to BPJS Kesehatan & Ketenagakerjaan wage base."
            />
          </div>

          <div class="flex justify-end gap-3 pt-2">
            <SecondaryButton type="button" @click="showModal = false">Cancel</SecondaryButton>
            <PrimaryButton type="submit" :disabled="form.processing">Save Component</PrimaryButton>
          </div>
        </form>
      </div>
    </Modal>
  </AppLayout>
</template>
