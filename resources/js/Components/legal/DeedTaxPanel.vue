<!-- ponytail: deed_taxes tracker (§3K) — computes/tracks only, never files or pays -->
<script setup lang="ts">
import { ref } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import FormInput from '@/Components/forms/FormInput.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'

export interface TaxRow {
  id: number
  tax_type: string
  taxpayer_name: string | null
  base_amount: string
  njop_amount: string | null
  rate: string
  npoptkp_applied: string | null
  computed_amount: string
  billing_code: string | null
  ntpn: string | null
  status: string
}

const props = defineProps<{
  deedId: number
  taxes: TaxRow[]
}>()

const TAX_LABEL: Record<string, string> = {
  pph_final: 'PPh Final (seller)',
  bphtb: 'BPHTB (buyer)',
}

const generateForm = useForm({})
const generate = () => generateForm.post(route('legal.deeds.taxes.generate', props.deedId), { preserveScroll: true })

const openAmounts = ref<number | null>(null)
const amountsForm = useForm({ base_amount: '', njop_amount: '', rate: '', npoptkp_applied: '' })
const editAmounts = (t: TaxRow) => {
  openAmounts.value = t.id
  amountsForm.base_amount = t.base_amount
  amountsForm.njop_amount = t.njop_amount ?? ''
  amountsForm.rate = t.rate
  amountsForm.npoptkp_applied = t.npoptkp_applied ?? ''
}
const submitAmounts = (taxId: number) => {
  amountsForm.patch(route('legal.deeds.taxes.updateAmounts', [props.deedId, taxId]), {
    preserveScroll: true,
    onSuccess: () => { openAmounts.value = null },
  })
}

const billingForm = useForm({ billing_code: '' })
const openBilling = ref<number | null>(null)
const submitBilling = (taxId: number) => {
  billingForm.patch(route('legal.deeds.taxes.issueBillingCode', [props.deedId, taxId]), {
    preserveScroll: true,
    onSuccess: () => { openBilling.value = null; billingForm.reset() },
  })
}

const paidForm = useForm({ ntpn: '' })
const openPaid = ref<number | null>(null)
const submitPaid = (taxId: number) => {
  paidForm.patch(route('legal.deeds.taxes.markPaid', [props.deedId, taxId]), {
    preserveScroll: true,
    onSuccess: () => { openPaid.value = null; paidForm.reset() },
  })
}

const markValidated = (taxId: number) => {
  router.patch(route('legal.deeds.taxes.markValidated', [props.deedId, taxId]), {}, { preserveScroll: true })
}
</script>

<template>
  <div class="space-y-4">
    <div v-if="taxes.length === 0">
      <PrimaryButton :disabled="generateForm.processing" @click="generate">Generate tax records</PrimaryButton>
    </div>

    <div v-for="t in taxes" :key="t.id" class="rounded-sm border border-border p-3">
      <div class="flex items-center justify-between">
        <p class="text-sm font-medium text-ink-900">{{ TAX_LABEL[t.tax_type] ?? t.tax_type }}</p>
        <StatusBadge :status="t.status" />
      </div>
      <p class="mt-0.5 text-xs text-ink-600">
        {{ t.taxpayer_name ?? 'No taxpayer party found' }} · rate {{ t.rate }}% · computed {{ t.computed_amount }}
      </p>

      <div v-if="t.status === 'pending'" class="mt-2 space-y-2">
        <button v-if="openAmounts !== t.id" type="button" class="text-sm font-medium text-accent hover:underline" @click="editAmounts(t)">
          Edit amounts
        </button>
        <form v-else class="space-y-2" @submit.prevent="submitAmounts(t.id)">
          <div class="grid grid-cols-2 gap-2">
            <FormInput v-model="amountsForm.base_amount" name="base_amount" type="number" label="Base (transaction value)" :error="amountsForm.errors.base_amount" />
            <FormInput v-model="amountsForm.njop_amount" name="njop_amount" type="number" label="NJOP" :error="amountsForm.errors.njop_amount" />
            <FormInput v-model="amountsForm.rate" name="rate" type="number" label="Rate (%)" :error="amountsForm.errors.rate" />
            <FormInput v-if="t.tax_type === 'bphtb'" v-model="amountsForm.npoptkp_applied" name="npoptkp_applied" type="number" label="NPOPTKP" :error="amountsForm.errors.npoptkp_applied" />
          </div>
          <PrimaryButton type="submit" :disabled="amountsForm.processing">Save amounts</PrimaryButton>
        </form>

        <button v-if="openBilling !== t.id" type="button" class="block text-sm font-medium text-accent hover:underline" @click="openBilling = t.id">
          Issue billing code
        </button>
        <form v-else class="flex items-end gap-2" @submit.prevent="submitBilling(t.id)">
          <FormInput v-model="billingForm.billing_code" name="billing_code" label="Kode Billing / Coretax" :error="billingForm.errors.billing_code" />
          <PrimaryButton type="submit" :disabled="billingForm.processing">Save</PrimaryButton>
        </form>
      </div>

      <div v-if="t.status === 'billing_code_issued'" class="mt-2">
        <p class="text-xs text-ink-600">Billing code: {{ t.billing_code }}</p>
        <button v-if="openPaid !== t.id" type="button" class="text-sm font-medium text-accent hover:underline" @click="openPaid = t.id">
          Mark paid
        </button>
        <form v-else class="mt-1 flex items-end gap-2" @submit.prevent="submitPaid(t.id)">
          <FormInput v-model="paidForm.ntpn" name="ntpn" label="NTPN" :error="paidForm.errors.ntpn" />
          <PrimaryButton type="submit" :disabled="paidForm.processing">Save</PrimaryButton>
        </form>
      </div>

      <div v-if="t.status === 'paid'" class="mt-2">
        <p class="text-xs text-ink-600">NTPN: {{ t.ntpn }}</p>
        <button type="button" class="text-sm font-medium text-accent hover:underline" @click="markValidated(t.id)">
          Mark validated
        </button>
      </div>
    </div>
  </div>
</template>
