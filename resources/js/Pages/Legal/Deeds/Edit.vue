<!-- ponytail: Edit / sign notarial deed (§3C) — locked once signed (deed immutability, §5) -->
<script setup lang="ts">
import { useForm, Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormTextarea from '@/Components/forms/FormTextarea.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import CustomFieldInputs, { type CustomFieldDef } from '@/Components/forms/CustomFieldInputs.vue'
import DeedPartyList, { type DeedPartyRow } from '@/Components/legal/DeedPartyList.vue'
import WillPanel, { type WillRow } from '@/Components/legal/WillPanel.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import { useConfirm } from '@/Composables/useConfirmDialog'

const props = defineProps<{
  deed: {
    id: number
    matter_id: number | null
    deed_type_id: number
    deed_type_code: string
    category: string
    deed_number: string | null
    status: string
    signing_date: string | null
    minuta_reference: string | null
    summary: string | null
    is_locked: boolean
    next_statuses: string[]
  }
  will: WillRow | null
  deedTypes: Array<{ id: number; name: string }>
  matters: Array<{ id: number; code: string; title: string }>
  customFields: CustomFieldDef[]
  partyRoleTypes: Array<{ id: number; name: string }>
  parties: DeedPartyRow[]
}>()

const customBag: Record<string, string> = {}
for (const f of props.customFields) {
  customBag[f.code] = f.value ?? ''
}

const form = useForm({
  deed_type_id: props.deed.deed_type_id,
  matter_id: props.deed.matter_id,
  signing_date: props.deed.signing_date ?? '',
  minuta_reference: props.deed.minuta_reference ?? '',
  summary: props.deed.summary ?? '',
  custom_fields: customBag,
})

const submit = () => form.put(route('legal.deeds.update', props.deed.id))

const deedTypeName = (id: number) => props.deedTypes.find((t) => t.id === id)?.name ?? '—'
const matterLabel = (id: number | null) => {
  if (!id) return 'Standalone (no matter)'
  const m = props.matters.find((m) => m.id === id)
  return m ? `${m.code} — ${m.title}` : `#${id}`
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
    description: toStatus === 'signed' ? 'This locks the deed — content becomes read-only. Corrections need a new amending deed.' : undefined,
    variant: toStatus === 'signed' ? 'destructive' : 'default',
    confirmText: 'Confirm',
    onConfirm: () => router.patch(route('legal.deeds.transition', props.deed.id), { status: toStatus }),
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader :title="deed.deed_number || `Deed #${deed.id}`" :description="deed.minuta_reference || 'No minuta reference yet'">
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
            <dt class="text-xs font-semibold uppercase tracking-wide text-ink-600">Matter</dt>
            <dd class="mt-0.5 text-ink-900">{{ matterLabel(deed.matter_id) }}</dd>
          </div>
          <div>
            <dt class="text-xs font-semibold uppercase tracking-wide text-ink-600">Signing date</dt>
            <dd class="mt-0.5 text-ink-900">{{ deed.signing_date || '—' }}</dd>
          </div>
          <div>
            <dt class="text-xs font-semibold uppercase tracking-wide text-ink-600">Minuta reference</dt>
            <dd class="mt-0.5 text-ink-900">{{ deed.minuta_reference || '—' }}</dd>
          </div>
          <div class="col-span-2">
            <dt class="text-xs font-semibold uppercase tracking-wide text-ink-600">Summary</dt>
            <dd class="mt-0.5 whitespace-pre-line text-ink-900">{{ deed.summary || '—' }}</dd>
          </div>
        </dl>
        <div class="mt-4 border-t border-border pt-4">
          <Link
            :href="route('legal.deeds.index')"
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
            v-model="form.matter_id"
            name="matter_id"
            label="Matter"
            placeholder="Standalone (no matter)"
            :options="matters.map((m) => ({ label: `${m.code} — ${m.title}`, value: m.id }))"
            :error="form.errors.matter_id"
          />
          <FormInput v-model="form.signing_date" name="signing_date" type="date" label="Signing date" :error="form.errors.signing_date" />
          <FormInput v-model="form.minuta_reference" name="minuta_reference" label="Minuta reference" :error="form.errors.minuta_reference" />
          <FormTextarea
            v-model="form.summary"
            name="summary"
            label="Summary"
            :rows="3"
            :error="form.errors.summary"
          />
          <CustomFieldInputs
            v-model="form.custom_fields"
            :fields="customFields"
            :errors="form.errors"
          />
          <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
            <Link
              :href="route('legal.deeds.index')"
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
      </Panel>
    </div>

    <div class="mt-6 grid max-w-3xl gap-6" :class="deed.deed_type_code === 'wasiat' ? 'lg:grid-cols-2' : ''">
      <Panel>
        <p class="mb-4 text-xs font-semibold uppercase tracking-wide text-ink-600">Parties (§3J)</p>
        <DeedPartyList
          :deed-id="deed.id"
          :parties="parties"
          :role-types="partyRoleTypes"
          :is-locked="deed.is_locked"
        />
      </Panel>

      <Panel v-if="deed.deed_type_code === 'wasiat'">
        <p class="mb-4 text-xs font-semibold uppercase tracking-wide text-ink-600">Wasiat / DPW (§3D)</p>
        <WillPanel :deed-id="deed.id" :will="will" />
      </Panel>
    </div>
  </AppLayout>
</template>
