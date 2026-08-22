<!-- ponytail: Edit / sign PPAT deed (§3G) — locked once signed, same immutability as §3C -->
<script setup lang="ts">
import { useForm, Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import CustomFieldInputs, { type CustomFieldDef } from '@/Components/forms/CustomFieldInputs.vue'
import DeedPartyList, { type DeedPartyRow } from '@/Components/legal/DeedPartyList.vue'
import DeedTaxPanel, { type TaxRow } from '@/Components/legal/DeedTaxPanel.vue'
import BpnSubmissionPanel, { type BpnSubmissionRow } from '@/Components/legal/BpnSubmissionPanel.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import { useConfirm } from '@/Composables/useConfirmDialog'

const props = defineProps<{
  deed: {
    id: number
    matter_id: number | null
    deed_type_id: number
    land_object_id: number | null
    transaction_value: string | null
    deed_number: string | null
    status: string
    signing_date: string | null
    minuta_reference: string | null
    summary: string | null
    is_locked: boolean
    next_statuses: string[]
  }
  deedTypes: Array<{ id: number; name: string }>
  matters: Array<{ id: number; code: string; title: string }>
  landObjects: Array<{ id: number; certificate_number: string; address: string }>
  customFields: CustomFieldDef[]
  partyRoleTypes: Array<{ id: number; name: string }>
  parties: DeedPartyRow[]
  taxes: TaxRow[]
  bpnSubmissions: BpnSubmissionRow[]
}>()

const customBag: Record<string, string> = {}
for (const f of props.customFields) {
  customBag[f.code] = f.value ?? ''
}

const form = useForm({
  deed_type_id: props.deed.deed_type_id,
  matter_id: props.deed.matter_id,
  land_object_id: props.deed.land_object_id,
  transaction_value: props.deed.transaction_value ?? '',
  signing_date: props.deed.signing_date ?? '',
  minuta_reference: props.deed.minuta_reference ?? '',
  summary: props.deed.summary ?? '',
  custom_fields: customBag,
})

const submit = () => form.put(route('legal.ppatDeeds.update', props.deed.id))

const deedTypeName = (id: number) => props.deedTypes.find((t) => t.id === id)?.name ?? '—'
const landObjectLabel = (id: number | null) => {
  if (!id) return '—'
  const o = props.landObjects.find((o) => o.id === id)
  return o ? `${o.certificate_number} — ${o.address}` : `#${id}`
}

const STATUS_LABELS: Record<string, string> = {
  draft: 'Draft',
  ready_for_signing: 'Ready for signing',
  signed: 'Signed',
  archived: 'Archived',
}

const { confirm } = useConfirm()

const requestTransition = (toStatus: string) => {
  if (toStatus === 'signed' && !props.deed.signing_date) {
    confirm({
      title: 'Signing date required',
      description: 'Set a signing date and save before moving this deed to Signed.',
      confirmText: 'OK',
      onConfirm: () => {},
    })
    return
  }

  confirm({
    title: `Move deed to "${STATUS_LABELS[toStatus]}"?`,
    description: toStatus === 'signed'
      ? 'This locks the deed — content becomes read-only. Blocked if due diligence on the land object is still flagged.'
      : undefined,
    variant: toStatus === 'signed' ? 'destructive' : 'default',
    confirmText: 'Confirm',
    onConfirm: () => router.patch(route('legal.deeds.transition', props.deed.id), { status: toStatus }),
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader :title="deed.deed_number || `PPAT Deed #${deed.id}`" :description="deed.minuta_reference || 'No minuta reference yet'">
      <template #actions>
        <StatusBadge :status="deed.status" />
      </template>
    </PageHeader>

    <div class="mt-6 grid max-w-3xl gap-6 lg:grid-cols-[1fr_260px]">
      <Panel v-if="deed.is_locked">
        <p class="mb-4 rounded-sm border border-signal-warning/40 bg-signal-warning/10 px-3 py-2 text-sm text-ink-900">
          This deed is signed — content is locked. Corrections require a new amending deed.
        </p>
        <dl class="grid grid-cols-2 gap-4 text-sm">
          <div>
            <dt class="text-xs font-semibold uppercase tracking-wide text-ink-600">Deed type</dt>
            <dd class="mt-0.5 text-ink-900">{{ deedTypeName(deed.deed_type_id) }}</dd>
          </div>
          <div>
            <dt class="text-xs font-semibold uppercase tracking-wide text-ink-600">Land object</dt>
            <dd class="mt-0.5 text-ink-900">{{ landObjectLabel(deed.land_object_id) }}</dd>
          </div>
          <div>
            <dt class="text-xs font-semibold uppercase tracking-wide text-ink-600">Transaction value</dt>
            <dd class="mt-0.5 text-ink-900">{{ deed.transaction_value || '—' }}</dd>
          </div>
          <div>
            <dt class="text-xs font-semibold uppercase tracking-wide text-ink-600">Signing date</dt>
            <dd class="mt-0.5 text-ink-900">{{ deed.signing_date || '—' }}</dd>
          </div>
          <div class="col-span-2">
            <dt class="text-xs font-semibold uppercase tracking-wide text-ink-600">Summary</dt>
            <dd class="mt-0.5 whitespace-pre-line text-ink-900">{{ deed.summary || '—' }}</dd>
          </div>
        </dl>
        <div class="mt-4 border-t border-border pt-4">
          <Link
            :href="route('legal.ppatDeeds.index')"
            class="inline-flex items-center justify-center rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm font-semibold text-ink-900 shadow-sm transition hover:bg-surface-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            Back
          </Link>
        </div>
      </Panel>

      <Panel v-else>
        <form class="space-y-4" @submit.prevent="submit">
          <FormSelect
            v-model="form.deed_type_id"
            name="deed_type_id"
            label="Deed type"
            :options="deedTypes.map((t) => ({ label: t.name, value: t.id }))"
            :error="form.errors.deed_type_id"
            required
          />
          <FormSelect
            v-model="form.land_object_id"
            name="land_object_id"
            label="Land object"
            :options="landObjects.map((o) => ({ label: `${o.certificate_number} — ${o.address}`, value: o.id }))"
            :error="form.errors.land_object_id"
            required
          />
          <FormInput v-model="form.transaction_value" name="transaction_value" type="number" label="Transaction value (IDR)" :error="form.errors.transaction_value" required />
          <FormSelect
            v-model="form.matter_id"
            name="matter_id"
            label="Matter"
            placeholder="Standalone (no matter)"
            :options="matters.map((m) => ({ label: `${m.code} — ${m.title}`, value: m.id }))"
            :error="form.errors.matter_id"
          />
          <FormInput v-model="form.signing_date" name="signing_date" type="date" label="Signing date" :error="form.errors.signing_date" />
          <FormInput v-model="form.minuta_reference" name="minuta_reference" label="Minuta reference" :error="form.errors.minuta_reference" />
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
              Back
            </Link>
            <PrimaryButton type="submit" :disabled="form.processing">Save deed</PrimaryButton>
          </div>
        </form>
      </Panel>

      <Panel class="h-fit space-y-3">
        <p class="text-xs font-semibold uppercase tracking-wide text-ink-600">Lifecycle</p>
        <button
          v-for="next in deed.next_statuses"
          :key="next"
          type="button"
          class="w-full rounded-sm border border-border bg-surface-0 px-3 py-2 text-left text-sm font-semibold text-ink-900 shadow-sm transition hover:bg-surface-50"
          @click="requestTransition(next)"
        >
          Move to {{ STATUS_LABELS[next] }}
        </button>
        <p v-if="deed.next_statuses.length === 0" class="text-sm text-ink-600">No further transitions.</p>
        <p v-if="deed.land_object_id" class="text-xs text-ink-600">
          <Link :href="route('legal.landObjects.edit', deed.land_object_id)" class="text-accent hover:underline">
            Check due diligence status →
          </Link>
        </p>
      </Panel>
    </div>

    <div class="mt-6 grid max-w-3xl gap-6 lg:grid-cols-2">
      <Panel>
        <p class="mb-4 text-xs font-semibold uppercase tracking-wide text-ink-600">Parties (§3J)</p>
        <DeedPartyList
          :deed-id="deed.id"
          :parties="parties"
          :role-types="partyRoleTypes"
          :is-locked="deed.is_locked"
        />
      </Panel>

      <Panel>
        <p class="mb-4 text-xs font-semibold uppercase tracking-wide text-ink-600">Tax obligations (§3K)</p>
        <DeedTaxPanel :deed-id="deed.id" :taxes="taxes" />
      </Panel>

      <Panel class="lg:col-span-2">
        <p class="mb-4 text-xs font-semibold uppercase tracking-wide text-ink-600">BPN Registration (§3L)</p>
        <BpnSubmissionPanel :deed-id="deed.id" :bpn-submissions="bpnSubmissions" />
      </Panel>
    </div>
  </AppLayout>
</template>
