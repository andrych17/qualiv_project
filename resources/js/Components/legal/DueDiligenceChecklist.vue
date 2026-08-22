<!-- ponytail: land_object due-diligence checklist (§3I) — add + record result + override -->
<script setup lang="ts">
import { ref } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import FormSelect from '@/Components/forms/FormSelect.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'

export interface CheckRow {
  id: number
  check_type: string
  status: string
  result_notes: string | null
  checker_name: string | null
  checked_at: string | null
  is_blocking: boolean
  overridden_by_name: string | null
  override_justification: string | null
}

const props = defineProps<{
  landObjectId: number
  checks: CheckRow[]
  checkTypes: string[]
}>()

const TYPE_LABEL: Record<string, string> = {
  sertifikat_validity: 'Sertifikat validity (SKPT)',
  pbb_payment_status: 'PBB payment status',
  blokir_sengketa: 'Blokir / sengketa',
  zona_nilai_tanah: 'Zona Nilai Tanah',
}

const addForm = useForm({ check_type: props.checkTypes[0] })
const submitAdd = () => {
  addForm.post(route('legal.landObjects.checks.store', props.landObjectId), {
    preserveScroll: true,
    onSuccess: () => addForm.reset(),
  })
}

const openResultForm = ref<number | null>(null)
const resultForm = useForm({ status: 'clear', result_notes: '' })
const submitResult = (checkId: number) => {
  resultForm.patch(route('legal.landObjects.checks.recordResult', [props.landObjectId, checkId]), {
    preserveScroll: true,
    onSuccess: () => { openResultForm.value = null; resultForm.reset() },
  })
}

const openOverrideForm = ref<number | null>(null)
const overrideForm = useForm({ justification: '' })
const submitOverride = (checkId: number) => {
  overrideForm.patch(route('legal.landObjects.checks.override', [props.landObjectId, checkId]), {
    preserveScroll: true,
    onSuccess: () => { openOverrideForm.value = null; overrideForm.reset() },
  })
}
</script>

<template>
  <div class="space-y-4">
    <form class="flex items-end gap-2" @submit.prevent="submitAdd">
      <div class="flex-1">
        <FormSelect
          v-model="addForm.check_type"
          name="check_type"
          label="Add check"
          :options="checkTypes.map((t) => ({ label: TYPE_LABEL[t] ?? t, value: t }))"
        />
      </div>
      <PrimaryButton type="submit" :disabled="addForm.processing">Add</PrimaryButton>
    </form>

    <div v-if="checks.length === 0" class="text-sm text-ink-600">No checks recorded yet.</div>
    <ul v-else class="space-y-3">
      <li v-for="c in checks" :key="c.id" class="rounded-sm border border-border p-3" :class="c.is_blocking ? 'border-signal-danger/50 bg-signal-danger/5' : ''">
        <div class="flex items-center justify-between">
          <p class="text-sm font-medium text-ink-900">{{ TYPE_LABEL[c.check_type] ?? c.check_type }}</p>
          <StatusBadge :status="c.status" />
        </div>
        <p v-if="c.checked_at" class="mt-0.5 text-xs text-ink-600">{{ c.checker_name ?? 'Unknown' }} · {{ c.checked_at }}</p>
        <p v-if="c.result_notes" class="mt-1 text-sm text-ink-900">{{ c.result_notes }}</p>

        <p v-if="c.overridden_by_name" class="mt-2 text-xs text-signal-warning">
          Overridden by {{ c.overridden_by_name }}: {{ c.override_justification }}
        </p>

        <div v-if="c.status === 'pending'" class="mt-2">
          <button
            v-if="openResultForm !== c.id"
            type="button"
            class="text-sm font-medium text-accent hover:underline"
            @click="openResultForm = c.id"
          >
            Record result
          </button>
          <form v-else class="mt-2 space-y-2" @submit.prevent="submitResult(c.id)">
            <FormSelect
              v-model="resultForm.status"
              name="status"
              label="Result"
              :options="[{ label: 'Clear', value: 'clear' }, { label: 'Flagged', value: 'flagged' }]"
            />
            <textarea
              v-model="resultForm.result_notes"
              rows="2"
              placeholder="Notes / evidence reference"
              class="w-full rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm text-ink-900 shadow-sm focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/20"
            />
            <PrimaryButton type="submit" :disabled="resultForm.processing">Save result</PrimaryButton>
          </form>
        </div>

        <div v-if="c.is_blocking" class="mt-2">
          <button
            v-if="openOverrideForm !== c.id"
            type="button"
            class="text-sm font-medium text-signal-danger hover:underline"
            @click="openOverrideForm = c.id"
          >
            Override (proceed with documented risk acceptance)
          </button>
          <form v-else class="mt-2 space-y-2" @submit.prevent="submitOverride(c.id)">
            <textarea
              v-model="overrideForm.justification"
              rows="2"
              placeholder="Justification — required, logged permanently"
              class="w-full rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm text-ink-900 shadow-sm focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/20"
            />
            <PrimaryButton type="submit" :disabled="overrideForm.processing">Confirm override</PrimaryButton>
          </form>
        </div>
      </li>
    </ul>
  </div>
</template>
