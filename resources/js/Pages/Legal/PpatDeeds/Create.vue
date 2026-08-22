<!-- ponytail: Draft PPAT deed (§3G) -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import CustomFieldInputs, { type CustomFieldDef } from '@/Components/forms/CustomFieldInputs.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'

const props = defineProps<{
  deedTypes: Array<{ id: number; name: string }>
  matters: Array<{ id: number; code: string; title: string }>
  landObjects: Array<{ id: number; certificate_number: string; address: string }>
  customFields: CustomFieldDef[]
}>()

const customBag: Record<string, string> = {}
for (const f of props.customFields) {
  customBag[f.code] = f.value ?? ''
}

const form = useForm({
  deed_type_id: null as number | null,
  matter_id: null as number | null,
  land_object_id: null as number | null,
  transaction_value: '',
  minuta_reference: '',
  summary: '',
  custom_fields: customBag,
})

const submit = () => form.post(route('legal.ppatDeeds.store'))
</script>

<template>
  <AppLayout>
    <PageHeader title="Draft PPAT deed" description="Signing is blocked until due diligence clears and tax obligations are validated." />

    <Panel class="mt-6 max-w-xl">
      <form class="space-y-4" @submit.prevent="submit">
        <FormSelect
          v-model="form.deed_type_id"
          name="deed_type_id"
          label="Deed type"
          placeholder="Select a deed type"
          :options="deedTypes.map((t) => ({ label: t.name, value: t.id }))"
          :error="form.errors.deed_type_id"
          required
        />
        <FormSelect
          v-model="form.land_object_id"
          name="land_object_id"
          label="Land object"
          placeholder="Select a certificate"
          :options="landObjects.map((o) => ({ label: `${o.certificate_number} — ${o.address}`, value: o.id }))"
          :error="form.errors.land_object_id"
          required
        />
        <FormInput
          v-model="form.transaction_value"
          name="transaction_value"
          type="number"
          label="Transaction value (IDR)"
          :error="form.errors.transaction_value"
          required
        />
        <FormSelect
          v-model="form.matter_id"
          name="matter_id"
          label="Matter"
          placeholder="Standalone (no matter)"
          :options="matters.map((m) => ({ label: `${m.code} — ${m.title}`, value: m.id }))"
          :error="form.errors.matter_id"
        />
        <FormInput
          v-model="form.minuta_reference"
          name="minuta_reference"
          label="Minuta reference"
          :error="form.errors.minuta_reference"
        />
        <div class="space-y-1.5">
          <label class="text-sm font-medium text-ink-900">Summary</label>
          <textarea
            v-model="form.summary"
            rows="3"
            class="w-full rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm text-ink-900 shadow-sm focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/20"
          />
        </div>
        <CustomFieldInputs
          v-model="form.custom_fields"
          :fields="customFields"
          :errors="form.errors"
        />
        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <Link
            :href="route('legal.ppatDeeds.index')"
            class="inline-flex items-center justify-center rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm font-semibold text-ink-900 shadow-sm transition hover:bg-surface-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            Cancel
          </Link>
          <PrimaryButton type="submit" :disabled="form.processing">Save draft</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
