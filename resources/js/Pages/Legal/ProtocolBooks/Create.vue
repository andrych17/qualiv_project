<!-- ponytail: Open protocol book (§3F) -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'

const props = defineProps<{
  bookTypes: string[]
  notaries: Array<{ id: number; name: string }>
  currentYear: number
}>()

const TYPE_LABEL: Record<string, string> = {
  repertorium: 'Repertorium',
  legalisasi: 'Buku Daftar Legalisasi',
  waarmerking: 'Buku Daftar Waarmerking',
  protes: 'Buku Daftar Protes',
  daftar_wasiat: 'Buku Daftar Wasiat',
  lain_lain: 'Lain-lain',
}

const form = useForm({
  book_type: 'repertorium',
  year: props.currentYear,
  volume: 1,
  notary_user_id: null as number | null,
})

const submit = () => form.post(route('legal.protocolBooks.store'))
</script>

<template>
  <AppLayout>
    <PageHeader title="Open protocol book" description="One row per book type × year × volume — the statutory ledger a notary is personally liable for." />

    <Panel class="mt-6 max-w-xl">
      <form class="space-y-4" @submit.prevent="submit">
        <FormSelect
          v-model="form.book_type"
          name="book_type"
          label="Book type"
          :options="bookTypes.map((t) => ({ label: TYPE_LABEL[t] ?? t, value: t }))"
          :error="form.errors.book_type"
          required
        />
        <div class="grid grid-cols-2 gap-4">
          <FormInput v-model="form.year" name="year" type="number" label="Year" :error="form.errors.year" required />
          <FormInput v-model="form.volume" name="volume" type="number" label="Volume" :error="form.errors.volume" required />
        </div>
        <FormSelect
          v-model="form.notary_user_id"
          name="notary_user_id"
          label="Notary"
          placeholder="Select notary"
          :options="notaries.map((n) => ({ label: n.name, value: n.id }))"
          :error="form.errors.notary_user_id"
          required
        />
        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <Link
            :href="route('legal.protocolBooks.index')"
            class="inline-flex items-center justify-center rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm font-semibold text-ink-900 shadow-sm transition hover:bg-surface-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            Cancel
          </Link>
          <PrimaryButton type="submit" :disabled="form.processing">Open book</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
