<!-- ponytail: Direct single-screen login with email, password, and optional multi-tenant selector -->
<script setup lang="ts">
import { ref } from 'vue';
import axios from 'axios';
import { Building2, Check } from 'lucide-vue-next';
import Checkbox from '@/Components/Checkbox.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
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

const tenants = ref<TenantOption[]>([]);
const isCheckingTenants = ref(false);

const form = useForm({
    email: '',
    password: '',
    tenant_id: '',
    remember: false,
});

const checkTenants = async () => {
    if (!form.email || !form.email.includes('@')) {
        tenants.value = [];
        return;
    }

    try {
        isCheckingTenants.value = true;
        const response = await axios.post<{ tenants: TenantOption[] }>('/login/lookup', {
            email: form.email,
        });
        const found = response.data.tenants || [];
        tenants.value = found;
        if (found.length === 1) {
            form.tenant_id = found[0].id;
        }
    } catch {
        // Silently ignore lookup error; backend login validation will handle it
    } finally {
        isCheckingTenants.value = false;
    }
};

const submit = () => {
    form.post('/login', {
        onError: async (errors) => {
            if (errors.tenant_id && tenants.value.length === 0) {
                await checkTenants();
            }
        },
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

        <form @submit.prevent="submit" class="space-y-4">
            <!-- Email Input -->
            <div>
                <InputLabel for="email" value="Email" />
                <TextInput
                    id="email"
                    type="email"
                    class="mt-1 block w-full"
                    v-model="form.email"
                    @blur="checkTenants"
                    required
                    autofocus
                    autocomplete="username"
                    placeholder="nama@perusahaan.com"
                />
                <InputError class="mt-2" :message="form.errors.email" />
            </div>

            <!-- Multiple Tenant Selection (Shown only if user belongs to >1 tenant) -->
            <div v-if="tenants.length > 1" class="space-y-2">
                <InputLabel value="Pilih Perusahaan / Organisasi" />
                <div class="space-y-1.5 max-h-40 overflow-y-auto pr-1">
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
                    type="password"
                    class="mt-1 block w-full"
                    v-model="form.password"
                    required
                    autocomplete="current-password"
                    placeholder="Masukkan password"
                />
                <InputError class="mt-2" :message="form.errors.password" />
            </div>

            <!-- Remember Me & Forgot Password -->
            <div class="flex items-center justify-between">
                <label class="flex items-center">
                    <Checkbox name="remember" v-model:checked="form.remember" />
                    <span class="ms-2 text-sm text-ink-600">Ingat saya</span>
                </label>

                <Link
                    v-if="canResetPassword"
                    :href="route('password.request')"
                    class="text-sm text-ink-600 underline hover:text-ink-900 focus:outline-none focus:ring-2 focus:ring-accent focus:ring-offset-2"
                >
                    Lupa password?
                </Link>
            </div>

            <!-- Submit Button -->
            <div class="pt-2">
                <PrimaryButton
                    type="submit"
                    class="w-full flex items-center justify-center"
                    :class="{ 'opacity-25': form.processing }"
                    :disabled="form.processing"
                >
                    Masuk
                </PrimaryButton>
            </div>
        </form>
    </GuestLayout>
</template>
