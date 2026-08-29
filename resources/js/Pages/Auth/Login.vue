<script setup lang="ts">
import { ref, nextTick } from 'vue';
import axios from 'axios';
import { Building2, Check, ArrowRight, ArrowLeft, Loader2 } from 'lucide-vue-next';
import Checkbox from '@/Components/Checkbox.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

interface TenantOption {
    id: string;
    name: string;
}

defineProps<{
    canResetPassword?: boolean;
    status?: string;
}>();

const step = ref<'email' | 'password'>('email');
const isLookingUp = ref(false);
const lookupError = ref<string | null>(null);
const tenants = ref<TenantOption[]>([]);
const passwordInputRef = ref<HTMLInputElement | null>(null);

const form = useForm({
    email: '',
    password: '',
    tenant_id: '',
    remember: false,
});

const handleLookupEmail = async () => {
    lookupError.value = null;

    if (!form.email || !form.email.includes('@')) {
        lookupError.value = 'Silakan masukkan alamat email yang valid.';
        return;
    }

    isLookingUp.value = true;

    try {
        const response = await axios.post<{ tenants: TenantOption[] }>('/login/lookup', {
            email: form.email,
        });

        const foundTenants = response.data.tenants || [];
        tenants.value = foundTenants;

        if (foundTenants.length === 0) {
            lookupError.value = 'Email ini tidak terdaftar di sistem Nusaevo.';
            return;
        }

        form.tenant_id = foundTenants[0].id;
        step.value = 'password';

        await nextTick();
        if (passwordInputRef.value) {
            passwordInputRef.value.focus();
        }
    } catch (err: any) {
        lookupError.value = err?.response?.data?.message || 'Gagal memeriksa akun. Silakan coba lagi.';
    } finally {
        isLookingUp.value = false;
    }
};

const resetToEmail = () => {
    step.value = 'email';
    form.password = '';
    form.clearErrors();
    lookupError.value = null;
};

const submit = () => {
    form.post('/login', {
        onFinish: () => {
            form.reset('password');
        },
    });
};
</script>

<template>
    <GuestLayout>
        <Head title="Log in" />

        <div v-if="status" class="mb-4 text-sm font-medium text-green-600">
            {{ status }}
        </div>

        <!-- STEP 1: INPUT EMAIL -->
        <form v-if="step === 'email'" @submit.prevent="handleLookupEmail" class="space-y-4">
            <div>
                <InputLabel for="email" value="Email" />

                <TextInput
                    id="email"
                    type="email"
                    class="mt-1 block w-full"
                    v-model="form.email"
                    required
                    autofocus
                    autocomplete="username"
                    placeholder="nama@perusahaan.com"
                    :disabled="isLookingUp"
                />

                <InputError class="mt-2" :message="lookupError || form.errors.email" />
            </div>

            <div class="pt-2 flex justify-end">
                <PrimaryButton
                    type="submit"
                    class="w-full sm:w-auto flex items-center justify-center gap-2"
                    :disabled="isLookingUp || !form.email"
                >
                    <Loader2 v-if="isLookingUp" class="h-4 w-4 animate-spin" />
                    <span>Lanjutkan</span>
                    <ArrowRight v-if="!isLookingUp" class="h-4 w-4" />
                </PrimaryButton>
            </div>
        </form>

        <!-- STEP 2: TENANT SELECTION (IF MULTI) & PASSWORD -->
        <form v-else @submit.prevent="submit" class="space-y-4">
            <!-- Email Summary with Change Action -->
            <div class="flex items-center justify-between p-2.5 rounded-lg border border-border bg-surface-50">
                <div class="truncate text-sm text-ink-900 font-medium">
                    {{ form.email }}
                </div>
                <button
                    type="button"
                    class="text-xs text-accent hover:underline flex items-center gap-1 font-medium cursor-pointer"
                    @click="resetToEmail"
                >
                    <ArrowLeft class="h-3.5 w-3.5" />
                    <span>Ganti</span>
                </button>
            </div>

            <!-- Single Tenant Context Info -->
            <div v-if="tenants.length === 1" class="flex items-center gap-2.5 px-3 py-2 rounded-md bg-accent/5 border border-accent/20 text-ink-900 text-xs">
                <Building2 class="h-4 w-4 shrink-0 text-accent" />
                <span class="truncate">
                    Masuk ke: <strong class="font-semibold">{{ tenants[0].name }}</strong>
                </span>
            </div>

            <!-- Multiple Tenant Selection -->
            <div v-else-if="tenants.length > 1" class="space-y-2">
                <InputLabel value="Pilih Perusahaan / Organisasi" />
                <div class="space-y-1.5 max-h-48 overflow-y-auto pr-1">
                    <button
                        v-for="t in tenants"
                        :key="t.id"
                        type="button"
                        class="flex w-full items-center justify-between px-3 py-2 text-left rounded-md border text-sm transition cursor-pointer"
                        :class="form.tenant_id === t.id
                            ? 'border-accent bg-accent/10 font-semibold text-accent'
                            : 'border-border bg-surface-0 text-ink-900 hover:bg-surface-50'"
                        @click="form.tenant_id = t.id"
                    >
                        <div class="flex items-center gap-2 truncate">
                            <Building2 class="h-4 w-4 shrink-0 opacity-70" />
                            <span class="truncate">{{ t.name }}</span>
                        </div>
                        <Check v-if="form.tenant_id === t.id" class="h-4 w-4 shrink-0 text-accent" />
                    </button>
                </div>
                <InputError class="mt-1" :message="form.errors.tenant_id" />
            </div>

            <!-- Password Input -->
            <div>
                <InputLabel for="password" value="Password" />

                <TextInput
                    id="password"
                    ref="passwordInputRef"
                    type="password"
                    class="mt-1 block w-full"
                    v-model="form.password"
                    required
                    autocomplete="current-password"
                    placeholder="Masukkan password"
                />

                <InputError class="mt-2" :message="form.errors.password || form.errors.email" />
            </div>

            <!-- Remember me -->
            <div class="block">
                <label class="flex items-center">
                    <Checkbox name="remember" v-model:checked="form.remember" />
                    <span class="ms-2 text-sm text-ink-600">Ingat saya</span>
                </label>
            </div>

            <!-- Action Buttons -->
            <div class="flex flex-col-reverse gap-3 pt-2 sm:flex-row sm:items-center sm:justify-between sm:gap-0">
                <Link
                    v-if="canResetPassword"
                    :href="route('password.request')"
                    class="rounded-md text-center text-sm text-ink-600 underline hover:text-ink-900 focus:outline-none focus:ring-2 focus:ring-accent focus:ring-offset-2 sm:text-left"
                >
                    Lupa password?
                </Link>
                <div v-else />

                <div class="flex items-center gap-2 justify-end">
                    <SecondaryButton
                        type="button"
                        @click="resetToEmail"
                        :disabled="form.processing"
                    >
                        Kembali
                    </SecondaryButton>

                    <PrimaryButton
                        type="submit"
                        class="w-full sm:w-auto"
                        :class="{ 'opacity-25': form.processing }"
                        :disabled="form.processing || !form.password"
                    >
                        Masuk
                    </PrimaryButton>
                </div>
            </div>
        </form>
    </GuestLayout>
</template>
