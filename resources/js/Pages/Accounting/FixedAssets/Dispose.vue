<!-- ponytail: Accounting §3G dispose an asset — sale (proceeds > 0) or write-off (proceeds = 0), one-shot form. -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSearchableSelect from '@/Components/forms/FormSearchableSelect.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'

const props = defineProps<{
  asset: { id: number; company_id: number; asset_no: string; name: string; acquisition_cost: string }
  accounts: Array<{ value: number; label: string }>
}>()

const today = new Date().toISOString().slice(0, 10)

const form = useForm({
  disposal_date: today,
  proceeds: '0',
  proceeds_gl_account_id: null as number | null,
  gain_loss_gl_account_id: null as number | null,
  notes: '',
})

const submit = () => form.transform((data) => ({ ...data, proceeds: Number(data.proceeds) })).post(route('accounting.fixed-assets.dispose.store', props.asset.id))
</script>

<template>
  <AppLayout>
    <PageHeader :title="`Dispose — ${asset.asset_no}`" :description="`${asset.name} — acquisition cost ${asset.acquisition_cost}`" />

    <Panel class="mt-6 max-w-xl">
      <form class="space-y-4" @submit.prevent="submit">
        <FormInput v-model="form.disposal_date" name="disposal_date" type="date" label="Disposal date" :error="form.errors.disposal_date" required />
        <FormInput v-model="form.proceeds" name="proceeds" type="number" step="0.01" label="Proceeds (0 = write-off)" :error="form.errors.proceeds" required />
        <FormSearchableSelect
          v-if="Number(form.proceeds) > 0"
          v-model="form.proceeds_gl_account_id"
          name="proceeds_gl_account_id"
          label="Proceeds account (cash/receivable)"
          :options="accounts"
          :error="form.errors.proceeds_gl_account_id"
          required
        />
        <FormSearchableSelect v-model="form.gain_loss_gl_account_id" name="gain_loss_gl_account_id" label="Gain/loss on disposal account" :options="accounts" :error="form.errors.gain_loss_gl_account_id" required />
        <FormInput v-model="form.notes" name="notes" label="Notes (optional)" :error="form.errors.notes" />

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <Link
            :href="route('accounting.fixed-assets.show', asset.id)"
            class="inline-flex items-center justify-center rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm font-semibold text-ink-900 shadow-sm transition hover:bg-surface-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            Cancel
          </Link>
          <PrimaryButton type="submit" :disabled="form.processing">Dispose asset</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
