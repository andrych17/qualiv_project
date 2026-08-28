<!-- ponytail: Add Budget GL Mapping (§3B) -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import FormSwitch from '@/Components/forms/FormSwitch.vue'
import PerformanceSubNav from '@/Components/performance/PerformanceSubNav.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'

const props = defineProps<{
  accounts: Array<{ id: number; account_code: string; account_name: string }>
  companies: Array<{ id: number; legal_name: string }>
}>()

const form = useForm({
  category: '',
  account_id: null as number | null,
  company_id: null as number | null,
  is_active: true,
})

const submit = () => form.post(route('performance.budgetCategoryAccounts.store'))
</script>

<template>
  <AppLayout>
    <PageHeader title="Add Budget GL Mapping" description="A category with no active mapping falls back to manual actuals." />

    <PerformanceSubNav active="budgetCategoryAccounts" class="mt-6" />

    <Panel class="mt-6 max-w-xl">
      <form class="space-y-4" @submit.prevent="submit">
        <FormInput v-model="form.category" name="category" label="Category" placeholder="Must match a budget line's category exactly, e.g. Marketing" :error="form.errors.category" required />

        <FormSelect
          v-model="form.account_id"
          name="account_id"
          label="GL Account"
          placeholder="Select an account…"
          :options="accounts.map((a) => ({ label: `${a.account_code} — ${a.account_name}`, value: a.id }))"
          :error="form.errors.account_id"
          required
        />

        <FormSelect
          v-model="form.company_id"
          name="company_id"
          label="Company scope (optional)"
          placeholder="All companies"
          :options="companies.map((c) => ({ label: c.legal_name, value: c.id }))"
          :error="form.errors.company_id"
        />

        <FormSwitch v-model="form.is_active" name="is_active" label="Active" />

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <Link
            :href="route('performance.budgetCategoryAccounts.index')"
            class="inline-flex items-center justify-center rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm font-semibold text-ink-900 shadow-sm transition hover:bg-surface-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            Cancel
          </Link>
          <PrimaryButton type="submit" :disabled="form.processing">Save mapping</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
