<!-- ponytail: Config const edit form -->
<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'

interface ConstItem {
  id: number
  appl_id: string | null
  group_id: number | null
  user_id: number | null
  const_group: string | null
  group_code: string | null
  value: string | null
  value_type: string | null
  seq: number
  str1: string | null
  str2: string | null
  num1: string | number | null
  num2: string | number | null
  note1: string | null
  effective_date: string | null
  is_active: boolean
}

const props = defineProps<{
  constItem: ConstItem
  moduleCodes: Array<{ label: string; value: string }>
  groups: Array<{ label: string; value: number }>
  users: Array<{ label: string; value: number }>
}>()

const form = useForm({
  appl_id: props.constItem.appl_id ?? '',
  group_id: props.constItem.group_id ?? ('' as string | number),
  user_id: props.constItem.user_id ?? ('' as string | number),
  const_group: props.constItem.const_group ?? '',
  group_code: props.constItem.group_code ?? '',
  value: props.constItem.value ?? '',
  value_type: props.constItem.value_type ?? 'text',
  seq: props.constItem.seq,
  str1: props.constItem.str1 ?? '',
  str2: props.constItem.str2 ?? '',
  num1: props.constItem.num1,
  num2: props.constItem.num2,
  note1: props.constItem.note1 ?? '',
  effective_date: props.constItem.effective_date ?? '',
  is_active: props.constItem.is_active,
})

const submit = () => form.put(route('config.consts.update', props.constItem.id))
</script>

<template>
  <AppLayout>
    <PageHeader
      title="Edit Const"
      :description="`${constItem.const_group}.${constItem.group_code}`"
    />

    <div class="mt-6 max-w-2xl">
      <Panel>
        <form class="space-y-4" @submit.prevent="submit">
          <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <FormInput v-model="form.const_group" name="const_group" label="Const group" :error="form.errors.const_group" required />
            <FormInput v-model="form.group_code" name="group_code" label="Key" :error="form.errors.group_code" required />
          </div>
          <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <FormSelect v-model="form.appl_id" name="appl_id" label="Module scope" :options="[{ label: '(platform-wide)', value: '' }, ...props.moduleCodes]" :error="form.errors.appl_id" />
            <FormSelect v-model="form.group_id" name="group_id" label="Group override" :options="[{ label: '(none)', value: '' }, ...props.groups]" :error="form.errors.group_id" />
            <FormSelect v-model="form.user_id" name="user_id" label="User override" :options="[{ label: '(none)', value: '' }, ...props.users]" :error="form.errors.user_id" />
          </div>
          <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <FormSelect
              v-model="form.value_type"
              name="value_type"
              label="Value type"
              :options="[
                { label: 'Text', value: 'text' },
                { label: 'Number', value: 'number' },
                { label: 'Bool', value: 'bool' },
                { label: 'Date', value: 'date' },
              ]"
              :error="form.errors.value_type"
              required
            />
            <FormInput v-model="form.value" name="value" label="Value" :error="form.errors.value" />
          </div>
          <FormInput v-model.number="form.seq" name="seq" label="Sequence" type="number" :error="form.errors.seq" required />
          <p class="text-xs font-semibold uppercase tracking-wide text-ink-600">Enum payload (optional)</p>
          <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <FormInput v-model="form.str1" name="str1" label="Str1 (label)" :error="form.errors.str1" />
            <FormInput v-model="form.str2" name="str2" label="Str2 (short)" :error="form.errors.str2" />
          </div>
          <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <FormInput v-model="form.num1" name="num1" label="Num1" type="number" :error="form.errors.num1" />
            <FormInput v-model="form.num2" name="num2" label="Num2" type="number" :error="form.errors.num2" />
          </div>
          <FormInput v-model="form.note1" name="note1" label="Note" :error="form.errors.note1" />
          <FormInput v-model="form.effective_date" name="effective_date" label="Effective date" type="date" :error="form.errors.effective_date" />

          <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
            <SecondaryButton :href="route('config.consts.index')">Cancel</SecondaryButton>
            <PrimaryButton type="submit" :disabled="form.processing">
              Update Const
            </PrimaryButton>
          </div>
        </form>
      </Panel>
    </div>
  </AppLayout>
</template>
