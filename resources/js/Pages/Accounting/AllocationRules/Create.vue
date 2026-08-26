<!-- ponytail: Accounting §3I allocation rule — source account/cost center + N target cost
     centers with percentages. Percentages must sum to exactly 100 (enforced server-side in
     AllocationRuleService, shown live here so a user doesn't have to submit to find out). -->
<script setup lang="ts">
import { computed } from 'vue'
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormNumberInput from '@/Components/forms/FormNumberInput.vue'
import FormSearchableSelect from '@/Components/forms/FormSearchableSelect.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import { Plus, Trash2 } from 'lucide-vue-next'

type Option = { value: number; label: string }

const props = defineProps<{
  companies: Array<{ id: number; legal_name: string }>
  selectedCompanyId: number | null
  accounts: Option[]
  costCenters: Option[]
}>()

const blankTarget = () => ({ cost_center_id: null as number | null, percentage: null as number | null })

const form = useForm({
  company_id: props.selectedCompanyId,
  name: '',
  source_account_id: null as number | null,
  source_cost_center_id: null as number | null,
  targets: [blankTarget(), blankTarget()],
})

const addTarget = () => form.targets.push(blankTarget())
const removeTarget = (i: number) => form.targets.splice(i, 1)

const totalPercentage = computed(() => form.targets.reduce((sum, t) => sum + (Number(t.percentage) || 0), 0))
const isValidTotal = computed(() => Math.abs(totalPercentage.value - 100) < 0.005)
const sourceEqualsTarget = computed(() => form.source_cost_center_id !== null && form.targets.some((t) => t.cost_center_id === form.source_cost_center_id))

const submit = () => form.transform((data) => ({
  ...data,
  targets: data.targets.map((t) => ({ ...t, percentage: Number(t.percentage) || 0 })),
})).post(route('accounting.allocation-rules.store'))
</script>

<template>
  <AppLayout>
    <PageHeader title="New Cost Allocation Rule" description="Percentages must sum to exactly 100% — rules redistribute the complete source pool." />

    <Panel class="mt-6">
      <form class="space-y-6" @submit.prevent="submit">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <FormSearchableSelect
            v-model="form.company_id"
            name="company_id"
            label="Company"
            :options="companies.map((c) => ({ value: c.id, label: c.legal_name }))"
            :error="form.errors.company_id"
            required
          />
          <FormInput v-model="form.name" name="name" label="Rule Name" placeholder="e.g. Office Rent → Practice Teams" :error="form.errors.name" required />
          <FormSearchableSelect v-model="form.source_account_id" name="source_account_id" label="Source Account" :options="accounts" :error="form.errors.source_account_id" required />
          <FormSearchableSelect
            v-model="form.source_cost_center_id"
            name="source_cost_center_id"
            label="Source Cost Center"
            placeholder="Unassigned (no cost center)"
            :options="costCenters"
            :error="form.errors.source_cost_center_id"
          />
        </div>

        <div>
          <div class="mb-3 flex items-center justify-between">
            <h3 class="text-sm font-semibold text-ink-900">Target Cost Centers</h3>
            <button type="button" class="inline-flex items-center gap-1 text-xs font-semibold text-accent hover:underline" @click="addTarget">
              <Plus class="h-4 w-4" /> Add Target
            </button>
          </div>

          <div class="overflow-x-auto rounded-lg border border-border">
            <table class="w-full text-sm">
              <thead>
                <tr class="border-b border-border bg-surface-50 text-left text-xs uppercase tracking-wider text-ink-600 font-semibold">
                  <th class="w-3/5 px-3 py-2.5">Target Cost Center</th>
                  <th class="px-3 py-2.5 text-right">Allocation (%)</th>
                  <th class="px-3 py-2.5"></th>
                </tr>
              </thead>
              <tbody class="divide-y divide-border bg-surface">
                <tr v-for="(target, i) in form.targets" :key="i" class="align-top hover:bg-surface-50/50 transition-colors">
                  <td class="px-3 py-2">
                    <FormSearchableSelect v-model="target.cost_center_id" :name="`targets.${i}.cost_center_id`" :options="costCenters" :error="(form.errors as any)[`targets.${i}.cost_center_id`]" />
                  </td>
                  <td class="px-3 py-2 text-right">
                    <FormNumberInput v-model="target.percentage" :name="`targets.${i}.percentage`" :decimals="2" suffix="%" class="w-32 inline-block" />
                  </td>
                  <td class="px-3 py-2 text-right pt-3">
                    <button type="button" class="text-ink-400 hover:text-signal-danger transition-colors" :disabled="form.targets.length <= 1" @click="removeTarget(i)">
                      <Trash2 class="h-4 w-4" />
                    </button>
                  </td>
                </tr>
              </tbody>
              <tfoot>
                <tr class="border-t-2 border-border bg-surface-100/75 font-semibold text-xs">
                  <td class="px-4 py-3 text-ink-900">Total Percentage</td>
                  <td class="px-4 py-3 text-right font-mono text-xs font-bold" :class="isValidTotal ? 'text-signal-success' : 'text-signal-danger'">
                    {{ totalPercentage.toFixed(2) }}% ({{ isValidTotal ? '✓ 100%' : 'Must equal 100%' }})
                  </td>
                  <td class="px-4 py-3"></td>
                </tr>
              </tfoot>
            </table>
          </div>
          <p v-if="sourceEqualsTarget" class="mt-2 text-sm text-signal-danger">A target cost center cannot be the same as the source cost center.</p>
          <p v-if="(form.errors as any).targets" class="mt-2 text-sm text-signal-danger">{{ (form.errors as any).targets }}</p>
        </div>

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <SecondaryButton :href="route('accounting.allocation-rules.index', { company_id: form.company_id })">
            Cancel
          </SecondaryButton>
          <PrimaryButton type="submit" :disabled="form.processing || !isValidTotal || sourceEqualsTarget">Save Rule</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
