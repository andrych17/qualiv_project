<!-- ponytail: POS Cash Sessions & Shifts (POS_SPECS.md §3C, §3D) -->
<script setup lang="ts">
import { ref, watch } from 'vue'
import { router, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import DataTable, { type FilterFieldDef, type SortState } from '@/Components/tables/DataTable.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import Modal from '@/Components/Modal.vue'
import { formatCurrency, formatDate } from '@/Utils/formatters'
import { debounce } from '@/Composables/debounce'
import { Plus } from 'lucide-vue-next'

interface SessionRow {
  id: number
  session_no: string
  terminal_id: number
  status: string
  opened_at: string
  closed_at: string | null
  opening_cash: number
  closing_cash_declared: number | null
  cash_difference: number | null
  terminal?: { id: number; code: string; name: string }
  cashier?: { id: number; name: string }
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
  sessions: PaginatedData<SessionRow>
  terminals: Array<{ id: number; code: string; name: string }>
  filters: { search?: string; terminal_id?: string; status?: string; sort?: string; direction?: string; per_page?: string }
}>()

const search = ref(props.filters.search ?? '')
const filters = ref({
  terminal_id: props.filters.terminal_id ?? '',
  status: props.filters.status ?? '',
})

const sort = ref<SortState>(
  props.filters.sort ? { key: props.filters.sort, direction: props.filters.direction === 'desc' ? 'desc' : 'asc' } : null,
)

const perPage = ref(Number(props.filters.per_page) || props.sessions.per_page)

const columns = [
  { key: 'session_no', label: 'Shift #', sortable: true },
  { key: 'terminal', label: 'Terminal' },
  { key: 'cashier', label: 'Kasir' },
  { key: 'status', label: 'Status', sortable: true },
  { key: 'opened_at', label: 'Waktu Buka', sortable: true },
  { key: 'opening_cash', label: 'Kas Awal', align: 'right' as const },
  { key: 'closing_cash_declared', label: 'Kas Akhir', align: 'right' as const },
  { key: 'cash_difference', label: 'Selisih', align: 'right' as const },
  { key: 'actions', label: 'Aksi', align: 'right' as const },
]

const filterFields: FilterFieldDef[] = [
  {
    key: 'terminal_id',
    label: 'Terminal',
    type: 'select',
    options: props.terminals.map((t) => ({ label: `${t.name} (${t.code})`, value: String(t.id) })),
  },
  {
    key: 'status',
    label: 'Status',
    type: 'select',
    options: [
      { label: 'Open', value: 'open' },
      { label: 'Closed', value: 'closed' },
      { label: 'Reconciled', value: 'reconciled' },
    ],
  },
]

const applyFilters = () => {
  router.get(
    route('pos.sessions.index'),
    {
      search: search.value || undefined,
      terminal_id: filters.value.terminal_id || undefined,
      status: filters.value.status || undefined,
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

// Open session modal
const showOpenModal = ref(false)
const selectedTerminal = ref(props.terminals[0]?.id || '')
const openingCashAmount = ref(100000)
const isSubmitting = ref(false)

const handleOpenSession = () => {
  if (!selectedTerminal.value) return
  isSubmitting.value = true
  router.post(
    route('pos.sessions.open'),
    {
      terminal_id: selectedTerminal.value,
      opening_cash: openingCashAmount.value,
    },
    {
      onSuccess: () => {
        showOpenModal.value = false
        isSubmitting.value = false
      },
      onError: () => {
        isSubmitting.value = false
      },
    },
  )
}

// Close session modal
const showCloseModal = ref(false)
const sessionToClose = ref<SessionRow | null>(null)
const closingCashAmount = ref(0)
const closingNotes = ref('')
const isClosing = ref(false)

const openCloseModal = (session: SessionRow) => {
  sessionToClose.value = session
  closingCashAmount.value = Number(session.opening_cash) || 0
  showCloseModal.value = true
}

const handleCloseSession = () => {
  if (!sessionToClose.value) return
  isClosing.value = true
  router.post(
    route('pos.sessions.close', { session: sessionToClose.value.id }),
    {
      declared_cash: closingCashAmount.value,
      notes: closingNotes.value,
    },
    {
      onSuccess: () => {
        showCloseModal.value = false
        isClosing.value = false
      },
      onError: () => {
        isClosing.value = false
      },
    },
  )
}
</script>

<template>
  <AppLayout>
    <PageHeader
      title="Sesi & Shift Kasir"
      description="Manajemen pembukaan shift, pencatatan kas awal/akhir, dan rekonsiliasi kasir."
    >
      <template #actions>
        <PrimaryButton class="inline-flex items-center gap-1.5" @click="showOpenModal = true">
          <Plus class="h-4 w-4" />
          Buka Shift Baru
        </PrimaryButton>
      </template>
    </PageHeader>

    <div class="mt-6">
      <DataTable
        :columns="columns"
        :items="sessions.data"
        v-model:sort="sort"
        v-model:search="search"
        v-model:filters="filters"
        v-model:per-page="perPage"
        :filter-fields="filterFields"
        :total="sessions.total"
        :from="sessions.from"
        :to="sessions.to"
        :links="sessions.links"
        empty-title="Belum ada sesi shift"
        empty-description="Buka shift kasir untuk mulai mencatat transaksi penjualan fisik."
      >
        <template #cell-session_no="{ item }">
          <span class="font-mono font-medium text-ink-900">{{ (item as SessionRow).session_no }}</span>
        </template>
        <template #cell-terminal="{ item }">
          <span class="text-ink-800">{{ (item as SessionRow).terminal?.name }} ({{ (item as SessionRow).terminal?.code }})</span>
        </template>
        <template #cell-cashier="{ item }">
          <span class="text-ink-700">{{ (item as SessionRow).cashier?.name || '-' }}</span>
        </template>
        <template #cell-status="{ item }">
          <StatusBadge
            :status="(item as SessionRow).status === 'open' ? 'success' : (item as SessionRow).status === 'closed' ? 'neutral' : 'info'"
            :label="(item as SessionRow).status.toUpperCase()"
          />
        </template>
        <template #cell-opened_at="{ item }">
          <span class="text-xs text-ink-600">{{ formatDate((item as SessionRow).opened_at) }}</span>
        </template>
        <template #cell-opening_cash="{ item }">
          <span class="font-mono text-ink-900">{{ formatCurrency((item as SessionRow).opening_cash) }}</span>
        </template>
        <template #cell-closing_cash_declared="{ item }">
          <span class="font-mono text-ink-900">
            {{ (item as SessionRow).closing_cash_declared !== null ? formatCurrency((item as SessionRow).closing_cash_declared) : '-' }}
          </span>
        </template>
        <template #cell-cash_difference="{ item }">
          <span
            v-if="(item as SessionRow).cash_difference !== null"
            :class="(item as SessionRow).cash_difference === 0 ? 'text-emerald-600' : Number((item as SessionRow).cash_difference) < 0 ? 'text-rose-600' : 'text-amber-600'"
            class="font-mono font-medium"
          >
            {{ formatCurrency((item as SessionRow).cash_difference) }}
          </span>
          <span v-else class="text-ink-400">-</span>
        </template>
        <template #cell-actions="{ item }">
          <button
            v-if="(item as SessionRow).status === 'open'"
            class="font-semibold text-primary-600 hover:text-primary-800"
            @click="openCloseModal(item as SessionRow)"
          >
            Tutup Shift
          </button>
          <span v-else class="text-xs text-ink-400">Selesai</span>
        </template>
      </DataTable>
    </div>

    <!-- Modal: Buka Shift -->
    <Modal :show="showOpenModal" @close="showOpenModal = false">
      <div class="p-6">
        <h3 class="text-lg font-bold text-ink-900">Buka Shift Kasir Baru</h3>
        <p class="mt-1 text-sm text-ink-600">Pilih terminal kasir dan tetapkan modal kas awal (float cash).</p>

        <div class="mt-4 space-y-4">
          <div>
            <label class="block text-sm font-medium text-ink-700">Terminal</label>
            <select
              v-model="selectedTerminal"
              class="mt-1 block w-full rounded-md border-surface-300 text-sm focus:border-primary-500 focus:ring-primary-500"
            >
              <option v-for="t in terminals" :key="t.id" :value="t.id">
                {{ t.name }} ({{ t.code }})
              </option>
            </select>
          </div>

          <div>
            <label class="block text-sm font-medium text-ink-700">Modal Kas Awal (IDR)</label>
            <input
              v-model.number="openingCashAmount"
              type="number"
              min="0"
              step="1000"
              class="mt-1 block w-full rounded-md border-surface-300 font-mono text-base focus:border-primary-500 focus:ring-primary-500"
            />
          </div>
        </div>

        <div class="mt-6 flex justify-end gap-3">
          <SecondaryButton @click="showOpenModal = false">Batal</SecondaryButton>
          <PrimaryButton :disabled="isSubmitting || !selectedTerminal" @click="handleOpenSession">
            {{ isSubmitting ? 'Membuka...' : 'Buka Shift' }}
          </PrimaryButton>
        </div>
      </div>
    </Modal>

    <!-- Modal: Tutup Shift -->
    <Modal :show="showCloseModal" @close="showCloseModal = false">
      <div v-if="sessionToClose" class="p-6">
        <h3 class="text-lg font-bold text-ink-900">Tutup Shift Kasir: {{ sessionToClose.session_no }}</h3>
        <p class="mt-1 text-sm text-ink-600">Hitung uang fisik di laci kasir dan masukkan total kas akhir.</p>

        <div class="mt-4 space-y-4">
          <div>
            <label class="block text-sm font-medium text-ink-700">Total Kas Akhir Fisik (IDR)</label>
            <input
              v-model.number="closingCashAmount"
              type="number"
              min="0"
              step="1000"
              class="mt-1 block w-full rounded-md border-surface-300 font-mono text-base font-bold text-primary-700 focus:border-primary-500 focus:ring-primary-500"
            />
          </div>

          <div>
            <label class="block text-sm font-medium text-ink-700">Catatan Rekonsiliasi / Shift</label>
            <textarea
              v-model="closingNotes"
              rows="3"
              placeholder="Keterangan bila ada selisih kas atau kendala shift..."
              class="mt-1 block w-full rounded-md border-surface-300 text-sm focus:border-primary-500 focus:ring-primary-500"
            ></textarea>
          </div>
        </div>

        <div class="mt-6 flex justify-end gap-3">
          <SecondaryButton @click="showCloseModal = false">Batal</SecondaryButton>
          <PrimaryButton :disabled="isClosing" @click="handleCloseSession">
            {{ isClosing ? 'Menutup...' : 'Konfirmasi Tutup Shift' }}
          </PrimaryButton>
        </div>
      </div>
    </Modal>
  </AppLayout>
</template>
