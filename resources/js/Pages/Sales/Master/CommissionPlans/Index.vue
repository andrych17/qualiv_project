<!-- Commission Plans Index (§3B / §3M) -->
<script setup lang="ts">
import { ref } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import FormSwitch from '@/Components/forms/FormSwitch.vue'
import SalesSubNav from '@/Components/sales/SalesSubNav.vue'
import SalesMasterSubNav from '@/Components/sales/SalesMasterSubNav.vue'
import DataTable, { type SortState } from '@/Components/tables/DataTable.vue'
import Modal from '@/Components/Modal.vue'
import { useConfirm } from '@/Composables/useConfirmDialog'
import { formatCurrency } from '@/Utils/formatters'

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

const search = ref('')
const sort = ref<SortState>(null)
const selected = ref<Array<string | number>>([])

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

const columns = [
  { key: 'name', label: 'Plan Name', sortable: true },
  { key: 'scope', label: 'Scope' },
  { key: 'calc_type', label: 'Calculation Type' },
  { key: 'rate_structure', label: 'Rate Structure' },
  { key: 'is_active', label: 'Status', sortable: true },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

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

const { confirm } = useConfirm()

const deletePlan = (p: PlanItem) => {
  confirm({
    title: `Delete Commission Plan "${p.name}"?`,
    description: 'Are you sure you want to delete this commission plan?',
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () => router.delete(route('sales.master.commission-plans.destroy', p.id)),
  })
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

    <div class="mt-6">
      <DataTable
        :columns="columns"
        :items="props.plans"
        v-model:sort="sort"
        v-model:selected="selected"
        v-model:search="search"
        sticky-header
        storage-key="sales.master.commission-plans"
        search-placeholder="Search commission plans…"
        export-filename="sales-commission-plans"
        status-rail-key="is_active"
        empty-title="No commission plans found"
        empty-description="Create your first commission plan to configure rep and team incentives."
      >
        <template #cell-name="{ item }">
          <span class="font-semibold text-ink-900">{{ (item as PlanItem).name }}</span>
        </template>

        <template #cell-scope="{ item }">
          <span v-if="(item as PlanItem).user" class="text-xs text-ink-700">
            Rep: <strong>{{ (item as PlanItem).user?.name }}</strong>
          </span>
          <span v-else-if="(item as PlanItem).sales_team" class="text-xs text-ink-700">
            Team: <strong>{{ (item as PlanItem).sales_team?.name }}</strong>
          </span>
          <span v-else class="text-xs text-ink-400">Tenant-wide Default</span>
        </template>

        <template #cell-calc_type="{ item }">
          <span class="capitalize text-ink-900">{{ (item as PlanItem).calc_type }}</span>
        </template>

        <template #cell-rate_structure="{ item }">
          <span v-if="(item as PlanItem).calc_type === 'flat'" class="text-xs font-mono">
            {{ (item as PlanItem).flat_rate }}% Flat
          </span>
          <span v-else class="text-xs font-mono">
            Base {{ (item as PlanItem).tier_base_rate }}% / Excess {{ (item as PlanItem).tier_excess_rate }}% (Above {{ formatCurrency((item as PlanItem).tier_threshold || 0) }})
          </span>
        </template>

        <template #cell-is_active="{ item }">
          <StatusBadge :status="(item as PlanItem).is_active ? 'active' : 'inactive'" />
        </template>

        <template #cell-actions="{ item }">
          <div class="flex items-center justify-end gap-3">
            <button
              type="button"
              @click="openEdit(item as PlanItem)"
              class="text-xs font-semibold text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
            >
              Edit
            </button>
            <button
              type="button"
              @click="deletePlan(item as PlanItem)"
              class="text-xs font-medium text-signal-danger hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
            >
              Delete
            </button>
          </div>
        </template>
      </DataTable>
    </div>

    <!-- Plan Modal -->
    <Modal :show="showModal" max-width="md" @close="showModal = false">
      <div class="p-6 bg-surface-0 border border-border text-ink-900 rounded-lg">
        <h3 class="text-lg font-semibold text-ink-900">{{ editingPlan ? 'Edit Commission Plan' : 'New Commission Plan' }}</h3>

        <form @submit.prevent="submit" class="mt-4 space-y-4">
          <FormInput
            label="Plan Name"
            name="name"
            v-model="form.name"
            :error="form.errors.name"
            placeholder="e.g. Standard 5% Rep Plan"
            required
          />

          <div class="grid grid-cols-2 gap-3">
            <FormSelect
              label="Sales Team"
              name="sales_team_id"
              v-model="form.sales_team_id"
              :options="props.teams.map(t => ({ value: t.id, label: t.name }))"
              placeholder="All teams"
            />
            <FormSelect
              label="Specific Rep"
              name="user_id"
              v-model="form.user_id"
              :options="props.users.map(u => ({ value: u.id, label: u.name }))"
              placeholder="All reps"
            />
          </div>

          <FormSelect
            label="Calculation Type"
            name="calc_type"
            v-model="form.calc_type"
            :options="[
              { label: 'Flat Percentage Rate', value: 'flat' },
              { label: 'Tiered (Base + Excess)', value: 'tiered' }
            ]"
            required
          />

          <div v-if="form.calc_type === 'flat'">
            <FormInput
              label="Flat Commission Rate (%)"
              name="flat_rate"
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
              label="Tier Threshold (IDR)"
              name="tier_threshold"
              type="number"
              step="any"
              min="0"
              v-model="form.tier_threshold"
              placeholder="e.g. 100000000"
              required
            />
            <div class="grid grid-cols-2 gap-3">
              <FormInput
                label="Base Rate (%)"
                name="tier_base_rate"
                type="number"
                step="any"
                min="0"
                v-model="form.tier_base_rate"
                required
              />
              <FormInput
                label="Excess Rate (%)"
                name="tier_excess_rate"
                type="number"
                step="any"
                min="0"
                v-model="form.tier_excess_rate"
                required
              />
            </div>
          </div>

          <FormSwitch
            v-model="form.is_active"
            name="is_active"
            label="Active Status"
            description="Enable this commission plan for rep payouts."
          />

          <div class="flex items-center justify-end gap-2 pt-2">
            <SecondaryButton @click="showModal = false">Cancel</SecondaryButton>
            <PrimaryButton type="submit" :disabled="form.processing">Save Plan</PrimaryButton>
          </div>
        </form>
      </div>
    </Modal>
  </AppLayout>
</template>
