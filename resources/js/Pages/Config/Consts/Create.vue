<!-- ponytail: Config const create form -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import FormInput from '@/Components/forms/FormInput.vue'

const form = useForm({
  const_group: '',
  group_code: '',
  seq: 1,
  str1: '',
  str2: '',
  num1: null as number | null,
  num2: null as number | null,
  note1: '',
})

const submit = () => form.post(route('config.consts.store'))
</script>

<template>
  <AppLayout>
    <PageHeader title="Create Const" description="Add a tenant configuration constant." />

    <div class="mt-6 max-w-2xl rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
      <form class="space-y-4" @submit.prevent="submit">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <FormInput
            v-model="form.const_group"
            name="const_group"
            label="Const group"
            placeholder="e.g. STATUS"
            :error="form.errors.const_group"
            required
          />
          <FormInput
            v-model="form.group_code"
            name="group_code"
            label="Key"
            placeholder="e.g. ACTIVE"
            :error="form.errors.group_code"
            required
          />
        </div>

        <FormInput
          v-model.number="form.seq"
          name="seq"
          label="Sequence"
          type="number"
          :error="form.errors.seq"
          required
        />

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <FormInput v-model="form.str1" name="str1" label="Str1" :error="form.errors.str1" />
          <FormInput v-model="form.str2" name="str2" label="Str2" :error="form.errors.str2" />
        </div>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <FormInput v-model="form.num1" name="num1" label="Num1" type="number" :error="form.errors.num1" />
          <FormInput v-model="form.num2" name="num2" label="Num2" type="number" :error="form.errors.num2" />
        </div>

        <FormInput v-model="form.note1" name="note1" label="Note" :error="form.errors.note1" />

        <div class="flex items-center justify-end gap-3 border-t border-gray-100 pt-4">
          <Link :href="route('config.consts.index')" class="text-sm font-semibold text-gray-900">
            Cancel
          </Link>
          <button
            type="submit"
            :disabled="form.processing"
            class="rounded-md bg-gray-900 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-gray-800 disabled:opacity-50"
          >
            Save Const
          </button>
        </div>
      </form>
    </div>
  </AppLayout>
</template>
