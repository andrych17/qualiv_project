<!-- ponytail: Salary Structures Index — grade linking and component packages. -->
<script setup lang="ts">
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import PayrollSubNav from '@/Components/payroll/PayrollSubNav.vue'
import Modal from '@/Components/Modal.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import FormTextarea from '@/Components/forms/FormTextarea.vue'
import FormCurrencyInput from '@/Components/forms/FormCurrencyInput.vue'
import EmptyState from '@/Components/feedback/EmptyState.vue'
import { formatCurrency } from '@/Utils/formatters'

interface Structure {
  id: number
  name: string
  description?: string
  grade?: { name: string }
  components: Array<{
    id: number
    default_amount: string
    formula_expression?: string
    payroll_component: { code: string; name: string; type: string }
  }>
}

const props = defineProps<{
  structures: Structure[]
  grades: Array<{ id: number; name: string }>
  components: Array<{ id: number; code: string; name: string }>
}>()

const form = useForm({
  name: '',
  grade_id: null as number | null,
  description: '',
})

const showCreateModal = ref(false)
const showAttachModal = ref(false)
const selectedStructure = ref<Structure | null>(null)

const attachForm = useForm({
  payroll_component_id: null as number | null,
  default_amount: 0,
  formula_expression: '',
})

const openAttach = (s: Structure) => {
  selectedStructure.value = s
  attachForm.reset()
  showAttachModal.value = true
}

const submitCreate = () => {
  form.post(route('payroll.structures.store'), {
    onSuccess: () => {
      showCreateModal.value = false
      form.reset()
    },
  })
}

const submitAttach = () => {
  if (!selectedStructure.value) return
  attachForm.post(route('payroll.structures.attachComponent', selectedStructure.value.id), {
    onSuccess: () => {
      showAttachModal.value = false
      attachForm.reset()
    },
  })
}
</script>

<template>
  <AppLayout title="Salary Structures">
    <PageHeader title="Salary Structures" subtitle="Define component packages and default salary packages per grade.">
      <template #actions>
        <PrimaryButton type="button" @click="showCreateModal = true">+ Add Salary Structure</PrimaryButton>
      </template>
    </PageHeader>

    <div class="mt-4">
      <PayrollSubNav active="structures" />
    </div>

    <div class="mt-6">
      <div v-if="structures.length === 0" class="rounded-lg border border-border bg-surface-0 p-8">
        <EmptyState
          title="No salary structures defined"
          description="Create salary structures linked to employment grades."
        />
      </div>

      <div v-else class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <Panel
          v-for="s in structures"
          :key="s.id"
          :title="s.name"
        >
          <template #actions>
            <button
              type="button"
              class="text-xs font-semibold text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
              @click="openAttach(s)"
            >
              + Attach Component
            </button>
          </template>

          <div class="space-y-3">
            <div class="text-xs text-ink-500">
              Grade: <span class="font-medium text-ink-800">{{ s.grade?.name ?? 'General / None' }}</span>
              <span v-if="s.description"> &bull; {{ s.description }}</span>
            </div>

            <div class="border-t border-border pt-2">
              <div class="text-xs font-semibold text-ink-700 mb-2">Package Components:</div>
              <div v-if="s.components.length === 0" class="text-xs text-ink-400 py-2">No components attached yet.</div>
              <ul v-else class="divide-y divide-border text-xs">
                <li
                  v-for="comp in s.components"
                  :key="comp.id"
                  class="flex items-center justify-between py-2"
                >
                  <span class="font-medium text-ink-900">{{ comp.payroll_component.name }} ({{ comp.payroll_component.code }})</span>
                  <span class="font-mono text-ink-900 font-semibold">{{ formatCurrency(Number(comp.default_amount)) }}</span>
                </li>
              </ul>
            </div>
          </div>
        </Panel>
      </div>
    </div>

    <!-- Create Structure Modal -->
    <Modal :show="showCreateModal" max-width="md" @close="showCreateModal = false">
      <div class="p-6 bg-white rounded-lg">
        <h3 class="text-lg font-bold text-ink-900">New Salary Structure</h3>
        <form @submit.prevent="submitCreate" class="mt-4 space-y-4">
          <div>
            <FormInput
              label="Structure Name"
              name="name"
              v-model="form.name"
              :error="form.errors.name"
              placeholder="e.g. Senior Associate Package, Standard Staff"
              required
            />
          </div>

          <div>
            <FormSelect
              label="Linked Grade (Optional)"
              name="grade_id"
              v-model="form.grade_id"
              :error="form.errors.grade_id"
              :options="grades.map(g => ({ label: g.name, value: g.id }))"
              placeholder="General / No Grade"
            />
          </div>

          <div>
            <FormTextarea
              label="Description (Optional)"
              name="description"
              v-model="form.description"
              placeholder="Notes on structure applicability…"
            />
          </div>

          <div class="flex justify-end gap-3 pt-2">
            <SecondaryButton type="button" @click="showCreateModal = false">Cancel</SecondaryButton>
            <PrimaryButton type="submit" :disabled="form.processing">Create Structure</PrimaryButton>
          </div>
        </form>
      </div>
    </Modal>

    <!-- Attach Component Modal -->
    <Modal :show="showAttachModal" max-width="md" @close="showAttachModal = false">
      <div class="p-6 bg-white rounded-lg">
        <h3 class="text-lg font-bold text-ink-900">Attach Component to {{ selectedStructure?.name }}</h3>
        <form @submit.prevent="submitAttach" class="mt-4 space-y-4">
          <div>
            <FormSelect
              label="Payroll Component"
              name="payroll_component_id"
              v-model="attachForm.payroll_component_id"
              :options="components.map(c => ({ label: `${c.name} (${c.code})`, value: c.id }))"
              placeholder="Select Component…"
              required
            />
          </div>

          <div>
            <FormCurrencyInput
              label="Default Amount (IDR)"
              name="default_amount"
              v-model="attachForm.default_amount"
            />
          </div>

          <div>
            <FormInput
              label="Formula Expression (Optional)"
              name="formula_expression"
              v-model="attachForm.formula_expression"
              placeholder="e.g. BASIC * 0.1"
            />
          </div>

          <div class="flex justify-end gap-3 pt-2">
            <SecondaryButton type="button" @click="showAttachModal = false">Cancel</SecondaryButton>
            <PrimaryButton type="submit" :disabled="attachForm.processing">Attach Component</PrimaryButton>
          </div>
        </form>
      </div>
    </Modal>
  </AppLayout>
</template>
