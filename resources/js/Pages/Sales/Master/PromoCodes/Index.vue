<!-- Promo Codes Index (§3B) -->
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
import Modal from '@/Components/Modal.vue'
import { useConfirm } from '@/Composables/useConfirmDialog'

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

const deletePromo = (id: number) => {
  confirm({
    title: 'Delete Promo Code?',
    description: 'Are you sure you want to delete this promo code?',
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () => router.delete(route('sales.master.promo-codes.destroy', id)),
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

    <div class="mt-6 rounded-lg border border-border bg-surface-0 overflow-x-auto">
      <table class="w-full text-left text-sm">
        <thead class="bg-surface-50 text-xs text-ink-500 uppercase border-b border-border">
          <tr>
            <th class="py-3 px-4">Code</th>
            <th class="py-3 px-4">Discount</th>
            <th class="py-3 px-4">Validity Range</th>
            <th class="py-3 px-4">Usage</th>
            <th class="py-3 px-4">Status</th>
            <th class="py-3 px-4 text-right">Action</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-border">
          <tr v-for="p in props.promoCodes" :key="p.id" class="hover:bg-surface-50">
            <td class="py-3 px-4 font-mono font-bold text-accent">{{ p.code }}</td>
            <td class="py-3 px-4 font-semibold text-ink-900">
              <span v-if="p.discount_type === 'percentage'">{{ p.discount_value }}% Off</span>
              <span v-else>IDR {{ new Intl.NumberFormat('id-ID').format(p.discount_value) }} Off</span>
            </td>
            <td class="py-3 px-4 font-mono text-xs text-ink-600">{{ p.valid_from }} &rarr; {{ p.valid_to }}</td>
            <td class="py-3 px-4 text-xs font-mono">
              {{ p.usage_count }} / {{ p.usage_limit ?? '&infin;' }}
            </td>
            <td class="py-3 px-4 text-xs font-semibold" :class="p.is_active ? 'text-emerald-600' : 'text-ink-400'">
              {{ p.is_active ? 'Active' : 'Inactive' }}
            </td>
            <td class="py-3 px-4 text-right space-x-2">
              <button
                type="button"
                @click="openEdit(p)"
                class="text-xs font-medium text-accent hover:underline"
              >
                Edit
              </button>
              <button
                type="button"
                @click="deletePromo(p.id)"
                class="text-xs font-medium text-rose-600 hover:underline"
              >
                Delete
              </button>
            </td>
          </tr>
          <tr v-if="props.promoCodes.length === 0">
            <td colspan="6" class="py-8 text-center text-ink-500">No promo codes found.</td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Promo Modal -->
    <Modal :show="showModal" max-width="md" @close="showModal = false">
      <div class="p-6 bg-white rounded-lg">
        <h3 class="text-lg font-semibold text-ink-900">{{ editingPromo ? 'Edit Promo Code' : 'New Promo Code' }}</h3>

        <form @submit.prevent="submit" class="mt-4 space-y-4">
          <FormInput
            label="Promo Code *"
            v-model="form.code"
            :error="form.errors.code"
            placeholder="e.g. FLASH20"
            required
          />

          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block text-xs font-medium text-ink-700 mb-1">Discount Type *</label>
              <select
                v-model="form.discount_type"
                class="w-full rounded border border-border bg-white py-2 px-3 text-sm text-ink-900 focus:outline-none"
              >
                <option value="percentage">Percentage (%)</option>
                <option value="fixed">Fixed Amount (IDR)</option>
              </select>
            </div>
            <div>
              <FormInput
                label="Discount Value *"
                type="number"
                step="any"
                min="0"
                v-model="form.discount_value"
                :error="form.errors.discount_value"
                required
              />
            </div>
          </div>

          <div class="grid grid-cols-2 gap-3">
            <div>
              <FormInput
                label="Valid From *"
                type="date"
                v-model="form.valid_from"
                :error="form.errors.valid_from"
                required
              />
            </div>
            <div>
              <FormInput
                label="Valid To *"
                type="date"
                v-model="form.valid_to"
                :error="form.errors.valid_to"
                required
              />
            </div>
          </div>

          <FormInput
            label="Usage Limit (Optional)"
            type="number"
            min="1"
            v-model="form.usage_limit"
            :error="form.errors.usage_limit"
            placeholder="Unlimited if empty"
          />

          <label class="flex items-center gap-2 text-sm text-ink-900 cursor-pointer pt-2">
            <input type="checkbox" v-model="form.is_active" class="rounded border-border text-accent focus:ring-accent" />
            <span>Active</span>
          </label>

          <div class="flex items-center justify-end gap-2 pt-2">
            <SecondaryButton @click="showModal = false">Cancel</SecondaryButton>
            <PrimaryButton type="submit" :disabled="form.processing">Save Promo Code</PrimaryButton>
          </div>
        </form>
      </div>
    </Modal>
  </AppLayout>
</template>
