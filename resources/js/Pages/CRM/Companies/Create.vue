<!-- ponytail: Create Company (§3C) — mirrors Contacts Create, org-specific fields -->
<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
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
import SecondaryButton from '@/Components/SecondaryButton.vue'
import { useI18n } from '@/Composables/useI18n'

const { t } = useI18n()

const props = defineProps<{
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
  name: '',
  trade_name: '',
  registration_tax_id: '',
  industry_id: null as number | null,
  parent_partner_id: null as number | null,
  owner_id: null as number | null,
  tags: '',
  role_type_ids: [] as number[],
  addresses: [] as AddressRow[],
  contact_points: [] as ContactPointRow[],
  custom_fields: customBag,
})

const submit = () => form.post(route('crm.companies.store'))
</script>

<template>
  <AppLayout>
    <PageHeader :title="t('crm.add_company')" :description="t('crm.company_desc')" />

    <CrmSubNav active="companies" class="mt-6" />

    <Panel class="mt-6 max-w-2xl">
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
            {{ t('crm.save_company') }}
          </PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
