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
  grade_id: '',
  description: '',
})

const showCreateModal = ref(false)
const showAttachModal = ref(false)
const selectedStructure = ref<Structure | null>(null)

const attachForm = useForm({
  payroll_component_id: '',
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
        <PrimaryButton @click="showCreateModal = true">+ Add Salary Structure</PrimaryButton>
      </template>
    </PageHeader>

    <div class="space-y-6">
      <PayrollSubNav active="structures" />

      <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <div v-if="structures.length === 0" class="col-span-2 text-center p-8 text-ink-500">
          No salary structures defined.
        </div>
        <Panel
          v-for="s in structures"
          :key="s.id"
          :title="s.name"
        >
          <template #actions>
            <button
              type="button"
              class="text-xs font-semibold text-accent hover:underline"
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
              <div v-if="s.components.length === 0" class="text-xs text-ink-400">No components attached yet.</div>
              <ul v-else class="divide-y divide-border text-xs">
                <li
                  v-for="comp in s.components"
                  :key="comp.id"
                  class="flex items-center justify-between py-1.5"
                >
                  <span class="font-medium text-ink-900">{{ comp.payroll_component.name }} ({{ comp.payroll_component.code }})</span>
                  <span class="text-ink-700 font-semibold">Rp {{ Number(comp.default_amount).toLocaleString('id-ID') }}</span>
                </li>
              </ul>
            </div>
          </div>
        </Panel>
      </div>
    </div>

    <!-- Create Structure Modal -->
    <div v-if="showCreateModal" class="fixed inset-0 z-50 flex items-center justify-center bg-ink-900/50 p-4">
      <div class="w-full max-w-md rounded-lg bg-surface p-6 shadow-xl border border-border">
        <h3 class="text-lg font-bold text-ink-900">New Salary Structure</h3>
        <form @submit.prevent="submitCreate" class="mt-4 space-y-4">
          <div>
            <label class="block text-xs font-medium text-ink-700">Structure Name *</label>
            <input
              v-model="form.name"
              type="text"
              required
              class="mt-1 block w-full rounded-md border-border bg-surface text-sm text-ink-900 shadow-sm focus:border-accent focus:ring-accent"
            />
          </div>
          <div>
            <label class="block text-xs font-medium text-ink-700">Grade</label>
            <select
              v-model="form.grade_id"
              class="mt-1 block w-full rounded-md border-border bg-surface text-sm text-ink-900 shadow-sm focus:border-accent focus:ring-accent"
            >
              <option value="">-- No Grade --</option>
              <option v-for="g in grades" :key="g.id" :value="g.id">{{ g.name }}</option>
            </select>
          </div>
          <div>
            <label class="block text-xs font-medium text-ink-700">Description</label>
            <input
              v-model="form.description"
              type="text"
              class="mt-1 block w-full rounded-md border-border bg-surface text-sm text-ink-900 shadow-sm focus:border-accent focus:ring-accent"
            />
          </div>
          <div class="flex justify-end space-x-3 pt-2">
            <SecondaryButton type="button" @click="showCreateModal = false">Cancel</SecondaryButton>
            <PrimaryButton :disabled="form.processing">Save Structure</PrimaryButton>
          </div>
        </form>
      </div>
    </div>

    <!-- Attach Component Modal -->
    <div v-if="showAttachModal" class="fixed inset-0 z-50 flex items-center justify-center bg-ink-900/50 p-4">
      <div class="w-full max-w-md rounded-lg bg-surface p-6 shadow-xl border border-border">
        <h3 class="text-lg font-bold text-ink-900">Attach Component to {{ selectedStructure?.name }}</h3>
        <form @submit.prevent="submitAttach" class="mt-4 space-y-4">
          <div>
            <label class="block text-xs font-medium text-ink-700">Component *</label>
            <select
              v-model="attachForm.payroll_component_id"
              required
              class="mt-1 block w-full rounded-md border-border bg-surface text-sm text-ink-900 shadow-sm focus:border-accent focus:ring-accent"
            >
              <option value="" disabled>-- Select Component --</option>
              <option v-for="c in components" :key="c.id" :value="c.id">{{ c.name }} ({{ c.code }})</option>
            </select>
          </div>
          <div>
            <label class="block text-xs font-medium text-ink-700">Default Amount (IDR)</label>
            <input
              v-model.number="attachForm.default_amount"
              type="number"
              min="0"
              class="mt-1 block w-full rounded-md border-border bg-surface text-sm text-ink-900 shadow-sm focus:border-accent focus:ring-accent"
            />
          </div>
          <div class="flex justify-end space-x-3 pt-2">
            <SecondaryButton type="button" @click="showAttachModal = false">Cancel</SecondaryButton>
            <PrimaryButton :disabled="attachForm.processing">Attach</PrimaryButton>
          </div>
        </form>
      </div>
    </div>
  </AppLayout>
</template>
