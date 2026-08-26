<!-- Sales Teams Index (§3B) -->
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

const showModal = ref(false)
const editingTeam = ref<TeamItem | null>(null)

const form = useForm({
  name: '',
  territory_id: null as number | null,
  is_active: true,
  member_user_ids: [] as number[],
})

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
  form.member_user_ids = team.members.map(m => m.user?.id).filter(Boolean) as number[]
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

const deleteTeam = (id: number) => {
  if (confirm('Delete this sales team?')) {
    router.delete(route('sales.master.teams.destroy', id))
  }
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

    <div class="mt-6 rounded-lg border border-border bg-surface-0 overflow-x-auto">
      <table class="w-full text-left text-sm">
        <thead class="bg-surface-50 text-xs text-ink-500 uppercase border-b border-border">
          <tr>
            <th class="py-3 px-4">Team Name</th>
            <th class="py-3 px-4">Territory</th>
            <th class="py-3 px-4">Members</th>
            <th class="py-3 px-4">Status</th>
            <th class="py-3 px-4 text-right">Action</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-border">
          <tr v-for="team in props.teams" :key="team.id" class="hover:bg-surface-50">
            <td class="py-3 px-4 font-semibold text-ink-900">{{ team.name }}</td>
            <td class="py-3 px-4 text-ink-600">{{ team.territory?.name ?? 'All Territories' }}</td>
            <td class="py-3 px-4 text-xs text-ink-700">
              <span v-if="team.members.length > 0">
                {{ team.members.map(m => m.user?.name).filter(Boolean).join(', ') }}
              </span>
              <span v-else class="text-ink-400">No members assigned</span>
            </td>
            <td class="py-3 px-4 text-xs font-semibold" :class="team.is_active ? 'text-emerald-600' : 'text-ink-400'">
              {{ team.is_active ? 'Active' : 'Inactive' }}
            </td>
            <td class="py-3 px-4 text-right space-x-2">
              <button
                type="button"
                @click="openEdit(team)"
                class="text-xs font-medium text-accent hover:underline"
              >
                Edit
              </button>
              <button
                type="button"
                @click="deleteTeam(team.id)"
                class="text-xs font-medium text-rose-600 hover:underline"
              >
                Delete
              </button>
            </td>
          </tr>
          <tr v-if="props.teams.length === 0">
            <td colspan="5" class="py-8 text-center text-ink-500">No sales teams found.</td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Team Modal -->
    <Modal :show="showModal" max-width="md" @close="showModal = false">
      <div class="p-6 bg-white rounded-lg">
        <h3 class="text-lg font-semibold text-ink-900">{{ editingTeam ? 'Edit Sales Team' : 'New Sales Team' }}</h3>

        <form @submit.prevent="submit" class="mt-4 space-y-4">
          <FormInput
            label="Team Name *"
            v-model="form.name"
            :error="form.errors.name"
            placeholder="e.g. Enterprise Sales Indonesia"
            required
          />

          <FormSelect
            label="Assigned Territory"
            v-model="form.territory_id"
            :error="form.errors.territory_id"
            :options="props.territories.map(t => ({ value: t.id, label: t.name }))"
            placeholder="Select territory (optional)…"
          />

          <div>
            <label class="block text-xs font-medium text-ink-700 mb-1">Select Member Users</label>
            <select
              multiple
              v-model="form.member_user_ids"
              class="w-full rounded border border-border bg-white py-2 px-3 text-xs text-ink-900 focus:outline-none h-32"
            >
              <option v-for="u in props.users" :key="u.id" :value="u.id">{{ u.name }}</option>
            </select>
            <p class="text-[11px] text-ink-500 mt-1">Hold Ctrl/Cmd to select multiple members.</p>
          </div>

          <label class="flex items-center gap-2 text-sm text-ink-900 cursor-pointer pt-2">
            <input type="checkbox" v-model="form.is_active" class="rounded border-border text-accent focus:ring-accent" />
            <span>Active</span>
          </label>

          <div class="flex items-center justify-end gap-2 pt-2">
            <SecondaryButton @click="showModal = false">Cancel</SecondaryButton>
            <PrimaryButton type="submit" :disabled="form.processing">Save Team</PrimaryButton>
          </div>
        </form>
      </div>
    </Modal>
  </AppLayout>
</template>
