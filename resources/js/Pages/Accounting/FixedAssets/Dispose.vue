<!-- ponytail: Accounting §3G dispose an asset — sale (proceeds > 0) or write-off (proceeds = 0), one-shot form. -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormCurrencyInput from '@/Components/forms/FormCurrencyInput.vue'
import FormSearchableSelect from '@/Components/forms/FormSearchableSelect.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import { formatCurrency } from '@/Utils/formatters'

const props = defineProps<{
  asset: { id: number; company_id: number; asset_no: string; name: string; acquisition_cost: string }
  accounts: Array<{ value: number; label: string }>
}>()

const today = new Date().toISOString().slice(0, 10)

const form = useForm({
  disposal_date: today,
  proceeds: 0 as number | null,
  proceeds_gl_account_id: null as number | null,
  gain_loss_gl_account_id: null as number | null,
  notes: '',
})

const submit = () => form.transform((data) => ({ ...data, proceeds: Number(data.proceeds) || 0 })).post(route('accounting.fixed-assets.dispose.store', props.asset.id))
</script>

<template>
  <AppLayout>
    <PageHeader :title="`Dispose Asset — ${asset.asset_no}`" :description="`${asset.name} — Acquisition Cost: ${formatCurrency(Number(asset.acquisition_cost))}`" />

    <Panel class="mt-6 max-w-xl">
      <form class="space-y-4" @submit.prevent="submit">
        <FormInput v-model="form.disposal_date" name="disposal_date" type="date" label="Disposal Date" :error="form.errors.disposal_date" required />
        <FormCurrencyInput v-model="form.proceeds" name="proceeds" label="Disposal Proceeds (0 = Full Write-Off)" :error="form.errors.proceeds" required />
        <FormSearchableSelect
          v-if="Number(form.proceeds) > 0"
          v-model="form.proceeds_gl_account_id"
          name="proceeds_gl_account_id"
          label="Proceeds Account (Cash / AR Receivable)"
          :options="accounts"
          :error="form.errors.proceeds_gl_account_id"
          required
        />
        <FormSearchableSelect v-model="form.gain_loss_gl_account_id" name="gain_loss_gl_account_id" label="Gain / Loss on Disposal GL Account" :options="accounts" :error="form.errors.gain_loss_gl_account_id" required />
        <FormInput v-model="form.notes" name="notes" label="Disposal Notes / Rationale" placeholder="e.g. Scrapped due to total hardware failure" :error="form.errors.notes" />

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <SecondaryButton :href="route('accounting.fixed-assets.show', asset.id)">
            Cancel
          </SecondaryButton>
          <PrimaryButton type="submit" :disabled="form.processing">Dispose Asset</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
