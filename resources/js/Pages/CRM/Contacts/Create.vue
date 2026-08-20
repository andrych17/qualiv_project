<!-- ponytail: Create Contact (§3B) — Panel + design-system inputs, mirrors Legal Cases Create -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormAsyncSearchableSelect from '@/Components/forms/FormAsyncSearchableSelect.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import CustomFieldInputs, { type CustomFieldDef } from '@/Components/forms/CustomFieldInputs.vue'
import AddressListInput, { type AddressRow } from '@/Components/crm/AddressListInput.vue'
import ContactPointListInput, { type ContactPointRow } from '@/Components/crm/ContactPointListInput.vue'
import RoleTypeCheckboxes from '@/Components/crm/RoleTypeCheckboxes.vue'
import CrmSubNav from '@/Components/crm/CrmSubNav.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'

const props = defineProps<{
  roleTypes: Array<{ id: number; name: string }>
  owners: Array<{ id: number; name: string }>
  customFields: CustomFieldDef[]
}>()

const customBag: Record<string, string> = {}
for (const f of props.customFields) {
  customBag[f.code] = f.value ?? ''
}

const form = useForm({
  name: '',
  title_position: '',
  parent_partner_id: null as number | null,
  owner_id: null as number | null,
  tags: '',
  role_type_ids: [] as number[],
  addresses: [] as AddressRow[],
  contact_points: [] as ContactPointRow[],
  custom_fields: customBag,
})

const submit = () => form.post(route('crm.contacts.store'))
</script>

<template>
  <AppLayout>
    <PageHeader title="Add contact" description="Register a person — tie them to a company later if needed." />

    <CrmSubNav active="contacts" class="mt-6" />

    <Panel class="mt-6 max-w-2xl">
      <form class="space-y-4" @submit.prevent="submit">
        <FormInput v-model="form.name" name="name" label="Name" :error="form.errors.name" required />
        <FormInput
          v-model="form.title_position"
          name="title_position"
          label="Title / position"
          placeholder="e.g. Finance Manager"
          :error="form.errors.title_position"
        />
        <FormAsyncSearchableSelect
          v-model="form.parent_partner_id"
          name="parent_partner_id"
          label="Works at (company)"
          api-entity="crm_company"
          placeholder="Search for a company…"
          :error="form.errors.parent_partner_id"
        />
        <FormSelect
          v-model="form.owner_id"
          name="owner_id"
          label="Owner"
          placeholder="Unassigned"
          :options="owners.map((o) => ({ label: o.name, value: o.id }))"
          :error="form.errors.owner_id"
        />
        <FormInput
          v-model="form.tags"
          name="tags"
          label="Tags"
          placeholder="Comma-separated, e.g. VIP, Referral"
          :error="form.errors.tags"
        />

        <RoleTypeCheckboxes v-model="form.role_type_ids" :role-types="roleTypes" />
        <AddressListInput v-model="form.addresses" />
        <ContactPointListInput v-model="form.contact_points" />

        <CustomFieldInputs
          v-model="form.custom_fields"
          :fields="customFields"
          :errors="form.errors"
        />

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <Link
            :href="route('crm.contacts.index')"
            class="inline-flex items-center justify-center rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm font-semibold text-ink-900 shadow-sm transition hover:bg-surface-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            Cancel
          </Link>
          <PrimaryButton type="submit" :disabled="form.processing">Save contact</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
