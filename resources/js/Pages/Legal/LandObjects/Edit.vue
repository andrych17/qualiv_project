<!-- ponytail: Edit land object (§3H) + due diligence checklist (§3I) -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormAsyncSearchableSelect from '@/Components/forms/FormAsyncSearchableSelect.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import DueDiligenceChecklist, { type CheckRow } from '@/Components/legal/DueDiligenceChecklist.vue'

const props = defineProps<{
  landObject: {
    id: number
    certificate_type: string
    certificate_number: string
    nib: string | null
    address: string
    area_m2: string | null
    njop_reference: string | null
    current_owner_partner_id: number | null
    status: string
  }
  certificateTypes: string[]
  checkTypes: string[]
  checks: CheckRow[]
}>()

const form = useForm({
  certificate_type: props.landObject.certificate_type,
  certificate_number: props.landObject.certificate_number,
  nib: props.landObject.nib ?? '',
  address: props.landObject.address,
  area_m2: props.landObject.area_m2 ?? '',
  njop_reference: props.landObject.njop_reference ?? '',
  current_owner_partner_id: props.landObject.current_owner_partner_id,
  status: props.landObject.status,
})

const submit = () => form.put(route('legal.landObjects.update', props.landObject.id))
</script>

<template>
  <AppLayout>
    <PageHeader title="Edit land object" :description="landObject.certificate_number" />

    <div class="mt-6 grid max-w-3xl gap-6 lg:grid-cols-2">
      <Panel>
        <form class="space-y-4" @submit.prevent="submit">
          <FormSelect
            v-model="form.certificate_type"
            name="certificate_type"
            label="Certificate type"
            :options="certificateTypes.map((t) => ({ label: t, value: t }))"
            :error="form.errors.certificate_type"
            required
          />
          <FormInput v-model="form.certificate_number" name="certificate_number" label="Certificate number" :error="form.errors.certificate_number" required />
          <FormInput v-model="form.nib" name="nib" label="NIB" :error="form.errors.nib" />
          <FormInput v-model="form.address" name="address" label="Address / location" :error="form.errors.address" required />
          <div class="grid grid-cols-2 gap-4">
            <FormInput v-model="form.area_m2" name="area_m2" type="number" label="Area (m²)" :error="form.errors.area_m2" />
            <FormInput v-model="form.njop_reference" name="njop_reference" label="NJOP reference" :error="form.errors.njop_reference" />
          </div>
          <FormAsyncSearchableSelect
            v-model="form.current_owner_partner_id"
            name="current_owner_partner_id"
            label="Current registered owner (informational)"
            api-entity="crm_partner"
            placeholder="Search — the certificate remains source of truth"
            :error="form.errors.current_owner_partner_id"
          />
          <FormSelect
            v-model="form.status"
            name="status"
            label="Status"
            :options="[
              { label: 'Active', value: 'active' },
              { label: 'In transaction', value: 'in_transaction' },
              { label: 'Transferred', value: 'transferred' },
              { label: 'Disputed', value: 'disputed' },
            ]"
            :error="form.errors.status"
            required
          />
          <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
            <Link
              :href="route('legal.landObjects.index')"
              class="inline-flex items-center justify-center rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm font-semibold text-ink-900 shadow-sm transition hover:bg-surface-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
            >
              Back
            </Link>
            <PrimaryButton type="submit" :disabled="form.processing">Save</PrimaryButton>
          </div>
        </form>
      </Panel>

      <Panel>
        <p class="mb-3 text-xs font-semibold uppercase tracking-wide text-ink-600">Due Diligence (§3I)</p>
        <DueDiligenceChecklist :land-object-id="landObject.id" :checks="checks" :check-types="checkTypes" />
      </Panel>
    </div>
  </AppLayout>
</template>
