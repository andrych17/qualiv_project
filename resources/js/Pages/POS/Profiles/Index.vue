<!-- ponytail: POS Profile & Capability Matrix (POS_SPECS.md §3A) -->
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
import FormInput from '@/Components/forms/FormInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import Checkbox from '@/Components/Checkbox.vue'
import { debounce } from '@/Composables/debounce'
import { Plus, Check } from 'lucide-vue-next'

interface ProfileRow {
  id: number
  code: string
  name: string
  base_type: string
  requires_barcode: boolean
  touch_menu: boolean
  table_management: boolean
  modifiers_enabled: boolean
  kds_enabled: boolean
  loyalty_enabled: boolean
  offline_enabled: boolean
  is_active: boolean
  terminals_count: number
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
  profiles: PaginatedData<ProfileRow>
  filters: { search?: string; base_type?: string; is_active?: string; sort?: string; direction?: string; per_page?: string }
}>()

const search = ref(props.filters.search ?? '')
const filters = ref({
  base_type: props.filters.base_type ?? '',
  is_active: props.filters.is_active ?? '',
})

const sort = ref<SortState>(
  props.filters.sort ? { key: props.filters.sort, direction: props.filters.direction === 'desc' ? 'desc' : 'asc' } : null,
)

const perPage = ref(Number(props.filters.per_page) || props.profiles.per_page)

const columns = [
  { key: 'code', label: 'Kode', sortable: true },
  { key: 'name', label: 'Nama Profil', sortable: true },
  { key: 'base_type', label: 'Tipe Bisnis' },
  { key: 'requires_barcode', label: 'Barcode', align: 'center' as const },
  { key: 'table_management', label: 'Meja / Floor', align: 'center' as const },
  { key: 'kds_enabled', label: 'KDS Dapur', align: 'center' as const },
  { key: 'offline_enabled', label: 'Offline', align: 'center' as const },
  { key: 'terminals_count', label: 'Jumlah Mesin', align: 'center' as const },
  { key: 'is_active', label: 'Status' },
]

const filterFields: FilterFieldDef[] = [
  {
    key: 'base_type',
    label: 'Tipe Bisnis',
    type: 'select',
    options: [
      { label: 'Retail / Swalayan', value: 'retail' },
      { label: 'Restaurant / F&B', value: 'restaurant' },
      { label: 'Jasa / Service', value: 'service' },
    ],
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
    route('pos.profiles.index'),
    {
      search: search.value || undefined,
      base_type: filters.value.base_type || undefined,
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

// Modal Form
const showCreateModal = ref(false)
const form = ref({
  code: '',
  name: '',
  base_type: 'retail',
  requires_barcode: true,
  touch_menu: true,
  table_management: false,
  modifiers_enabled: false,
  kds_enabled: false,
  loyalty_enabled: true,
  offline_enabled: true,
})
const isSubmitting = ref(false)

const handleCreateProfile = () => {
  isSubmitting.value = true
  router.post(
    route('pos.profiles.store'),
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
      title="Profil & Matriks Kemampuan POS"
      description="Atur fitur dan alur kerja kasir (Retail/Barcode vs. Restaurant/Meja & KDS) tanpa membuat aplikasi terpisah."
    >
      <template #actions>
        <PrimaryButton class="inline-flex items-center gap-1.5" @click="showCreateModal = true">
          <Plus class="h-4 w-4" />
          Tambah Profil
        </PrimaryButton>
      </template>
    </PageHeader>

    <div class="mt-6">
      <DataTable
        :columns="columns"
        :items="profiles.data"
        v-model:sort="sort"
        v-model:search="search"
        v-model:filters="filters"
        v-model:per-page="perPage"
        :filter-fields="filterFields"
        :total="profiles.total"
        :from="profiles.from"
        :to="profiles.to"
        :links="profiles.links"
        empty-title="Belum ada profil POS"
        empty-description="Buat profil POS untuk mengatur kapabilitas terminal."
      >
        <template #cell-code="{ item }">
          <span class="font-mono font-medium text-ink-900">{{ (item as ProfileRow).code }}</span>
        </template>
        <template #cell-name="{ item }">
          <span class="font-medium text-ink-900">{{ (item as ProfileRow).name }}</span>
        </template>
        <template #cell-base_type="{ item }">
          <span class="inline-flex items-center rounded-md bg-surface-100 px-2 py-1 text-xs font-medium text-ink-700 capitalize">
            {{ (item as ProfileRow).base_type }}
          </span>
        </template>
        <template #cell-requires_barcode="{ item }">
          <Check v-if="(item as ProfileRow).requires_barcode" class="mx-auto h-4 w-4 text-emerald-600" />
          <span v-else class="text-ink-300">-</span>
        </template>
        <template #cell-table_management="{ item }">
          <Check v-if="(item as ProfileRow).table_management" class="mx-auto h-4 w-4 text-emerald-600" />
          <span v-else class="text-ink-300">-</span>
        </template>
        <template #cell-kds_enabled="{ item }">
          <Check v-if="(item as ProfileRow).kds_enabled" class="mx-auto h-4 w-4 text-emerald-600" />
          <span v-else class="text-ink-300">-</span>
        </template>
        <template #cell-offline_enabled="{ item }">
          <Check v-if="(item as ProfileRow).offline_enabled" class="mx-auto h-4 w-4 text-emerald-600" />
          <span v-else class="text-ink-300">-</span>
        </template>
        <template #cell-terminals_count="{ item }">
          <span class="font-mono font-semibold text-ink-800">
            {{ (item as ProfileRow).terminals_count || 0 }}
          </span>
        </template>
        <template #cell-is_active="{ item }">
          <StatusBadge
            :status="(item as ProfileRow).is_active ? 'success' : 'neutral'"
            :label="(item as ProfileRow).is_active ? 'AKTIF' : 'NON-AKTIF'"
          />
        </template>
      </DataTable>
    </div>

    <!-- Modal: Tambah Profil -->
    <Modal :show="showCreateModal" @close="showCreateModal = false">
      <div class="p-6">
        <h3 class="text-lg font-bold text-ink-900">Tambah Profil POS Baru</h3>
        <p class="mt-1 text-sm text-ink-600">Tetapkan kemampuan modul kasir untuk tipe operasional tertentu.</p>

        <div class="mt-4 space-y-4">
          <FormInput
            v-model="form.code"
            label="Kode Profil"
            placeholder="Contoh: RETAIL-STD"
            required
          />

          <FormInput
            v-model="form.name"
            label="Nama Profil"
            placeholder="Contoh: Retail Supermarket Standar"
            required
          />

          <FormSelect
            v-model="form.base_type"
            label="Tipe Bisnis Dasar"
            :options="[
              { label: 'Retail / Minimarket', value: 'retail' },
              { label: 'Restaurant / Kafe / F&B', value: 'restaurant' },
              { label: 'Jasa / Salon / Bengkel', value: 'service' },
            ]"
            required
          />

          <div class="space-y-2.5 pt-2 border-t border-border">
            <span class="block text-xs font-semibold text-ink-900 uppercase tracking-wider">Fitur & Kemampuan</span>
            <label class="flex items-center gap-2 text-sm text-ink-900 cursor-pointer">
              <Checkbox :checked="form.requires_barcode" @update:checked="form.requires_barcode = $event" />
              Wajib Barcode Scanner
            </label>
            <label class="flex items-center gap-2 text-sm text-ink-900 cursor-pointer">
              <Checkbox :checked="form.touch_menu" @update:checked="form.touch_menu = $event" />
              Menu Tombol Layar Sentuh (Touch Grid)
            </label>
            <label class="flex items-center gap-2 text-sm text-ink-900 cursor-pointer">
              <Checkbox :checked="form.table_management" @update:checked="form.table_management = $event" />
              Manajemen Meja & Dine-In
            </label>
            <label class="flex items-center gap-2 text-sm text-ink-900 cursor-pointer">
              <Checkbox :checked="form.kds_enabled" @update:checked="form.kds_enabled = $event" />
              Integrasi Kitchen Display (KDS)
            </label>
            <label class="flex items-center gap-2 text-sm text-ink-900 cursor-pointer">
              <Checkbox :checked="form.offline_enabled" @update:checked="form.offline_enabled = $event" />
              Dukungan Offline-First (PWA)
            </label>
          </div>
        </div>

        <div class="mt-6 flex justify-end gap-3">
          <SecondaryButton @click="showCreateModal = false">Batal</SecondaryButton>
          <PrimaryButton :disabled="isSubmitting || !form.code || !form.name" @click="handleCreateProfile">
            {{ isSubmitting ? 'Menyimpan...' : 'Simpan Profil' }}
          </PrimaryButton>
        </div>
      </div>
    </Modal>
  </AppLayout>
</template>
