<!-- Sales Teams Index (§3B) -->
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
import FormMultiSelect from '@/Components/forms/FormMultiSelect.vue'
import FormSwitch from '@/Components/forms/FormSwitch.vue'
import SalesSubNav from '@/Components/sales/SalesSubNav.vue'
import SalesMasterSubNav from '@/Components/sales/SalesMasterSubNav.vue'
import DataTable, { type SortState } from '@/Components/tables/DataTable.vue'
import Modal from '@/Components/Modal.vue'
import { useConfirm } from '@/Composables/useConfirmDialog'

interface MemberItem {
  id: number
  role: string
  user?: { id: number; name: string }
}

interface TeamItem {
  id: number
  name: string
  territory_id: number | null
  is_active: boolean
  territory?: { name: string }
  members: MemberItem[]
}

const props = defineProps<{
  teams: TeamItem[]
  territories: Array<{ id: number; name: string }>
  users: Array<{ id: number; name: string }>
}>()

const search = ref('')
const sort = ref<SortState>(null)
const selected = ref<Array<string | number>>([])

const showModal = ref(false)
const editingTeam = ref<TeamItem | null>(null)

const form = useForm({
  name: '',
  territory_id: null as number | null,
  is_active: true,
  member_user_ids: [] as number[],
})

const columns = [
  { key: 'name', label: 'Team Name', sortable: true },
  { key: 'territory', label: 'Territory' },
  { key: 'members', label: 'Members' },
  { key: 'is_active', label: 'Status', sortable: true },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

const openCreate = () => {
  editingTeam.value = null
  form.reset()
  form.is_active = true
  showModal.value = true
}

const openEdit = (team: TeamItem) => {
  editingTeam.value = team
  form.name = team.name
  form.territory_id = team.territory_id
  form.is_active = team.is_active
  form.member_user_ids = team.members.map((m) => m.user?.id).filter(Boolean) as number[]
  showModal.value = true
}

const submit = () => {
  if (editingTeam.value) {
    form.put(route('sales.master.teams.update', editingTeam.value.id), {
      onSuccess: () => { showModal.value = false },
    })
  } else {
    form.post(route('sales.master.teams.store'), {
      onSuccess: () => { showModal.value = false },
    })
  }
}

const { confirm } = useConfirm()

const deleteTeam = (team: TeamItem) => {
  confirm({
    title: `Delete Sales Team "${team.name}"?`,
    description: 'Are you sure you want to delete this sales team?',
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () => router.delete(route('sales.master.teams.destroy', team.id)),
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader
      title="Sales Teams"
      description="Organize sales representatives into regional and functional teams (§3B)."
    >
      <template #actions>
        <PrimaryButton @click="openCreate">New Sales Team</PrimaryButton>
      </template>
    </PageHeader>

    <div class="mt-4">
      <SalesSubNav active="master" />
    </div>

    <div class="mt-4">
      <SalesMasterSubNav active="teams" />
    </div>

    <div class="mt-6">
      <DataTable
        :columns="columns"
        :items="props.teams"
        v-model:sort="sort"
        v-model:selected="selected"
        v-model:search="search"
        sticky-header
        storage-key="sales.master.teams"
        search-placeholder="Search sales teams…"
        export-filename="sales-teams"
        status-rail-key="is_active"
        empty-title="No sales teams found"
        empty-description="Create your first sales team to assign sales representatives and territories."
      >
        <template #cell-name="{ item }">
          <span class="font-semibold text-ink-900">{{ (item as TeamItem).name }}</span>
        </template>

        <template #cell-territory="{ item }">
          <span class="text-ink-600">{{ (item as TeamItem).territory?.name ?? 'All Territories' }}</span>
        </template>

        <template #cell-members="{ item }">
          <span v-if="(item as TeamItem).members.length > 0" class="text-xs text-ink-700">
            {{ (item as TeamItem).members.map((m) => m.user?.name).filter(Boolean).join(', ') }}
          </span>
          <span v-else class="text-ink-400 text-xs">No members assigned</span>
        </template>

        <template #cell-is_active="{ item }">
          <StatusBadge :status="(item as TeamItem).is_active ? 'active' : 'inactive'" />
        </template>

        <template #cell-actions="{ item }">
          <div class="flex items-center justify-end gap-3">
            <button
              type="button"
              @click="openEdit(item as TeamItem)"
              class="text-xs font-semibold text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
            >
              Edit
            </button>
            <button
              type="button"
              @click="deleteTeam(item as TeamItem)"
              class="text-xs font-medium text-signal-danger hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
            >
              Delete
            </button>
          </div>
        </template>
      </DataTable>
    </div>

    <!-- Team Modal -->
    <Modal :show="showModal" max-width="md" @close="showModal = false">
      <div class="p-6 bg-surface-0 border border-border text-ink-900 rounded-lg">
        <h3 class="text-lg font-semibold text-ink-900">{{ editingTeam ? 'Edit Sales Team' : 'New Sales Team' }}</h3>

        <form @submit.prevent="submit" class="mt-4 space-y-4">
          <FormInput
            label="Team Name"
            name="name"
            v-model="form.name"
            :error="form.errors.name"
            placeholder="e.g. Enterprise Sales Indonesia"
            required
          />

          <FormSelect
            label="Assigned Territory"
            name="territory_id"
            v-model="form.territory_id"
            :error="form.errors.territory_id"
            :options="props.territories.map(t => ({ value: t.id, label: t.name }))"
            placeholder="Select territory (optional)…"
          />

          <FormMultiSelect
            v-model="form.member_user_ids"
            name="member_user_ids"
            label="Select Member Users"
            placeholder="Select members…"
            :options="props.users.map(u => ({ value: u.id, label: u.name }))"
            :error="form.errors.member_user_ids"
          />

          <FormSwitch
            v-model="form.is_active"
            name="is_active"
            label="Active Status"
            description="Allow this team to receive deal assignments and quotas."
          />

          <div class="flex items-center justify-end gap-2 pt-2">
            <SecondaryButton @click="showModal = false">Cancel</SecondaryButton>
            <PrimaryButton type="submit" :disabled="form.processing">Save Team</PrimaryButton>
          </div>
        </form>
      </div>
    </Modal>
  </AppLayout>
</template>
