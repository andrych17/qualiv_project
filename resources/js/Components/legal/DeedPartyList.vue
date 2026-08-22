<!-- ponytail: deed_parties list + quick-add (§3J) — mirrors LeadActivityLog's inline feed pattern -->
<script setup lang="ts">
import { useForm, router } from '@inertiajs/vue3'
import FormSelect from '@/Components/forms/FormSelect.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormAsyncSearchableSelect from '@/Components/forms/FormAsyncSearchableSelect.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import { useConfirm } from '@/Composables/useConfirmDialog'

export interface DeedPartyRow {
  id: number
  partner_id: number
  partner_name: string | null
  role_type_id: number
  identity_name: string | null
  identity_id_number: string | null
  identity_address: string | null
}

const props = defineProps<{
  deedId: number
  parties: DeedPartyRow[]
  roleTypes: Array<{ id: number; name: string }>
  isLocked: boolean
}>()

const form = useForm({
  partner_id: null as number | null,
  role_type_id: null as number | null,
  identity_name: '',
  identity_id_number: '',
  identity_address: '',
})

const submit = () => {
  form.post(route('legal.deeds.parties.store', props.deedId), {
    preserveScroll: true,
    onSuccess: () => form.reset(),
  })
}

const { confirm } = useConfirm()

const confirmRemove = (party: DeedPartyRow) => {
  confirm({
    title: `Remove ${party.identity_name || party.partner_name || 'this party'}?`,
    variant: 'destructive',
    confirmText: 'Remove',
    onConfirm: () => router.delete(route('legal.deeds.parties.destroy', [props.deedId, party.id]), { preserveScroll: true }),
  })
}

const roleName = (id: number) => props.roleTypes.find((r) => r.id === id)?.name ?? '—'
</script>

<template>
  <div class="space-y-4">
    <form v-if="!isLocked" class="space-y-3 rounded-sm border border-border p-3" @submit.prevent="submit">
      <FormAsyncSearchableSelect
        v-model="form.partner_id"
        name="partner_id"
        label="Existing contact (optional)"
        api-entity="crm_partner"
        placeholder="Search — or leave blank to quick-add below"
        :error="form.errors.partner_id"
      />
      <FormSelect
        v-model="form.role_type_id"
        name="role_type_id"
        label="Role"
        placeholder="Select a role"
        :options="roleTypes.map((r) => ({ label: r.name, value: r.id }))"
        :error="form.errors.role_type_id"
        required
      />
      <div class="grid grid-cols-3 gap-3">
        <FormInput v-model="form.identity_name" name="identity_name" label="Name" :error="form.errors.identity_name" />
        <FormInput v-model="form.identity_id_number" name="identity_id_number" label="ID number (NIK)" :error="form.errors.identity_id_number" />
        <FormInput v-model="form.identity_address" name="identity_address" label="Address" :error="form.errors.identity_address" />
      </div>
      <div class="flex justify-end">
        <PrimaryButton type="submit" :disabled="form.processing">Add party</PrimaryButton>
      </div>
    </form>

    <div v-if="parties.length === 0" class="text-sm text-ink-600">No parties added yet.</div>
    <ul v-else class="space-y-2">
      <li v-for="p in parties" :key="p.id" class="flex items-start justify-between gap-3 border-l-2 border-border pl-3">
        <div>
          <p class="text-sm font-medium text-ink-900">
            {{ p.identity_name || p.partner_name || `Party #${p.id}` }}
            <span class="font-normal text-ink-600">— {{ roleName(p.role_type_id) }}</span>
          </p>
          <p v-if="p.identity_id_number || p.identity_address" class="mt-0.5 text-xs text-ink-600">
            <span v-if="p.identity_id_number">{{ p.identity_id_number }}</span>
            <span v-if="p.identity_id_number && p.identity_address"> · </span>
            <span v-if="p.identity_address">{{ p.identity_address }}</span>
          </p>
        </div>
        <button
          v-if="!isLocked"
          type="button"
          class="shrink-0 text-sm font-medium text-signal-danger hover:underline"
          @click="confirmRemove(p)"
        >
          Remove
        </button>
      </li>
    </ul>
  </div>
</template>
