<!-- ponytail: Edit Company (§3C) — mirrors Create, adds is_active toggle + read-only Contacts list -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormAsyncSearchableSelect from '@/Components/forms/FormAsyncSearchableSelect.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import FormSwitch from '@/Components/forms/FormSwitch.vue'
import CustomFieldInputs, { type CustomFieldDef } from '@/Components/forms/CustomFieldInputs.vue'
import AddressListInput, { type AddressRow } from '@/Components/crm/AddressListInput.vue'
import ContactPointListInput, { type ContactPointRow } from '@/Components/crm/ContactPointListInput.vue'
import RoleTypeCheckboxes from '@/Components/crm/RoleTypeCheckboxes.vue'
import CrmSubNav from '@/Components/crm/CrmSubNav.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import { useI18n } from '@/Composables/useI18n'

const { t } = useI18n()

const props = defineProps<{
  company: {
    id: number
    name: string
    trade_name: string | null
    registration_tax_id: string | null
    industry_id: number | null
    parent_partner_id: number | null
    parent: { value: number; label: string } | null
    owner_id: number | null
    tags: string
    is_active: boolean
    role_type_ids: number[]
    addresses: AddressRow[]
    contact_points: ContactPointRow[]
    contacts: Array<{ id: number; name: string; title_position: string | null }>
  }
  industries: Array<{ id: number; name: string }>
  roleTypes: Array<{ id: number; name: string }>
  owners: Array<{ id: number; name: string }>
  customFields: CustomFieldDef[]
}>()

const customBag: Record<string, string> = {}
for (const f of props.customFields) {
  customBag[f.code] = f.value ?? ''
}

const form = useForm({
  name: props.company.name,
  trade_name: props.company.trade_name ?? '',
  registration_tax_id: props.company.registration_tax_id ?? '',
  industry_id: props.company.industry_id,
  parent_partner_id: props.company.parent_partner_id,
  owner_id: props.company.owner_id,
  tags: props.company.tags,
  is_active: props.company.is_active,
  role_type_ids: [...props.company.role_type_ids],
  addresses: props.company.addresses.map((a) => ({ ...a })),
  contact_points: props.company.contact_points.map((c) => ({ ...c })),
  custom_fields: customBag,
})

const submit = () => form.put(route('crm.companies.update', props.company.id))
</script>

<template>
  <AppLayout>
    <PageHeader :title="t('crm.edit_company')" :description="company.name" />

    <CrmSubNav active="companies" class="mt-6" />

    <div class="mt-6 grid max-w-5xl grid-cols-1 gap-6 lg:grid-cols-3">
      <Panel class="lg:col-span-2">
        <form class="space-y-4" @submit.prevent="submit">
          <FormInput
            v-model="form.name"
            name="name"
            :label="t('crm.legal_name')"
            :error="form.errors.name"
            required
          />
          <FormInput
            v-model="form.trade_name"
            name="trade_name"
            :label="t('crm.trade_name')"
            :error="form.errors.trade_name"
          />
          <FormInput
            v-model="form.registration_tax_id"
            name="registration_tax_id"
            :label="t('crm.registration_tax_id')"
            :placeholder="t('crm.registration_tax_id_placeholder')"
            :error="form.errors.registration_tax_id"
          />
          <FormSelect
            v-model="form.industry_id"
            name="industry_id"
            :label="t('crm.industry')"
            :placeholder="t('crm.unclassified')"
            :options="industries.map((i) => ({ label: i.name, value: i.id }))"
            :error="form.errors.industry_id"
          />
          <FormAsyncSearchableSelect
            v-model="form.parent_partner_id"
            name="parent_partner_id"
            :label="t('crm.parent_company')"
            api-entity="crm_company"
            :placeholder="t('crm.parent_company_placeholder')"
            :error="form.errors.parent_partner_id"
          />
          <FormSelect
            v-model="form.owner_id"
            name="owner_id"
            :label="t('crm.owner')"
            :placeholder="t('crm.unassigned')"
            :options="owners.map((o) => ({ label: o.name, value: o.id }))"
            :error="form.errors.owner_id"
          />
          <FormInput
            v-model="form.tags"
            name="tags"
            :label="t('crm.tags')"
            :placeholder="t('crm.tags_placeholder')"
            :error="form.errors.tags"
          />

          <FormSwitch
            v-model="form.is_active"
            :label="t('crm.active_status')"
            :description="t('crm.active_status_desc')"
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
            <SecondaryButton :href="route('crm.companies.index')">
              {{ t('common.cancel') }}
            </SecondaryButton>
            <PrimaryButton type="submit" :disabled="form.processing">
              {{ t('crm.update_company') }}
            </PrimaryButton>
          </div>
        </form>
      </Panel>

      <Panel :title="t('crm.linked_contacts')" :subtitle="t('crm.people_work_here')">
        <div v-if="company.contacts.length === 0" class="text-sm text-ink-600">
          {{ t('crm.no_linked_contacts') }}
        </div>
        <ul v-else class="space-y-3">
          <li v-for="c in company.contacts" :key="c.id">
            <Link :href="route('crm.contacts.edit', c.id)" class="text-sm font-medium text-accent hover:underline">
              {{ c.name }}
            </Link>
            <p v-if="c.title_position" class="text-xs text-ink-600">{{ c.title_position }}</p>
          </li>
        </ul>
      </Panel>
    </div>
  </AppLayout>
</template>
