<!-- Territories Index (§3B) -->
<script setup lang="ts">
import { ref } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import SalesSubNav from '@/Components/sales/SalesSubNav.vue'
import SalesMasterSubNav from '@/Components/sales/SalesMasterSubNav.vue'

interface TerritoryItem {
  id: number
  code: string
  name: string
  parent_id: number | null
  is_active: boolean
  teams_count?: number
}

const props = defineProps<{
  territories: TerritoryItem[]
}>()

const showModal = ref(false)
const editingTerritory = ref<TerritoryItem | null>(null)

const form = useForm({
  code: '',
  name: '',
  parent_id: null as number | null,
  is_active: true,
})

const openCreate = () => {
  editingTerritory.value = null
  form.reset()
  form.is_active = true
  showModal.value = true
}

const openEdit = (t: TerritoryItem) => {
  editingTerritory.value = t
  form.code = t.code
  form.name = t.name
  form.parent_id = t.parent_id
  form.is_active = t.is_active
  showModal.value = true
}

const submit = () => {
  if (editingTerritory.value) {
    form.put(route('sales.master.territories.update', editingTerritory.value.id), {
      onSuccess: () => { showModal.value = false },
    })
  } else {
    form.post(route('sales.master.territories.store'), {
      onSuccess: () => { showModal.value = false },
    })
  }
}

const deleteTerritory = (id: number) => {
  if (confirm('Delete this territory?')) {
    router.delete(route('sales.master.territories.destroy', id))
  }
}
</script>

<template>
  <AppLayout>
    <PageHeader
      title="Sales Territories"
      description="Manage geographical and operational sales regions (§3B)."
    >
      <template #actions>
        <PrimaryButton @click="openCreate">New Territory</PrimaryButton>
      </template>
    </PageHeader>

    <div class="mt-4">
      <SalesSubNav active="master" />
    </div>

    <div class="mt-4">
      <SalesMasterSubNav active="territories" />
    </div>

    <div class="mt-6 rounded-lg border border-border bg-surface-0 overflow-x-auto">
      <table class="w-full text-left text-sm">
        <thead class="bg-surface-50 text-xs text-ink-500 uppercase border-b border-border">
          <tr>
            <th class="py-3 px-4">Code</th>
            <th class="py-3 px-4">Territory Name</th>
            <th class="py-3 px-4">Sales Teams</th>
            <th class="py-3 px-4">Status</th>
            <th class="py-3 px-4 text-right">Action</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-border">
          <tr v-for="t in props.territories" :key="t.id" class="hover:bg-surface-50">
            <td class="py-3 px-4 font-mono font-medium text-accent">{{ t.code }}</td>
            <td class="py-3 px-4 font-semibold text-ink-900">{{ t.name }}</td>
            <td class="py-3 px-4 text-xs font-mono text-ink-600">{{ t.teams_count ?? 0 }} team(s)</td>
            <td class="py-3 px-4 text-xs font-semibold" :class="t.is_active ? 'text-emerald-600' : 'text-ink-400'">
              {{ t.is_active ? 'Active' : 'Inactive' }}
            </td>
            <td class="py-3 px-4 text-right space-x-2">
              <button
                type="button"
                @click="openEdit(t)"
                class="text-xs font-medium text-accent hover:underline"
              >
                Edit
              </button>
              <button
                type="button"
                @click="deleteTerritory(t.id)"
                class="text-xs font-medium text-rose-600 hover:underline"
              >
                Delete
              </button>
            </td>
          </tr>
          <tr v-if="props.territories.length === 0">
            <td colspan="5" class="py-8 text-center text-ink-500">No territories configured.</td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Territory Modal -->
    <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
      <div class="w-full max-w-md rounded-lg bg-surface-0 p-6 shadow-xl border border-border">
        <h3 class="text-lg font-semibold text-ink-900">{{ editingTerritory ? 'Edit Territory' : 'New Territory' }}</h3>

        <form @submit.prevent="submit" class="mt-4 space-y-4">
          <FormInput
            label="Territory Code *"
            v-model="form.code"
            :error="form.errors.code"
            placeholder="e.g. ID-JKT"
            required
          />

          <FormInput
            label="Territory Name *"
            v-model="form.name"
            :error="form.errors.name"
            placeholder="e.g. DKI Jakarta & Greater Area"
            required
          />

          <label class="flex items-center gap-2 text-sm text-ink-900 cursor-pointer pt-2">
            <input type="checkbox" v-model="form.is_active" class="rounded border-border text-accent focus:ring-accent" />
            <span>Active</span>
          </label>

          <div class="flex items-center justify-end gap-2 pt-2">
            <SecondaryButton @click="showModal = false">Cancel</SecondaryButton>
            <PrimaryButton type="submit" :disabled="form.processing">Save Territory</PrimaryButton>
          </div>
        </form>
      </div>
    </div>
  </AppLayout>
</template>
