<!-- ponytail: POS Floors & Tables Management (POS_SPECS.md §3M) -->
<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import Modal from '@/Components/Modal.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import { formatCurrency } from '@/Utils/formatters'
import { Utensils, Plus, Users, CheckCircle2, Clock } from 'lucide-vue-next'

interface TxnLine {
  id: number
  description: string
  qty: number
  line_total: number
}

interface ActiveTxn {
  id: number
  txn_no: string
  status: string
  grand_total: number
  lines: TxnLine[]
}

interface Table {
  id: number
  floor_id: number
  table_no: string
  capacity: number
  status: 'available' | 'occupied' | 'reserved' | 'cleaning'
  active_transaction?: ActiveTxn | null
}

interface Floor {
  id: number
  name: string
  tables: Table[]
}

const props = defineProps<{
  floors: Floor[]
}>()

const activeFloorId = ref<number>(props.floors[0]?.id || 0)

const activeFloor = () => {
  return props.floors.find((f) => f.id === activeFloorId.value) || props.floors[0]
}

// Modal Form: Add Table
const showTableModal = ref(false)
const tableForm = ref({
  floor_id: activeFloorId.value,
  table_no: '',
  capacity: 4,
})
const isSubmittingTable = ref(false)

const handleCreateTable = () => {
  isSubmittingTable.value = true
  router.post(
    route('pos.tables.store'),
    {
      ...tableForm.value,
      floor_id: activeFloorId.value,
    },
    {
      onSuccess: () => {
        showTableModal.value = false
        isSubmittingTable.value = false
        tableForm.value.table_no = ''
      },
      onError: () => {
        isSubmittingTable.value = false
      },
    },
  )
}

// Modal Detail: Table active bill
const selectedTable = ref<Table | null>(null)
const showDetailModal = ref(false)

const viewTable = (table: Table) => {
  selectedTable.value = table
  showDetailModal.value = true
}
</script>

<template>
  <AppLayout>
    <PageHeader
      title="Manajemen Lantai & Meja (Restaurant)"
      description="Visualisasi tata letak meja restoran, status okupansi, dan pantauan tagihan aktif."
    >
      <template #actions>
        <PrimaryButton class="inline-flex items-center gap-1.5" @click="showTableModal = true">
          <Plus class="h-4 w-4" />
          Tambah Meja
        </PrimaryButton>
      </template>
    </PageHeader>

    <!-- Floor Tabs -->
    <div v-if="floors.length > 0" class="mt-6 flex border-b border-border">
      <button
        v-for="floor in floors"
        :key="floor.id"
        :class="[
          'border-b-2 px-4 py-2.5 text-sm font-semibold transition',
          activeFloorId === floor.id
            ? 'border-accent text-accent'
            : 'border-transparent text-ink-600 hover:border-border hover:text-ink-900'
        ]"
        @click="activeFloorId = floor.id"
      >
        {{ floor.name }} ({{ floor.tables?.length || 0 }} Meja)
      </button>
    </div>

    <!-- Tables Grid -->
    <div class="mt-6">
      <div v-if="activeFloor() && activeFloor()?.tables.length > 0" class="grid grid-cols-2 gap-4 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6">
        <div
          v-for="table in activeFloor()?.tables"
          :key="table.id"
          :class="[
            'relative flex flex-col justify-between rounded-xl border p-4 shadow-sm transition hover:shadow cursor-pointer',
            table.status === 'occupied'
              ? 'border-signal-danger/40 bg-signal-danger/10 hover:bg-signal-danger/15'
              : table.status === 'reserved'
              ? 'border-signal-warning/40 bg-signal-warning/10 hover:bg-signal-warning/15'
              : 'border-border bg-surface-0 hover:border-accent hover:bg-surface-50'
          ]"
          @click="viewTable(table)"
        >
          <div class="flex items-start justify-between">
            <span class="font-mono text-lg font-extrabold text-ink-900">
              #{{ table.table_no }}
            </span>
            <span
              :class="[
                'inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-bold uppercase border',
                table.status === 'occupied'
                  ? 'border-signal-danger/30 bg-signal-danger/20 text-signal-danger'
                  : table.status === 'reserved'
                  ? 'border-signal-warning/30 bg-signal-warning/20 text-signal-warning'
                  : 'border-signal-success/30 bg-signal-success/20 text-signal-success'
              ]"
            >
              {{ table.status }}
            </span>
          </div>

          <div class="mt-3">
            <div class="flex items-center gap-1 text-xs text-ink-600">
              <Users class="h-3.5 w-3.5" />
              <span>{{ table.capacity }} Kursi</span>
            </div>

            <div v-if="table.active_transaction" class="mt-2 border-t border-border pt-2 font-mono text-xs font-bold text-signal-danger">
              {{ formatCurrency(table.active_transaction.grand_total) }}
            </div>
            <div v-else class="mt-2 border-t border-transparent pt-2 text-xs text-ink-600">
              Kosong
            </div>
          </div>
        </div>
      </div>

      <div v-else class="rounded-lg border border-dashed border-border bg-surface-0 p-12 text-center text-ink-600">
        <Utensils class="mx-auto h-8 w-8 text-ink-600" />
        <p class="mt-2 text-sm font-medium">Belum ada meja yang terdaftar di lantai ini.</p>
      </div>
    </div>

    <!-- Modal Detail Meja -->
    <Modal :show="showDetailModal" @close="showDetailModal = false">
      <div v-if="selectedTable" class="p-6">
        <div class="flex items-center justify-between border-b border-border pb-3">
          <h3 class="text-lg font-bold text-ink-900">Meja #{{ selectedTable.table_no }}</h3>
          <span class="rounded-full border border-border bg-surface-50 px-2.5 py-1 text-xs font-semibold capitalize text-ink-900">
            {{ selectedTable.status }}
          </span>
        </div>

        <div v-if="selectedTable.active_transaction" class="mt-4">
          <p class="text-xs text-ink-600">No. Tagihan: {{ selectedTable.active_transaction.txn_no }}</p>
          <div class="mt-2 max-h-48 space-y-2 overflow-y-auto pr-1 text-sm">
            <div
              v-for="item in selectedTable.active_transaction.lines"
              :key="item.id"
              class="flex justify-between border-b border-border pb-1 text-ink-900"
            >
              <span>{{ item.qty }}x {{ item.description }}</span>
              <span class="font-mono font-medium">{{ formatCurrency(item.line_total) }}</span>
            </div>
          </div>

          <div class="mt-4 flex justify-between border-t border-border pt-2 font-bold text-ink-900">
            <span>Total Tagihan</span>
            <span class="font-mono text-signal-danger">{{ formatCurrency(selectedTable.active_transaction.grand_total) }}</span>
          </div>
        </div>
        <div v-else class="py-8 text-center text-sm text-ink-600">
          Meja ini sedang tidak memiliki pesanan aktif.
        </div>

        <div class="mt-6 flex justify-end">
          <SecondaryButton @click="showDetailModal = false">Tutup</SecondaryButton>
        </div>
      </div>
    </Modal>

    <!-- Modal Tambah Meja -->
    <Modal :show="showTableModal" @close="showTableModal = false">
      <div class="p-6">
        <h3 class="text-lg font-bold text-ink-900">Tambah Meja Baru</h3>
        <p class="mt-1 text-sm text-ink-600">Daftarkan nomor meja dan kapasitas kursi pada lantai aktif.</p>

        <div class="mt-4 space-y-4">
          <FormInput
            v-model="tableForm.table_no"
            label="Nomor / Nama Meja"
            placeholder="Contoh: 01, VIP-A"
            required
          />

          <FormInput
            v-model.number="tableForm.capacity"
            type="number"
            label="Kapasitas Kursi"
            placeholder="4"
            required
          />
        </div>

        <div class="mt-6 flex justify-end gap-3">
          <SecondaryButton @click="showTableModal = false">Batal</SecondaryButton>
          <PrimaryButton :disabled="isSubmittingTable || !tableForm.table_no" @click="handleCreateTable">
            {{ isSubmittingTable ? 'Menyimpan...' : 'Simpan Meja' }}
          </PrimaryButton>
        </div>
      </div>
    </Modal>
  </AppLayout>
</template>
