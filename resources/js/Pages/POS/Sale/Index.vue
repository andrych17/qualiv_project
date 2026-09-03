<!-- ponytail: POS Cashier Register shell (POS_SPECS.md §3E, §3F, §3I) -->
<script setup lang="ts">
import { computed, ref } from 'vue'
import { router, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import DangerButton from '@/Components/DangerButton.vue'
import Modal from '@/Components/Modal.vue'
import { formatCurrency, formatNumber } from '@/Utils/formatters'
import {
  Barcode,
  CreditCard,
  DollarSign,
  Pause,
  Play,
  Plus,
  Minus,
  Trash2,
  QrCode,
  RotateCcw,
  CheckCircle2,
  AlertTriangle,
  User,
  Utensils,
  Clock,
  Layers,
} from 'lucide-vue-next'

interface Terminal {
  id: number
  code: string
  name: string
  profile?: {
    id: number
    name: string
    base_type: string
    table_management: boolean
    touch_menu: boolean
    offline_enabled: boolean
  }
}

interface PosSession {
  id: number
  session_no: string
  terminal_id: number
  status: string
  opened_at: string
  opening_cash: number
  cashier?: {
    id: number
    name: string
  }
}

interface FavoriteItem {
  id: number
  product_id: number
  sort_order: number
  product?: {
    id: number
    code: string
    name: string
    default_price: number
    base_uom?: {
      id: number
      code: string
      name: string
    }
  }
}

interface TxnLine {
  id?: number
  product_id: number
  description: string
  qty: number
  unit_price: number
  discount_amount: number
  line_total: number
}

interface TxnHdr {
  id: number
  txn_no: string
  status: string
  subtotal: number
  tax_amount: number
  discount_amount: number
  grand_total: number
  lines: TxnLine[]
  table?: {
    id: number
    table_no: string
  }
}

interface Table {
  id: number
  table_no: string
  status: string
  active_transaction?: TxnHdr
}

const props = defineProps<{
  terminals: Terminal[]
  selectedTerminalId: number | null
  activeSession: PosSession | null
  favorites: FavoriteItem[]
  parkedOrders: TxnHdr[]
  tables: Table[]
}>()

// State
const barcodeInput = ref('')
const isScanning = ref(false)
const scanError = ref('')

// In-memory cart for quick cashier workflow
interface CartItem {
  product_id: number
  code: string
  name: string
  uom_id: number | null
  qty: number
  unit_price: number
  discount_amount: number
}

const cart = ref<CartItem[]>([])
const selectedTableId = ref<number | null>(null)
const selectedCustomerId = ref<number | null>(null)
const orderNote = ref('')

// Modals
const showPayModal = ref(false)
const showParkedModal = ref(false)
const showOpenSessionModal = ref(false)
const showTableModal = ref(false)

// Open session form
const openingCash = ref<number>(100000)
const isSubmittingSession = ref(false)

// Payment State
const paymentMethod = ref<'cash' | 'card' | 'qris' | 'transfer'>('cash')
const tenderAmount = ref<number>(0)
const tenderReference = ref('')
const isProcessingPayment = ref(false)
const paymentSuccess = ref(false)
const lastChange = ref(0)

// Totals
const subtotal = computed(() => {
  return cart.value.reduce((sum, item) => sum + (item.qty * item.unit_price) - item.discount_amount, 0)
})

const taxAmount = computed(() => {
  return Math.round(subtotal.value * 0.11) // 11% PPN standard
})

const grandTotal = computed(() => {
  return subtotal.value + taxAmount.value
})

const changeDue = computed(() => {
  if (paymentMethod.value !== 'cash') return 0
  return Math.max(0, (tenderAmount.value || 0) - grandTotal.value)
})

// Handlers
const changeTerminal = (terminalId: number) => {
  router.get(route('pos.sale.index'), { terminal_id: terminalId }, { preserveState: false })
}

const openShift = () => {
  if (!props.selectedTerminalId) return
  isSubmittingSession.value = true
  router.post(
    route('pos.sessions.open'),
    {
      terminal_id: props.selectedTerminalId,
      opening_cash: openingCash.value,
    },
    {
      onSuccess: () => {
        showOpenSessionModal.value = false
        isSubmittingSession.value = false
        router.reload()
      },
      onError: () => {
        isSubmittingSession.value = false
      },
    },
  )
}

const handleBarcodeScan = async () => {
  const code = barcodeInput.value.trim()
  if (!code || !props.selectedTerminalId) return

  isScanning.value = true
  scanError.value = ''

  try {
    const res = await fetch(route('pos.sale.scan'), {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || '',
      },
      body: JSON.stringify({
        barcode: code,
        terminal_id: props.selectedTerminalId,
      }),
    })

    if (!res.ok) {
      const err = await res.json()
      scanError.value = err.message || 'Product not found'
      return
    }

    const product = await res.json()
    addToCart({
      product_id: product.id || product.product_id,
      code: product.code,
      name: product.name,
      uom_id: product.uom_id || null,
      unit_price: product.price || product.unit_price || 0,
    })
    barcodeInput.value = ''
  } catch (e: any) {
    scanError.value = e?.message || 'Error scanning barcode'
  } finally {
    isScanning.value = false
  }
}

const addToCart = (product: { product_id: number; code: string; name: string; uom_id?: number | null; unit_price: number }) => {
  const existing = cart.value.find((i) => i.product_id === product.product_id)
  if (existing) {
    existing.qty += 1
  } else {
    cart.value.push({
      product_id: product.product_id,
      code: product.code,
      name: product.name,
      uom_id: product.uom_id ?? null,
      qty: 1,
      unit_price: product.unit_price,
      discount_amount: 0,
    })
  }
}

const addFavorite = (fav: FavoriteItem) => {
  if (!fav.product) return
  addToCart({
    product_id: fav.product_id,
    code: fav.product.code,
    name: fav.product.name,
    uom_id: fav.product.base_uom?.id,
    unit_price: Number(fav.product.default_price || 0),
  })
}

const updateQty = (index: number, delta: number) => {
  const item = cart.value[index]
  if (!item) return
  item.qty += delta
  if (item.qty <= 0) {
    cart.value.splice(index, 1)
  }
}

const removeItem = (index: number) => {
  cart.value.splice(index, 1)
}

const clearCart = () => {
  if (confirm('Clear current cart?')) {
    cart.value = []
    orderNote.value = ''
    selectedTableId.value = null
  }
}

const openPayModal = () => {
  if (cart.value.length === 0) return
  tenderAmount.value = grandTotal.value
  paymentMethod.value = 'cash'
  paymentSuccess.value = false
  showPayModal.value = true
}

const processPayment = async () => {
  if (!props.activeSession) return
  isProcessingPayment.value = true

  try {
    // 1. Create draft txn
    const draftRes = await fetch(route('pos.sale.draft'), {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || '',
      },
      body: JSON.stringify({
        session_id: props.activeSession.id,
        table_id: selectedTableId.value,
        customer_id: selectedCustomerId.value,
      }),
    })

    if (!draftRes.ok) throw new Error('Failed to create draft transaction')
    const txn = await draftRes.json()

    // 2. Add lines
    for (const item of cart.value) {
      await fetch(route('pos.sale.lines.add', { txn: txn.id }), {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || '',
        },
        body: JSON.stringify({
          product_id: item.product_id,
          uom_id: item.uom_id,
          qty: item.qty,
          unit_price: item.unit_price,
          discount_amount: item.discount_amount,
          note: '',
          modifier_ids: [],
        }),
      })
    }

    // 3. Process payment
    const payRes = await fetch(route('pos.sale.pay', { txn: txn.id }), {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || '',
      },
      body: JSON.stringify({
        tender_type: paymentMethod.value,
        amount: tenderAmount.value,
        tender_reference: tenderReference.value,
      }),
    })

    if (!payRes.ok) throw new Error('Payment processing failed')

    // 4. Complete txn
    const compRes = await fetch(route('pos.sale.complete', { txn: txn.id }), {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || '',
      },
    })

    if (!compRes.ok) throw new Error('Failed to complete sale')

    lastChange.value = changeDue.value
    paymentSuccess.value = true
    cart.value = []
    selectedTableId.value = null
  } catch (e: any) {
    alert(e?.message || 'Transaction error')
  } finally {
    isProcessingPayment.value = false
  }
}

const closePayModal = () => {
  showPayModal.value = false
  if (paymentSuccess.value) {
    router.reload({ only: ['parkedOrders', 'tables'] })
  }
}

const parkCurrentOrder = async () => {
  if (cart.value.length === 0 || !props.activeSession) return
  const note = prompt('Enter note for parked order:', 'Hold Order') || 'Held Order'

  try {
    const draftRes = await fetch(route('pos.sale.draft'), {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || '',
      },
      body: JSON.stringify({
        session_id: props.activeSession.id,
        table_id: selectedTableId.value,
      }),
    })
    const txn = await draftRes.json()

    for (const item of cart.value) {
      await fetch(route('pos.sale.lines.add', { txn: txn.id }), {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || '',
        },
        body: JSON.stringify({
          product_id: item.product_id,
          uom_id: item.uom_id,
          qty: item.qty,
          unit_price: item.unit_price,
          discount_amount: item.discount_amount,
        }),
      })
    }

    await fetch(route('pos.sale.park', { txn: txn.id }), {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || '',
      },
      body: JSON.stringify({ note }),
    })

    cart.value = []
    router.reload({ only: ['parkedOrders'] })
  } catch (e: any) {
    alert('Failed to park order: ' + e?.message)
  }
}

const resumeParkedOrder = async (order: TxnHdr) => {
  cart.value = order.lines.map((l) => ({
    product_id: l.product_id,
    code: '',
    name: l.description,
    uom_id: null,
    qty: Number(l.qty),
    unit_price: Number(l.unit_price),
    discount_amount: Number(l.discount_amount),
  }))
  selectedTableId.value = order.table?.id ?? null
  showParkedModal.value = false
}
</script>

<template>
  <AppLayout>
    <!-- Top POS Toolbar -->
    <div class="mb-4 flex flex-wrap items-center justify-between gap-4 rounded-lg bg-surface-100 p-3 shadow-sm">
      <div class="flex items-center gap-3">
        <label class="text-sm font-semibold text-ink-700">Terminal:</label>
        <select
          :value="selectedTerminalId"
          class="rounded-md border-surface-300 bg-white py-1.5 pl-3 pr-8 text-sm focus:border-primary-500 focus:ring-primary-500"
          @change="changeTerminal(Number(($event.target as HTMLSelectElement).value))"
        >
          <option v-for="t in terminals" :key="t.id" :value="t.id">
            {{ t.name }} ({{ t.code }}) - {{ t.profile?.name }}
          </option>
        </select>

        <span
          v-if="activeSession"
          class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-medium text-emerald-700 ring-1 ring-inset ring-emerald-600/20"
        >
          <span class="h-1.5 w-1.5 rounded-full bg-emerald-600"></span>
          Shift: {{ activeSession.session_no }} ({{ activeSession.cashier?.name || 'Cashier' }})
        </span>
        <span
          v-else
          class="inline-flex items-center gap-1.5 rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-medium text-amber-700 ring-1 ring-inset ring-amber-600/20"
        >
          <AlertTriangle class="h-3.5 w-3.5" />
          No Open Shift
        </span>
      </div>

      <div class="flex items-center gap-2">
        <button
          v-if="parkedOrders && parkedOrders.length > 0"
          class="relative inline-flex items-center gap-1.5 rounded-md bg-amber-100 px-3 py-1.5 text-xs font-semibold text-amber-900 hover:bg-amber-200"
          @click="showParkedModal = true"
        >
          <Pause class="h-3.5 w-3.5" />
          Parked ({{ parkedOrders.length }})
        </button>

        <button
          v-if="terminals.find((t) => t.id === selectedTerminalId)?.profile?.table_management"
          class="inline-flex items-center gap-1.5 rounded-md bg-surface-200 px-3 py-1.5 text-xs font-semibold text-ink-800 hover:bg-surface-300"
          @click="showTableModal = true"
        >
          <Utensils class="h-3.5 w-3.5" />
          {{ selectedTableId ? `Table #${tables.find((t) => t.id === selectedTableId)?.table_no}` : 'Select Table' }}
        </button>

        <Link
          :href="route('pos.sessions.index')"
          class="inline-flex items-center gap-1.5 rounded-md border border-surface-300 bg-white px-3 py-1.5 text-xs font-semibold text-ink-700 shadow-sm hover:bg-surface-50"
        >
          <Clock class="h-3.5 w-3.5" />
          Shifts & Cash
        </Link>
      </div>
    </div>

    <!-- Active Session Banner / Notice if closed -->
    <div v-if="!activeSession" class="mb-6 rounded-lg border border-amber-200 bg-amber-50 p-6 text-center shadow-sm">
      <AlertTriangle class="mx-auto h-10 w-10 text-amber-500" />
      <h3 class="mt-2 text-base font-semibold text-amber-900">Shift Belum Dibuka</h3>
      <p class="mt-1 text-sm text-amber-700">Buka shift kasir terlebih dahulu untuk mulai melayani transaksi pada terminal ini.</p>
      <div class="mt-4">
        <PrimaryButton class="px-5 py-2.5 text-sm" @click="showOpenSessionModal = true">
          Buka Shift Sekarang
        </PrimaryButton>
      </div>
    </div>

    <!-- POS Register Layout -->
    <div v-else class="grid grid-cols-1 gap-6 lg:grid-cols-12">
      <!-- Left: Catalog & Barcode Scanner (7 cols) -->
      <div class="space-y-4 lg:col-span-7">
        <!-- Barcode Input Bar -->
        <div class="rounded-lg bg-white p-3 shadow-sm ring-1 ring-surface-200">
          <form class="flex items-center gap-2" @submit.prevent="handleBarcodeScan">
            <div class="relative flex-1">
              <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-ink-400">
                <Barcode class="h-5 w-5" />
              </div>
              <input
                v-model="barcodeInput"
                type="text"
                autofocus
                placeholder="Scan Barcode atau masukkan Kode Produk..."
                class="block w-full rounded-md border-surface-300 pl-10 pr-4 text-sm focus:border-primary-500 focus:ring-primary-500"
              />
            </div>
            <PrimaryButton type="submit" :disabled="isScanning" class="py-2">
              <span v-if="isScanning">Cari...</span>
              <span v-else>Tambah</span>
            </PrimaryButton>
          </form>
          <p v-if="scanError" class="mt-1.5 text-xs text-rose-600">{{ scanError }}</p>
        </div>

        <!-- Touch Favorites / Quick Items -->
        <Panel title="Menu Favorit / Cepat" subtitle="Tekan item untuk memasukkan ke keranjang kasir">
          <div v-if="favorites && favorites.length > 0" class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4">
            <button
              v-for="fav in favorites"
              :key="fav.id"
              class="flex flex-col justify-between rounded-lg border border-surface-200 bg-surface-50 p-3 text-left transition hover:border-primary-500 hover:bg-primary-50/30 active:scale-95"
              @click="addFavorite(fav)"
            >
              <div>
                <p class="text-xs font-medium text-ink-500">{{ fav.product?.code }}</p>
                <h4 class="line-clamp-2 text-sm font-semibold text-ink-900">{{ fav.product?.name }}</h4>
              </div>
              <p class="mt-2 font-mono text-sm font-bold text-primary-700">
                {{ formatCurrency(fav.product?.default_price) }}
              </p>
            </button>
          </div>
          <div v-else class="py-8 text-center text-sm text-ink-500">
            Belum ada item favorit diatur untuk terminal ini.
          </div>
        </Panel>
      </div>

      <!-- Right: Cart & Settle Panel (5 cols) -->
      <div class="lg:col-span-5">
        <div class="flex h-full flex-col justify-between rounded-lg bg-white p-4 shadow-sm ring-1 ring-surface-200">
          <div>
            <div class="flex items-center justify-between border-b border-surface-100 pb-3">
              <h3 class="text-base font-bold text-ink-900">Keranjang Kasir</h3>
              <button
                v-if="cart.length > 0"
                class="text-xs text-rose-600 hover:text-rose-800"
                @click="clearCart"
              >
                Kosongkan
              </button>
            </div>

            <!-- Items List -->
            <div class="mt-3 max-h-[380px] space-y-2 overflow-y-auto pr-1">
              <div
                v-for="(item, idx) in cart"
                :key="item.product_id"
                class="flex items-center justify-between rounded-md border border-surface-100 bg-surface-50/50 p-2 text-sm"
              >
                <div class="min-w-0 flex-1 pr-2">
                  <p class="truncate font-medium text-ink-900">{{ item.name }}</p>
                  <p class="font-mono text-xs text-ink-500">{{ formatCurrency(item.unit_price) }} / unit</p>
                </div>

                <div class="flex items-center gap-2">
                  <div class="flex items-center rounded border border-surface-200 bg-white">
                    <button
                      class="px-2 py-1 text-ink-600 hover:bg-surface-100"
                      @click="updateQty(idx, -1)"
                    >
                      <Minus class="h-3.5 w-3.5" />
                    </button>
                    <span class="w-8 text-center font-mono text-xs font-semibold">{{ item.qty }}</span>
                    <button
                      class="px-2 py-1 text-ink-600 hover:bg-surface-100"
                      @click="updateQty(idx, 1)"
                    >
                      <Plus class="h-3.5 w-3.5" />
                    </button>
                  </div>
                  <span class="w-20 text-right font-mono text-sm font-semibold text-ink-900">
                    {{ formatCurrency(item.qty * item.unit_price) }}
                  </span>
                  <button
                    class="text-surface-400 hover:text-rose-600"
                    @click="removeItem(idx)"
                  >
                    <Trash2 class="h-4 w-4" />
                  </button>
                </div>
              </div>

              <div v-if="cart.length === 0" class="py-12 text-center text-sm text-ink-400">
                Keranjang masih kosong. Scan barcode atau pilih item favorit.
              </div>
            </div>
          </div>

          <!-- Bottom Summary & Settle -->
          <div class="mt-4 border-t border-surface-200 pt-4">
            <div class="space-y-1.5 text-sm">
              <div class="flex justify-between text-ink-600">
                <span>Subtotal</span>
                <span class="font-mono">{{ formatCurrency(subtotal) }}</span>
              </div>
              <div class="flex justify-between text-ink-600">
                <span>PPN (11%)</span>
                <span class="font-mono">{{ formatCurrency(taxAmount) }}</span>
              </div>
              <div class="flex justify-between border-t border-surface-200 pt-2 text-base font-bold text-ink-900">
                <span>Total Bayar</span>
                <span class="font-mono text-primary-700">{{ formatCurrency(grandTotal) }}</span>
              </div>
            </div>

            <div class="mt-4 grid grid-cols-2 gap-2">
              <SecondaryButton
                class="justify-center py-2.5 text-xs font-semibold"
                :disabled="cart.length === 0"
                @click="parkCurrentOrder"
              >
                <Pause class="mr-1 h-3.5 w-3.5" />
                Parkir (Hold)
              </SecondaryButton>
              <PrimaryButton
                class="justify-center py-2.5 text-sm font-bold"
                :disabled="cart.length === 0"
                @click="openPayModal"
              >
                Bayar Sekarang
              </PrimaryButton>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Modal: Open Session -->
    <Modal :show="showOpenSessionModal" @close="showOpenSessionModal = false">
      <div class="p-6">
        <h3 class="text-lg font-bold text-ink-900">Buka Shift Kasir Baru</h3>
        <p class="mt-1 text-sm text-ink-600">Masukkan modal awal (float cash) di laci kasir saat membuka shift.</p>

        <div class="mt-4">
          <label class="block text-sm font-medium text-ink-700">Modal Kas Awal (IDR)</label>
          <input
            v-model.number="openingCash"
            type="number"
            min="0"
            step="1000"
            class="mt-1 block w-full rounded-md border-surface-300 font-mono text-base focus:border-primary-500 focus:ring-primary-500"
          />
        </div>

        <div class="mt-6 flex justify-end gap-3">
          <SecondaryButton @click="showOpenSessionModal = false">Batal</SecondaryButton>
          <PrimaryButton :disabled="isSubmittingSession" @click="openShift">
            {{ isSubmittingSession ? 'Membuka...' : 'Buka Shift' }}
          </PrimaryButton>
        </div>
      </div>
    </Modal>

    <!-- Modal: Payment / Settle -->
    <Modal :show="showPayModal" @close="closePayModal">
      <div class="p-6">
        <div v-if="paymentSuccess" class="py-4 text-center">
          <CheckCircle2 class="mx-auto h-12 w-12 text-emerald-500" />
          <h3 class="mt-2 text-lg font-bold text-ink-900">Pembayaran Berhasil!</h3>
          <p class="mt-1 text-sm text-ink-600">Transaksi selesai dicatat dan struk siap dicetak.</p>

          <div v-if="lastChange > 0" class="mt-4 rounded-lg bg-emerald-50 p-4 text-emerald-900">
            <span class="text-xs uppercase tracking-wider font-semibold">Kembalian</span>
            <p class="font-mono text-2xl font-bold">{{ formatCurrency(lastChange) }}</p>
          </div>

          <div class="mt-6">
            <PrimaryButton class="w-full justify-center py-2.5 text-sm" @click="closePayModal">
              Selesai / Transaksi Baru
            </PrimaryButton>
          </div>
        </div>

        <div v-else>
          <h3 class="text-lg font-bold text-ink-900">Penyelesaian Pembayaran</h3>
          <p class="mt-1 text-sm text-ink-500">Pilih metode pembayaran dan masukkan jumlah bayar.</p>

          <div class="mt-4 rounded-lg bg-surface-100 p-3 text-center">
            <span class="text-xs font-semibold text-ink-500">TOTAL TAGIHAN</span>
            <p class="font-mono text-2xl font-extrabold text-primary-700">{{ formatCurrency(grandTotal) }}</p>
          </div>

          <!-- Method Tabs -->
          <div class="mt-4 grid grid-cols-4 gap-2">
            <button
              type="button"
              :class="[
                'flex flex-col items-center rounded-lg border p-2.5 text-xs font-semibold transition',
                paymentMethod === 'cash' ? 'border-primary-600 bg-primary-50 text-primary-900' : 'border-surface-200 bg-white text-ink-700 hover:bg-surface-50'
              ]"
              @click="paymentMethod = 'cash'"
            >
              <DollarSign class="h-4 w-4" />
              Tunai
            </button>
            <button
              type="button"
              :class="[
                'flex flex-col items-center rounded-lg border p-2.5 text-xs font-semibold transition',
                paymentMethod === 'card' ? 'border-primary-600 bg-primary-50 text-primary-900' : 'border-surface-200 bg-white text-ink-700 hover:bg-surface-50'
              ]"
              @click="paymentMethod = 'card'"
            >
              <CreditCard class="h-4 w-4" />
              Kartu
            </button>
            <button
              type="button"
              :class="[
                'flex flex-col items-center rounded-lg border p-2.5 text-xs font-semibold transition',
                paymentMethod === 'qris' ? 'border-primary-600 bg-primary-50 text-primary-900' : 'border-surface-200 bg-white text-ink-700 hover:bg-surface-50'
              ]"
              @click="paymentMethod = 'qris'"
            >
              <QrCode class="h-4 w-4" />
              QRIS
            </button>
            <button
              type="button"
              :class="[
                'flex flex-col items-center rounded-lg border p-2.5 text-xs font-semibold transition',
                paymentMethod === 'transfer' ? 'border-primary-600 bg-primary-50 text-primary-900' : 'border-surface-200 bg-white text-ink-700 hover:bg-surface-50'
              ]"
              @click="paymentMethod = 'transfer'"
            >
              <RotateCcw class="h-4 w-4" />
              Transfer
            </button>
          </div>

          <!-- Cash Input -->
          <div v-if="paymentMethod === 'cash'" class="mt-4 space-y-3">
            <div>
              <label class="block text-xs font-semibold text-ink-700">Uang Diterima</label>
              <input
                v-model.number="tenderAmount"
                type="number"
                min="0"
                step="1000"
                class="mt-1 block w-full rounded-md border-surface-300 font-mono text-lg font-bold focus:border-primary-500 focus:ring-primary-500"
              />
            </div>

            <!-- Quick Cash Chips -->
            <div class="flex flex-wrap gap-2">
              <button
                type="button"
                class="rounded border border-surface-200 bg-white px-2.5 py-1 text-xs font-medium text-ink-700 hover:bg-surface-50"
                @click="tenderAmount = grandTotal"
              >
                Uang Pas
              </button>
              <button
                type="button"
                class="rounded border border-surface-200 bg-white px-2.5 py-1 text-xs font-medium text-ink-700 hover:bg-surface-50"
                @click="tenderAmount = 50000"
              >
                50.000
              </button>
              <button
                type="button"
                class="rounded border border-surface-200 bg-white px-2.5 py-1 text-xs font-medium text-ink-700 hover:bg-surface-50"
                @click="tenderAmount = 100000"
              >
                100.000
              </button>
              <button
                type="button"
                class="rounded border border-surface-200 bg-white px-2.5 py-1 text-xs font-medium text-ink-700 hover:bg-surface-50"
                @click="tenderAmount = 200000"
              >
                200.000
              </button>
            </div>

            <div class="flex justify-between border-t border-surface-200 pt-2 text-sm font-semibold">
              <span class="text-ink-600">Kembalian:</span>
              <span class="font-mono text-emerald-600">{{ formatCurrency(changeDue) }}</span>
            </div>
          </div>

          <!-- Non-Cash Ref -->
          <div v-else class="mt-4">
            <label class="block text-xs font-semibold text-ink-700">Nomor Referensi / Trace / Approval Code</label>
            <input
              v-model="tenderReference"
              type="text"
              placeholder="Contoh: TRACE-892182"
              class="mt-1 block w-full rounded-md border-surface-300 text-sm focus:border-primary-500 focus:ring-primary-500"
            />
          </div>

          <div class="mt-6 flex justify-end gap-3">
            <SecondaryButton @click="closePayModal">Batal</SecondaryButton>
            <PrimaryButton
              :disabled="isProcessingPayment || (paymentMethod === 'cash' && tenderAmount < grandTotal)"
              @click="processPayment"
            >
              {{ isProcessingPayment ? 'Memproses...' : 'Proses Pembayaran' }}
            </PrimaryButton>
          </div>
        </div>
      </div>
    </Modal>

    <!-- Modal: Parked Orders -->
    <Modal :show="showParkedModal" @close="showParkedModal = false">
      <div class="p-6">
        <h3 class="text-lg font-bold text-ink-900">Daftar Pesanan Terparkir (Hold)</h3>
        <div class="mt-4 space-y-2">
          <div
            v-for="order in parkedOrders"
            :key="order.id"
            class="flex items-center justify-between rounded-lg border border-surface-200 bg-surface-50 p-3 text-sm"
          >
            <div>
              <span class="font-mono font-bold text-ink-900">{{ order.txn_no }}</span>
              <p class="text-xs text-ink-500">{{ order.lines?.length || 0 }} item • {{ formatCurrency(order.grand_total) }}</p>
            </div>
            <PrimaryButton class="py-1.5 text-xs" @click="resumeParkedOrder(order)">
              Lanjutkan Pesanan
            </PrimaryButton>
          </div>
          <div v-if="!parkedOrders || parkedOrders.length === 0" class="py-6 text-center text-sm text-ink-500">
            Tidak ada pesanan yang diparkir.
          </div>
        </div>
      </div>
    </Modal>
  </AppLayout>
</template>
