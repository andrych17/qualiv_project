<!-- ponytail: POS Terminals & Devices (POS_SPECS.md §3B, §3Q) -->
<script setup lang="ts">
import { ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import DataTable, { type FilterFieldDef, type SortState } from '@/Components/tables/DataTable.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import Modal from '@/Components/Modal.vue'
import { debounce } from '@/Composables/debounce'
import { Plus, Check } from 'lucide-vue-next'

interface TerminalRow {
  id: number
  code: string
  name: string
  receipt_prefix: string
  is_active: boolean
  branch?: { id: number; name: string }
  warehouse?: { id: number; name: string }
  profile?: { id: number; name: string; base_type: string }
  devices?: Array<{ id: number; device_type: string; device_name: string }>
  current_session?: { id: number; session_no: string; cashier?: { name: string } }
}

interface PaginatedData<T> {
  data: T[]
  links: Array<{ url: string | null; label: string; active: boolean }>
  total: number
  from: number | null
  to: number | null
  per_page: number
}

const props = defineProps<{
  terminals: PaginatedData<TerminalRow>
  branches: Array<{ id: number; name: string }>
  warehouses: Array<{ id: number; name: string }>
  profiles: Array<{ id: number; name: string; base_type: string }>
  filters: { search?: string; branch_id?: string; profile_id?: string; is_active?: string; sort?: string; direction?: string; per_page?: string }
}>()

const search = ref(props.filters.search ?? '')
const filters = ref({
  branch_id: props.filters.branch_id ?? '',
  profile_id: props.filters.profile_id ?? '',
  is_active: props.filters.is_active ?? '',
})

const sort = ref<SortState>(
  props.filters.sort ? { key: props.filters.sort, direction: props.filters.direction === 'desc' ? 'desc' : 'asc' } : null,
)

const perPage = ref(Number(props.filters.per_page) || props.terminals.per_page)

const columns = [
  { key: 'code', label: 'Kode Terminal', sortable: true },
  { key: 'name', label: 'Nama Terminal', sortable: true },
  { key: 'profile', label: 'Profil POS' },
  { key: 'warehouse', label: 'Gudang / Outlet' },
  { key: 'receipt_prefix', label: 'Prefix Struk' },
  { key: 'current_session', label: 'Status Shift' },
  { key: 'is_active', label: 'Status' },
]

const filterFields: FilterFieldDef[] = [
  {
    key: 'profile_id',
    label: 'Profil POS',
    type: 'select',
    options: props.profiles.map((p) => ({ label: p.name, value: String(p.id) })),
  },
  {
    key: 'is_active',
    label: 'Status',
    type: 'select',
    options: [
      { label: 'Aktif', value: '1' },
      { label: 'Non-Aktif', value: '0' },
    ],
  },
]

const applyFilters = () => {
  router.get(
    route('pos.terminals.index'),
    {
      search: search.value || undefined,
      branch_id: filters.value.branch_id || undefined,
      profile_id: filters.value.profile_id || undefined,
      is_active: filters.value.is_active || undefined,
      sort: sort.value?.key,
      direction: sort.value?.direction,
      per_page: perPage.value,
    },
    { preserveState: true, replace: true },
  )
}

watch(search, debounce(applyFilters, 300))
watch(filters, applyFilters, { deep: true })
watch(sort, applyFilters)
watch(perPage, applyFilters)

// Create Terminal Modal
const showCreateModal = ref(false)
const form = ref({
  code: '',
  name: '',
  receipt_prefix: 'POS',
  warehouse_id: props.warehouses[0]?.id || '',
  profile_id: props.profiles[0]?.id || '',
  branch_id: props.branches[0]?.id || '',
})
const isSubmitting = ref(false)

const handleCreateTerminal = () => {
  isSubmitting.value = true
  router.post(
    route('pos.terminals.store'),
    form.value,
    {
      onSuccess: () => {
        showCreateModal.value = false
        isSubmitting.value = false
      },
      onError: () => {
        isSubmitting.value = false
      },
    },
  )
}
</script>

<template>
  <AppLayout>
    <PageHeader
      title="Terminal & Perangkat Kasir"
      description="Konfigurasi mesin POS fisik, printer struk, barcode scanner, dan cash drawer."
    >
      <template #actions>
        <PrimaryButton class="inline-flex items-center gap-1.5" @click="showCreateModal = true">
          <Plus class="h-4 w-4" />
          Tambah Terminal
        </PrimaryButton>
      </template>
    </PageHeader>

    <div class="mt-6">
      <DataTable
        :columns="columns"
        :items="terminals.data"
        v-model:sort="sort"
        v-model:search="search"
        v-model:filters="filters"
        v-model:per-page="perPage"
        :filter-fields="filterFields"
        :total="terminals.total"
        :from="terminals.from"
        :to="terminals.to"
        :links="terminals.links"
        empty-title="Belum ada terminal kasir"
        empty-description="Daftarkan terminal POS baru untuk melayani penjualan fisik."
      >
        <template #cell-code="{ item }">
          <span class="font-mono font-medium text-ink-900">{{ (item as TerminalRow).code }}</span>
        </template>
        <template #cell-name="{ item }">
          <span class="font-medium text-ink-900">{{ (item as TerminalRow).name }}</span>
        </template>
        <template #cell-profile="{ item }">
          <span class="text-ink-700">{{ (item as TerminalRow).profile?.name || '-' }}</span>
        </template>
        <template #cell-warehouse="{ item }">
          <span class="text-ink-700">{{ (item as TerminalRow).warehouse?.name || '-' }}</span>
        </template>
        <template #cell-receipt_prefix="{ item }">
          <span class="font-mono text-ink-600">{{ (item as TerminalRow).receipt_prefix }}</span>
        </template>
        <template #cell-current_session="{ item }">
          <span
            v-if="(item as TerminalRow).current_session"
            class="inline-flex items-center gap-1 text-xs font-medium text-emerald-700"
          >
            <Check class="h-3.5 w-3.5" />
            Buka ({{ (item as TerminalRow).current_session?.session_no }})
          </span>
          <span v-else class="text-xs text-ink-400">Tutup</span>
        </template>
        <template #cell-is_active="{ item }">
          <StatusBadge
            :status="(item as TerminalRow).is_active ? 'success' : 'neutral'"
            :label="(item as TerminalRow).is_active ? 'AKTIF' : 'NON-AKTIF'"
          />
        </template>
      </DataTable>
    </div>

    <!-- Modal: Tambah Terminal -->
    <Modal :show="showCreateModal" @close="showCreateModal = false">
      <div class="p-6">
        <h3 class="text-lg font-bold text-ink-900">Tambah Terminal Kasir Baru</h3>
        <p class="mt-1 text-sm text-ink-600">Daftarkan register atau mesin kasir baru ke sistem.</p>

        <div class="mt-4 space-y-4">
          <div>
            <label class="block text-sm font-medium text-ink-700">Kode Terminal</label>
            <input
              v-model="form.code"
              type="text"
              placeholder="Contoh: POS-01"
              class="mt-1 block w-full rounded-md border-surface-300 font-mono text-sm focus:border-primary-500 focus:ring-primary-500"
            />
          </div>

          <div>
            <label class="block text-sm font-medium text-ink-700">Nama Terminal</label>
            <input
              v-model="form.name"
              type="text"
              placeholder="Contoh: Kasir Utama Lantai 1"
              class="mt-1 block w-full rounded-md border-surface-300 text-sm focus:border-primary-500 focus:ring-primary-500"
            />
          </div>

          <div>
            <label class="block text-sm font-medium text-ink-700">Profil POS</label>
            <select
              v-model="form.profile_id"
              class="mt-1 block w-full rounded-md border-surface-300 text-sm focus:border-primary-500 focus:ring-primary-500"
            >
              <option v-for="p in profiles" :key="p.id" :value="p.id">
                {{ p.name }} ({{ p.base_type }})
              </option>
            </select>
          </div>

          <div>
            <label class="block text-sm font-medium text-ink-700">Gudang / Sumber Stok</label>
            <select
              v-model="form.warehouse_id"
              class="mt-1 block w-full rounded-md border-surface-300 text-sm focus:border-primary-500 focus:ring-primary-500"
            >
              <option v-for="w in warehouses" :key="w.id" :value="w.id">
                {{ w.name }}
              </option>
            </select>
          </div>

          <div>
            <label class="block text-sm font-medium text-ink-700">Prefix Struk</label>
            <input
              v-model="form.receipt_prefix"
              type="text"
              maxlength="10"
              class="mt-1 block w-full rounded-md border-surface-300 font-mono text-sm focus:border-primary-500 focus:ring-primary-500"
            />
          </div>
        </div>

        <div class="mt-6 flex justify-end gap-3">
          <SecondaryButton @click="showCreateModal = false">Batal</SecondaryButton>
          <PrimaryButton :disabled="isSubmitting || !form.code || !form.name" @click="handleCreateTerminal">
            {{ isSubmitting ? 'Menyimpan...' : 'Simpan Terminal' }}
          </PrimaryButton>
        </div>
      </div>
    </Modal>
  </AppLayout>
</template>
