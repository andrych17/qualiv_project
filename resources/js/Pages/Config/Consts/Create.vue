<!-- ponytail: Config const create — settings + enum payload on one form (SYSCONFIG_SPECS.md §3B/§3C) -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'

const props = defineProps<{
  moduleCodes: Array<{ label: string; value: string }>
  groups: Array<{ label: string; value: number }>
  users: Array<{ label: string; value: number }>
}>()

const form = useForm({
  appl_id: '',
  group_id: '' as string | number,
  user_id: '' as string | number,
  const_group: '',
  group_code: '',
  value: '',
  value_type: 'text',
  seq: 1,
  str1: '',
  str2: '',
  num1: null as number | null,
  num2: null as number | null,
  note1: '',
  effective_date: '',
})

const submit = () => form.post(route('config.consts.store'))
</script>

<template>
  <AppLayout>
    <PageHeader title="Create Const" description="Add a tenant setting or mini-enum member." />

    <div class="mt-6 max-w-2xl rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
      <form class="space-y-4" @submit.prevent="submit">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <FormInput
            v-model="form.const_group"
            name="const_group"
            label="Const group"
            placeholder="e.g. LEGAL or GENDER"
            :error="form.errors.const_group"
            required
          />
          <FormInput
            v-model="form.group_code"
            name="group_code"
            label="Key"
            placeholder="e.g. CASE_PREFIX"
            :error="form.errors.group_code"
            required
          />
        </div>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
          <FormSelect
            v-model="form.appl_id"
            name="appl_id"
            label="Module scope"
            :options="[{ label: '(platform-wide)', value: '' }, ...props.moduleCodes]"
            :error="form.errors.appl_id"
          />
          <FormSelect
            v-model="form.group_id"
            name="group_id"
            label="Group override"
            :options="[{ label: '(none)', value: '' }, ...props.groups]"
            :error="form.errors.group_id"
          />
          <FormSelect
            v-model="form.user_id"
            name="user_id"
            label="User override"
            :options="[{ label: '(none)', value: '' }, ...props.users]"
            :error="form.errors.user_id"
          />
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

        <FormInput
          v-model.number="form.seq"
          name="seq"
          label="Sequence"
          type="number"
          :error="form.errors.seq"
          required
        />

        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Enum payload (optional)</p>
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

        <div class="flex items-center justify-end gap-3 border-t border-gray-100 pt-4">
          <Link :href="route('config.consts.index')" class="text-sm font-semibold text-gray-900">
            Cancel
          </Link>
          <button
            type="submit"
            :disabled="form.processing"
            class="min-h-11 rounded-md bg-gray-900 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-gray-800 disabled:opacity-50"
          >
            Save Const
          </button>
        </div>
      </form>
    </div>
  </AppLayout>
</template>
