<!-- ponytail: Accounting §3I allocation rule edit — same shape as Create.vue plus status/run
     link and pause/resume/delete, mirrors RecurringJournalTemplates/Edit.vue's structure. -->
<script setup lang="ts">
import { computed } from 'vue'
import { useForm, router, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSearchableSelect from '@/Components/forms/FormSearchableSelect.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import { Plus, Trash2 } from 'lucide-vue-next'

type Option = { value: number; label: string }
type Target = { cost_center_id: number | null; percentage: number }

const props = defineProps<{
  rule: { id: number; company_id: number; name: string; source_account_id: number; source_cost_center_id: number | null; is_active: boolean; targets: Target[] }
  accounts: Option[]
  costCenters: Option[]
}>()

const form = useForm({
  name: props.rule.name,
  source_account_id: props.rule.source_account_id as number | null,
  source_cost_center_id: props.rule.source_cost_center_id,
  targets: props.rule.targets.map((t) => ({ ...t, percentage: String(t.percentage) })),
})

const addTarget = () => form.targets.push({ cost_center_id: null, percentage: '' })
const removeTarget = (i: number) => form.targets.splice(i, 1)

const totalPercentage = computed(() => form.targets.reduce((sum, t) => sum + (Number(t.percentage) || 0), 0))
const isValidTotal = computed(() => Math.abs(totalPercentage.value - 100) < 0.005)
const sourceEqualsTarget = computed(() => form.source_cost_center_id !== null && form.targets.some((t) => t.cost_center_id === form.source_cost_center_id))

const submit = () => form.transform((data) => ({
  ...data,
  targets: data.targets.map((t) => ({ ...t, percentage: Number(t.percentage) || 0 })),
})).put(route('accounting.allocation-rules.update', props.rule.id))

const toggleActive = () => router.post(route('accounting.allocation-rules.set-active', props.rule.id), { is_active: !props.rule.is_active }, { preserveScroll: true })

const destroy = () => {
  if (confirm(`Delete allocation rule "${props.rule.name}"? Only possible if it has never been run.`)) {
    router.delete(route('accounting.allocation-rules.destroy', props.rule.id))
  }
}
</script>

<template>
  <AppLayout>
    <PageHeader :title="rule.name" description="Percentages must sum to exactly 100.">
      <template #actions>
        <a :href="route('accounting.allocation-rules.run.show', rule.id)" class="mr-4 text-sm font-medium text-accent hover:underline">Run</a>
        <button type="button" class="mr-4 text-sm font-medium text-accent hover:underline" @click="toggleActive">{{ rule.is_active ? 'Pause' : 'Resume' }}</button>
        <button type="button" class="mr-4 text-sm font-medium text-signal-danger hover:underline" @click="destroy">Delete</button>
        <Link :href="route('accounting.allocation-rules.index', { company_id: rule.company_id })" class="text-sm font-medium text-accent hover:underline">← Rules</Link>
      </template>
    </PageHeader>

    <div class="mt-6">
      <StatusBadge :status="rule.is_active ? 'active' : 'paused'" />
    </div>

    <Panel class="mt-4">
      <form class="space-y-6" @submit.prevent="submit">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
          <FormInput v-model="form.name" name="name" label="Rule name" :error="form.errors.name" required />
          <FormSearchableSelect v-model="form.source_account_id" name="source_account_id" label="Source account" :options="accounts" :error="form.errors.source_account_id" required />
          <FormSearchableSelect
            v-model="form.source_cost_center_id"
            name="source_cost_center_id"
            label="Source cost center"
            placeholder="Unassigned (no cost center)"
            :options="costCenters"
            :error="form.errors.source_cost_center_id"
          />
        </div>

        <div>
          <div class="mb-2 flex items-center justify-between">
            <h3 class="text-sm font-semibold text-ink-900">Target cost centers</h3>
            <button type="button" class="inline-flex items-center gap-1 text-sm font-medium text-accent hover:underline" @click="addTarget">
              <Plus class="h-4 w-4" /> Add target
            </button>
          </div>

          <div class="overflow-x-auto rounded-sm border border-border">
            <table class="w-full text-sm">
              <thead>
                <tr class="border-b border-border bg-surface-50 text-left text-xs uppercase text-ink-600">
                  <th class="w-3/5 px-3 py-2">Cost center</th>
                  <th class="px-3 py-2 text-right">Percentage</th>
                  <th class="px-3 py-2"></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(target, i) in form.targets" :key="i" class="border-b border-border last:border-b-0">
                  <td class="px-3 py-2">
                    <FormSearchableSelect v-model="target.cost_center_id" :name="`targets.${i}.cost_center_id`" :options="costCenters" :error="(form.errors as any)[`targets.${i}.cost_center_id`]" />
                  </td>
                  <td class="px-3 py-2">
                    <input v-model="target.percentage" type="number" step="0.01" min="0" max="100" class="w-28 rounded-sm border border-border bg-surface-0 px-2 py-1.5 text-right text-sm" />
                  </td>
                  <td class="px-3 py-2 text-right">
                    <button type="button" class="text-ink-600 hover:text-signal-danger" :disabled="form.targets.length <= 1" @click="removeTarget(i)">
                      <Trash2 class="h-4 w-4" />
                    </button>
                  </td>
                </tr>
              </tbody>
              <tfoot>
                <tr class="border-t border-border bg-surface-50 font-semibold">
                  <td class="px-3 py-2">Total</td>
                  <td class="px-3 py-2 text-right">{{ totalPercentage.toFixed(2) }}%</td>
                  <td class="px-3 py-2">
                    <span :class="isValidTotal ? 'text-signal-success' : 'text-signal-danger'">{{ isValidTotal ? 'OK' : 'Must total 100' }}</span>
                  </td>
                </tr>
              </tfoot>
            </table>
          </div>
          <p v-if="sourceEqualsTarget" class="mt-2 text-sm text-signal-danger">A target cannot be the same as the source cost center — that portion would net to zero.</p>
          <p v-if="(form.errors as any).targets" class="mt-2 text-sm text-signal-danger">{{ (form.errors as any).targets }}</p>
        </div>

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <PrimaryButton type="submit" :disabled="form.processing">Save changes</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
