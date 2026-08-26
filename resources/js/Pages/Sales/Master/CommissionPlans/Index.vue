<!-- Commission Plans Index (§3B / §3M) -->
<script setup lang="ts">
import { ref } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import SalesSubNav from '@/Components/sales/SalesSubNav.vue'
import SalesMasterSubNav from '@/Components/sales/SalesMasterSubNav.vue'
import Modal from '@/Components/Modal.vue'

interface PlanItem {
  id: number
  name: string
  sales_team_id: number | null
  user_id: number | null
  calc_type: 'flat' | 'tiered'
  flat_rate: number | null
  tier_threshold: number | null
  tier_base_rate: number | null
  tier_excess_rate: number | null
  is_active: boolean
  sales_team?: { name: string }
  user?: { name: string }
}

const props = defineProps<{
  plans: PlanItem[]
  teams: Array<{ id: number; name: string }>
  users: Array<{ id: number; name: string }>
}>()

const showModal = ref(false)
const editingPlan = ref<PlanItem | null>(null)

const form = useForm({
  name: '',
  sales_team_id: null as number | null,
  user_id: null as number | null,
  calc_type: 'flat' as 'flat' | 'tiered',
  flat_rate: 5 as number | null,
  tier_threshold: null as number | null,
  tier_base_rate: null as number | null,
  tier_excess_rate: null as number | null,
  is_active: true,
})

const openCreate = () => {
  editingPlan.value = null
  form.reset()
  form.calc_type = 'flat'
  form.flat_rate = 5
  form.is_active = true
  showModal.value = true
}

const openEdit = (p: PlanItem) => {
  editingPlan.value = p
  form.name = p.name
  form.sales_team_id = p.sales_team_id
  form.user_id = p.user_id
  form.calc_type = p.calc_type
  form.flat_rate = p.flat_rate ? Number(p.flat_rate) : null
  form.tier_threshold = p.tier_threshold ? Number(p.tier_threshold) : null
  form.tier_base_rate = p.tier_base_rate ? Number(p.tier_base_rate) : null
  form.tier_excess_rate = p.tier_excess_rate ? Number(p.tier_excess_rate) : null
  form.is_active = p.is_active
  showModal.value = true
}

const submit = () => {
  if (editingPlan.value) {
    form.put(route('sales.master.commission-plans.update', editingPlan.value.id), {
      onSuccess: () => { showModal.value = false },
    })
  } else {
    form.post(route('sales.master.commission-plans.store'), {
      onSuccess: () => { showModal.value = false },
    })
  }
}

const deletePlan = (id: number) => {
  if (confirm('Delete this commission plan?')) {
    router.delete(route('sales.master.commission-plans.destroy', id))
  }
}
</script>

<template>
  <AppLayout>
    <PageHeader
      title="Commission Plans"
      description="Configure flat and tiered commission rates per representative or team (§3M)."
    >
      <template #actions>
        <PrimaryButton @click="openCreate">New Commission Plan</PrimaryButton>
      </template>
    </PageHeader>

    <div class="mt-4">
      <SalesSubNav active="master" />
    </div>

    <div class="mt-4">
      <SalesMasterSubNav active="commission-plans" />
    </div>

    <div class="mt-6 rounded-lg border border-border bg-surface-0 overflow-x-auto">
      <table class="w-full text-left text-sm">
        <thead class="bg-surface-50 text-xs text-ink-500 uppercase border-b border-border">
          <tr>
            <th class="py-3 px-4">Plan Name</th>
            <th class="py-3 px-4">Scope</th>
            <th class="py-3 px-4">Calculation Type</th>
            <th class="py-3 px-4">Rate Structure</th>
            <th class="py-3 px-4">Status</th>
            <th class="py-3 px-4 text-right">Action</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-border">
          <tr v-for="p in props.plans" :key="p.id" class="hover:bg-surface-50">
            <td class="py-3 px-4 font-semibold text-ink-900">{{ p.name }}</td>
            <td class="py-3 px-4 text-xs text-ink-700">
              <span v-if="p.user">Rep: <strong>{{ p.user.name }}</strong></span>
              <span v-else-if="p.sales_team">Team: <strong>{{ p.sales_team.name }}</strong></span>
              <span v-else class="text-ink-400">Tenant-wide Default</span>
            </td>
            <td class="py-3 px-4 capitalize text-ink-900">{{ p.calc_type }}</td>
            <td class="py-3 px-4 text-xs font-mono">
              <span v-if="p.calc_type === 'flat'">{{ p.flat_rate }}% Flat</span>
              <span v-else>Base {{ p.tier_base_rate }}% / Excess {{ p.tier_excess_rate }}%</span>
            </td>
            <td class="py-3 px-4 text-xs font-semibold" :class="p.is_active ? 'text-emerald-600' : 'text-ink-400'">
              {{ p.is_active ? 'Active' : 'Inactive' }}
            </td>
            <td class="py-3 px-4 text-right space-x-2">
              <button
                type="button"
                @click="openEdit(p)"
                class="text-xs font-medium text-accent hover:underline"
              >
                Edit
              </button>
              <button
                type="button"
                @click="deletePlan(p.id)"
                class="text-xs font-medium text-rose-600 hover:underline"
              >
                Delete
              </button>
            </td>
          </tr>
          <tr v-if="props.plans.length === 0">
            <td colspan="6" class="py-8 text-center text-ink-500">No commission plans configured.</td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Plan Modal -->
    <Modal :show="showModal" max-width="md" @close="showModal = false">
      <div class="p-6 bg-white rounded-lg">
        <h3 class="text-lg font-semibold text-ink-900">{{ editingPlan ? 'Edit Commission Plan' : 'New Commission Plan' }}</h3>

        <form @submit.prevent="submit" class="mt-4 space-y-4">
          <FormInput
            label="Plan Name *"
            v-model="form.name"
            :error="form.errors.name"
            placeholder="e.g. Standard 5% Rep Plan"
            required
          />

          <div class="grid grid-cols-2 gap-3">
            <FormSelect
              label="Assign to Sales Team"
              v-model="form.sales_team_id"
              :options="props.teams.map(t => ({ value: t.id, label: t.name }))"
              placeholder="All teams"
            />
            <FormSelect
              label="Assign to Specific Rep"
              v-model="form.user_id"
              :options="props.users.map(u => ({ value: u.id, label: u.name }))"
              placeholder="All reps"
            />
          </div>

          <div>
            <label class="block text-xs font-medium text-ink-700 mb-1">Calculation Type *</label>
            <select
              v-model="form.calc_type"
              class="w-full rounded border border-border bg-white py-2 px-3 text-sm text-ink-900 focus:outline-none"
            >
              <option value="flat">Flat Percentage Rate</option>
              <option value="tiered">Tiered (Base + Excess)</option>
            </select>
          </div>

          <div v-if="form.calc_type === 'flat'">
            <FormInput
              label="Flat Commission Rate (%) *"
              type="number"
              step="any"
              min="0"
              v-model="form.flat_rate"
              :error="form.errors.flat_rate"
              required
            />
          </div>

          <div v-else class="space-y-3">
            <FormInput
              label="Tier Threshold (IDR) *"
              type="number"
              step="any"
              min="0"
              v-model="form.tier_threshold"
              placeholder="e.g. 100000000"
              required
            />
            <div class="grid grid-cols-2 gap-3">
              <FormInput
                label="Base Rate (%) *"
                type="number"
                step="any"
                min="0"
                v-model="form.tier_base_rate"
                required
              />
              <FormInput
                label="Excess Rate (%) *"
                type="number"
                step="any"
                min="0"
                v-model="form.tier_excess_rate"
                required
              />
            </div>
          </div>

          <label class="flex items-center gap-2 text-sm text-ink-900 cursor-pointer pt-2">
            <input type="checkbox" v-model="form.is_active" class="rounded border-border text-accent focus:ring-accent" />
            <span>Active</span>
          </label>

          <div class="flex items-center justify-end gap-2 pt-2">
            <SecondaryButton @click="showModal = false">Cancel</SecondaryButton>
            <PrimaryButton type="submit" :disabled="form.processing">Save Plan</PrimaryButton>
          </div>
        </form>
      </div>
    </Modal>
  </AppLayout>
</template>
