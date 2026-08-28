<!-- Promo Codes Index (§3B) -->
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
import { formatCurrency, formatDate } from '@/Utils/formatters'

interface PromoCode {
  id: number
  code: string
  discount_type: 'percentage' | 'fixed'
  discount_value: number
  valid_from: string
  valid_to: string
  usage_limit: number | null
  usage_count: number
  is_active: boolean
}

const props = defineProps<{
  promoCodes: PromoCode[]
}>()

const search = ref('')
const sort = ref<SortState>(null)
const selected = ref<Array<string | number>>([])

const showModal = ref(false)
const editingPromo = ref<PromoCode | null>(null)

const form = useForm({
  code: '',
  discount_type: 'percentage' as 'percentage' | 'fixed',
  discount_value: 0,
  valid_from: '',
  valid_to: '',
  usage_limit: null as number | null,
  is_active: true,
})

const columns = [
  { key: 'code', label: 'Code', sortable: true },
  { key: 'discount', label: 'Discount' },
  { key: 'validity', label: 'Validity Range' },
  { key: 'usage', label: 'Usage', align: 'center' as const },
  { key: 'is_active', label: 'Status', sortable: true },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

const openCreate = () => {
  editingPromo.value = null
  form.reset()
  form.discount_type = 'percentage'
  form.is_active = true
  showModal.value = true
}

const openEdit = (p: PromoCode) => {
  editingPromo.value = p
  form.code = p.code
  form.discount_type = p.discount_type
  form.discount_value = Number(p.discount_value)
  form.valid_from = p.valid_from
  form.valid_to = p.valid_to
  form.usage_limit = p.usage_limit
  form.is_active = p.is_active
  showModal.value = true
}

const submit = () => {
  if (editingPromo.value) {
    form.put(route('sales.master.promo-codes.update', editingPromo.value.id), {
      onSuccess: () => { showModal.value = false },
    })
  } else {
    form.post(route('sales.master.promo-codes.store'), {
      onSuccess: () => { showModal.value = false },
    })
  }
}

const { confirm } = useConfirm()

const deletePromo = (p: PromoCode) => {
  confirm({
    title: `Delete Promo Code "${p.code}"?`,
    description: 'Are you sure you want to delete this promotional code?',
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () => router.delete(route('sales.master.promo-codes.destroy', p.id)),
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader
      title="Promotional Discount Codes"
      description="Create campaign promo codes with fixed or percentage discounts (§3B)."
    >
      <template #actions>
        <PrimaryButton @click="openCreate">New Promo Code</PrimaryButton>
      </template>
    </PageHeader>

    <div class="mt-4">
      <SalesSubNav active="master" />
    </div>

    <div class="mt-4">
      <SalesMasterSubNav active="promo-codes" />
    </div>

    <div class="mt-6">
      <DataTable
        :columns="columns"
        :items="props.promoCodes"
        v-model:sort="sort"
        v-model:selected="selected"
        v-model:search="search"
        sticky-header
        storage-key="sales.master.promo-codes"
        search-placeholder="Search promo codes…"
        export-filename="sales-promo-codes"
        status-rail-key="is_active"
        empty-title="No promo codes found"
        empty-description="Create promotional codes for campaigns or order incentives."
      >
        <template #cell-code="{ item }">
          <span class="font-mono font-bold text-accent">{{ (item as PromoCode).code }}</span>
        </template>

        <template #cell-discount="{ item }">
          <span v-if="(item as PromoCode).discount_type === 'percentage'" class="font-semibold text-ink-900">
            {{ (item as PromoCode).discount_value }}% Off
          </span>
          <span v-else class="font-semibold font-mono text-ink-900">
            {{ formatCurrency((item as PromoCode).discount_value) }} Off
          </span>
        </template>

        <template #cell-validity="{ item }">
          <span class="font-mono text-xs text-ink-600">
            {{ formatDate((item as PromoCode).valid_from) }} &rarr; {{ formatDate((item as PromoCode).valid_to) }}
          </span>
        </template>

        <template #cell-usage="{ item }">
          <span class="text-xs font-mono text-ink-700">
            {{ (item as PromoCode).usage_count }} / {{ (item as PromoCode).usage_limit ?? 'Unlimited' }}
          </span>
        </template>

        <template #cell-is_active="{ item }">
          <StatusBadge :status="(item as PromoCode).is_active ? 'active' : 'inactive'" />
        </template>

        <template #cell-actions="{ item }">
          <div class="flex items-center justify-end gap-3">
            <button
              type="button"
              @click="openEdit(item as PromoCode)"
              class="text-xs font-semibold text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
            >
              Edit
            </button>
            <button
              type="button"
              @click="deletePromo(item as PromoCode)"
              class="text-xs font-medium text-signal-danger hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
            >
              Delete
            </button>
          </div>
        </template>
      </DataTable>
    </div>

    <!-- Promo Modal -->
    <Modal :show="showModal" max-width="md" @close="showModal = false">
      <div class="p-6 bg-surface-0 border border-border text-ink-900 rounded-lg">
        <h3 class="text-lg font-semibold text-ink-900">{{ editingPromo ? 'Edit Promo Code' : 'New Promo Code' }}</h3>

        <form @submit.prevent="submit" class="mt-4 space-y-4">
          <FormInput
            label="Promo Code"
            name="code"
            v-model="form.code"
            :error="form.errors.code"
            placeholder="e.g. SUMMER2026"
            required
          />

          <div class="grid grid-cols-2 gap-3">
            <FormSelect
              label="Discount Type"
              name="discount_type"
              v-model="form.discount_type"
              :options="[
                { label: 'Percentage (%)', value: 'percentage' },
                { label: 'Fixed Amount (IDR)', value: 'fixed' }
              ]"
              required
            />

            <FormInput
              :label="form.discount_type === 'percentage' ? 'Value (%)' : 'Amount (IDR)'"
              name="discount_value"
              type="number"
              step="any"
              min="0"
              v-model="form.discount_value"
              :error="form.errors.discount_value"
              required
            />
          </div>

          <div class="grid grid-cols-2 gap-3">
            <FormInput
              type="date"
              label="Valid From"
              name="valid_from"
              v-model="form.valid_from"
              :error="form.errors.valid_from"
              required
            />
            <FormInput
              type="date"
              label="Valid To"
              name="valid_to"
              v-model="form.valid_to"
              :error="form.errors.valid_to"
              required
            />
          </div>

          <FormInput
            type="number"
            label="Usage Limit (Optional)"
            name="usage_limit"
            v-model="form.usage_limit"
            :error="form.errors.usage_limit"
            placeholder="Leave blank for unlimited"
          />

          <FormSwitch
            v-model="form.is_active"
            name="is_active"
            label="Active Status"
            description="Enable customers and quotes to redeem this promo code."
          />

          <div class="flex items-center justify-end gap-2 pt-2">
            <SecondaryButton @click="showModal = false">Cancel</SecondaryButton>
            <PrimaryButton type="submit" :disabled="form.processing">Save Promo Code</PrimaryButton>
          </div>
        </form>
      </div>
    </Modal>
  </AppLayout>
</template>
