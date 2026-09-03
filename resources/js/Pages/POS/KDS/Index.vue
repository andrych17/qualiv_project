<!-- ponytail: POS Kitchen Display System (POS_SPECS.md §3O) -->
<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import axios from 'axios'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import { Utensils, CheckCircle2, Clock, AlertCircle } from 'lucide-vue-next'

interface Station {
  id: number
  code: string
  name: string
}

interface KdsItem {
  id: number
  txn_id: number
  txn_no: string
  table_no: string | null
  description: string
  qty: number
  kds_status: 'pending' | 'preparing' | 'ready' | 'served'
  created_at: string
  note?: string
}

const props = defineProps<{
  stations: Station[]
  selectedStationId: number | null
  initialQueue: KdsItem[]
}>()

const items = ref<KdsItem[]>([...props.initialQueue])

const filterStation = (stationId: number) => {
  router.get(route('pos.kds.index'), { station_id: stationId }, { preserveState: false })
}

const updateStatus = async (item: KdsItem, nextStatus: 'preparing' | 'ready' | 'served') => {
  try {
    await axios.post(route('pos.kds.updateStatus', { line: item.id }), {
      status: nextStatus,
    })
    item.kds_status = nextStatus
    if (nextStatus === 'served') {
      items.value = items.value.filter((i) => i.id !== item.id)
    }
  } catch (e: any) {
    alert(e.response?.data?.message || e?.message || 'Failed to update status')
  }
}
</script>

<template>
  <AppLayout>
    <div class="flex flex-wrap items-center justify-between gap-4 border-b border-surface-200 pb-4">
      <div>
        <h1 class="text-xl font-bold text-ink-900">Kitchen Display System (KDS)</h1>
        <p class="text-xs text-ink-500">Antrean pesanan dapur secara waktu-nyata per stasiun persiapan.</p>
      </div>

      <div class="flex items-center gap-2">
        <label class="text-xs font-semibold text-ink-700">Stasiun Dapur:</label>
        <select
          :value="selectedStationId"
          class="rounded-md border-surface-300 bg-white py-1 pl-3 pr-8 text-xs focus:border-primary-500 focus:ring-primary-500"
          @change="filterStation(Number(($event.target as HTMLSelectElement).value))"
        >
          <option v-for="s in stations" :key="s.id" :value="s.id">
            {{ s.name }} ({{ s.code }})
          </option>
        </select>
      </div>
    </div>

    <!-- Kitchen Orders Grid -->
    <div class="mt-6">
      <div v-if="items.length > 0" class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
        <div
          v-for="item in items"
          :key="item.id"
          :class="[
            'flex flex-col justify-between rounded-xl border p-4 shadow-sm transition',
            item.kds_status === 'ready'
              ? 'border-emerald-300 bg-emerald-50/50'
              : item.kds_status === 'preparing'
              ? 'border-amber-300 bg-amber-50/50'
              : 'border-surface-200 bg-white'
          ]"
        >
          <div>
            <div class="flex items-center justify-between border-b border-surface-100 pb-2">
              <span class="font-mono text-sm font-bold text-ink-900">#{{ item.txn_no }}</span>
              <span class="rounded bg-surface-100 px-2 py-0.5 font-mono text-xs font-semibold text-ink-800">
                {{ item.table_no ? `Meja ${item.table_no}` : 'Takeaway' }}
              </span>
            </div>

            <div class="mt-3">
              <div class="flex items-baseline justify-between">
                <h4 class="text-base font-bold text-ink-900">{{ item.description }}</h4>
                <span class="rounded-full bg-primary-100 px-2 py-0.5 font-mono text-xs font-extrabold text-primary-800">
                  {{ item.qty }}x
                </span>
              </div>
              <p v-if="item.note" class="mt-1 text-xs italic text-amber-700">
                Catatan: {{ item.note }}
              </p>
            </div>
          </div>

          <div class="mt-4 pt-3 border-t border-surface-100">
            <div class="mb-3 flex items-center justify-between text-xs text-ink-500">
              <span class="capitalize font-semibold">Status: {{ item.kds_status }}</span>
              <span class="flex items-center gap-1">
                <Clock class="h-3 w-3" />
                {{ item.created_at }}
              </span>
            </div>

            <div class="grid grid-cols-2 gap-2">
              <button
                v-if="item.kds_status === 'pending'"
                class="col-span-2 rounded-lg bg-amber-500 py-2 text-xs font-bold text-white shadow-sm hover:bg-amber-600 active:scale-95"
                @click="updateStatus(item, 'preparing')"
              >
                Mulai Masak
              </button>
              <button
                v-else-if="item.kds_status === 'preparing'"
                class="col-span-2 rounded-lg bg-emerald-600 py-2 text-xs font-bold text-white shadow-sm hover:bg-emerald-700 active:scale-95"
                @click="updateStatus(item, 'ready')"
              >
                Selesai / Siap Antar
              </button>
              <button
                v-else-if="item.kds_status === 'ready'"
                class="col-span-2 rounded-lg bg-ink-800 py-2 text-xs font-bold text-white shadow-sm hover:bg-black active:scale-95"
                @click="updateStatus(item, 'served')"
              >
                Telah Diantar
              </button>
            </div>
          </div>
        </div>
      </div>

      <div v-else class="rounded-lg border border-dashed border-surface-300 bg-surface-50 p-16 text-center text-ink-500">
        <CheckCircle2 class="mx-auto h-10 w-10 text-emerald-500" />
        <h3 class="mt-2 text-base font-bold text-ink-900">Dapur Bersih!</h3>
        <p class="mt-1 text-xs text-ink-500">Semua pesanan pada stasiun ini telah selesai diproses.</p>
      </div>
    </div>
  </AppLayout>
</template>
