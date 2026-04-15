<script setup lang="ts">
import AppLogo from '@/components/AppLogo.vue';
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Head, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Briefcase, Eye, EyeOff, GraduationCap, LoaderCircle, Shield } from 'lucide-vue-next';
import { computed, ref } from 'vue';

defineProps<{ status?: string; canResetPassword: boolean }>();

const selectedRole = ref<'admin' | 'accounting' | 'student' | null>(null);
const showPassword = ref(false);

const form = useForm({
    email: '',
    password: '',
    remember: false,
    role: null as 'admin' | 'accounting' | 'student' | null,
});

const roleOptions = [
    { value: 'admin', label: 'Admin', icon: Shield, accentBg: '#fef2f2', accentText: '#991b1b', iconBg: '#dc2626', description: 'System administrators and managers' },
    { value: 'accounting', label: 'Accounting', icon: Briefcase, accentBg: '#eff6ff', accentText: '#1e40af', iconBg: '#2563eb', description: 'Accounting staff and financial officers' },
    { value: 'student', label: 'Student', icon: GraduationCap, accentBg: '#f0fdf4', accentText: '#166534', iconBg: '#16a34a', description: 'Students and learners' },
] as const;

const currentRole = computed(() => roleOptions.find((r) => r.value === selectedRole.value));
const selectRole = (role: 'admin' | 'accounting' | 'student') => { selectedRole.value = role; form.role = role; };
const backToRoleSelection = () => { selectedRole.value = null; form.reset('password'); };

const submit = () => {
    if (!selectedRole.value) {
        form.errors.role = ['Please select a role before logging in.'];
        return;
    }

    form.post(route('login.store'), {
        preserveScroll: true,
        onError: () => {
            form.reset('password');
        },
    });
};
</script>

<template>
    <Head title="Log in" />

    <div class="flex min-h-screen items-center justify-center px-4 py-12" style="font-family: 'Plus Jakarta Sans', system-ui, sans-serif; background-image: url('/images/login.png'); background-size: cover; background-position: center; background-repeat: no-repeat;">
        <div class="w-full max-w-sm">
            <!-- Logo -->
            <div class="mb-8 flex justify-center">
                <AppLogo />
            </div>

            <!-- Card -->
            <div class="overflow-hidden rounded-2xl bg-white shadow-xl" style="border: 1px solid #e8edf5;">

                <!-- Status message -->
                <div v-if="status" class="border-b border-green-100 bg-green-50 px-6 py-3 text-center text-sm font-medium text-green-700">{{ status }}</div>

                <!-- ROLE SELECTION -->
                <template v-if="!selectedRole">
                    <div class="px-6 pt-6 pb-4 text-center">
                        <h1 class="text-xl font-bold text-gray-900">Welcome to CCDI Portal</h1>
                        <p class="mt-1 text-sm text-gray-500">Select your role to get started</p>
                    </div>
                    <div class="space-y-2.5 px-6 pb-6">
                        <button
                            v-for="role in roleOptions" :key="role.value"
                            type="button" @click="selectRole(role.value)"
                            class="group flex w-full items-center gap-3.5 rounded-xl p-3.5 text-left transition-all hover:scale-[1.01] hover:shadow-sm"
                            :style="{ background: role.accentBg, border: '1px solid transparent' }"
                        >
                            <div class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-xl text-white" :style="{ background: role.iconBg }">
                                <component :is="role.icon" :size="18" />
                            </div>
                            <div class="flex-1">
                                <p class="text-sm font-semibold text-gray-900">{{ role.label }}</p>
                                <p class="text-xs text-gray-500">{{ role.description }}</p>
                            </div>
                            <svg class="h-4 w-4 text-gray-400 transition-transform group-hover:translate-x-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" /></svg>
                        </button>
                    </div>
                    <div class="border-t border-gray-100 px-6 py-4 text-center text-sm text-gray-500">
                        Don't have an account? <TextLink :href="route('register')" class="font-medium text-blue-600">Sign up as Student</TextLink>
                    </div>
                </template>

                <!-- LOGIN FORM -->
                <template v-else>
                    <div class="px-6 pt-6 pb-2">
                        <h1 class="text-xl font-bold text-gray-900">Log in as {{ currentRole?.label }}</h1>
                        <p class="mt-0.5 text-sm text-gray-500">Enter your credentials to continue</p>
                    </div>

                    <!-- Role badge -->
                    <div class="mx-6 mt-4 flex items-center justify-between rounded-xl p-3" :style="{ background: currentRole?.accentBg, border: '1.5px solid ' + currentRole?.iconBg + '40' }">
                        <div class="flex items-center gap-3">
                            <div class="flex h-8 w-8 items-center justify-center rounded-lg text-white" :style="{ background: currentRole?.iconBg }">
                                <component :is="currentRole?.icon" :size="16" />
                            </div>
                            <div>
                                <p class="text-xs text-gray-500">Logging in as</p>
                                <p class="text-sm font-semibold" :style="{ color: currentRole?.accentText }">{{ currentRole?.label }}</p>
                            </div>
                        </div>
                        <button type="button" @click="backToRoleSelection" class="flex h-7 w-7 items-center justify-center rounded-lg text-gray-400 transition-all hover:bg-white/70 hover:text-gray-600">
                            <ArrowLeft :size="15" />
                        </button>
                    </div>

                    <form @submit.prevent="submit" class="space-y-4 px-6 py-5">
                        <div class="space-y-1.5">
                            <Label for="email" class="text-sm font-medium text-gray-700">Email address</Label>
                            <Input id="email" type="email" v-model="form.email" required autofocus autocomplete="email" class="h-10 rounded-xl border-gray-200 bg-gray-50 text-sm focus:bg-white" />
                            <InputError :message="form.errors.email" />
                        </div>

                        <div class="space-y-1.5">
                            <div class="flex items-center justify-between">
                                <Label for="password" class="text-sm font-medium text-gray-700">Password</Label>
                                <TextLink v-if="canResetPassword" :href="route('password.request')" class="text-xs text-blue-600 hover:underline">Forgot password?</TextLink>
                            </div>
                            <div class="relative">
                                <Input id="password" :type="showPassword ? 'text' : 'password'" v-model="form.password" required autocomplete="current-password" class="h-10 rounded-xl border-gray-200 bg-gray-50 pr-10 text-sm focus:bg-white" />
                                <button type="button" @click="showPassword = !showPassword" class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600">
                                    <EyeOff v-if="!showPassword" :size="16" /><Eye v-else :size="16" />
                                </button>
                            </div>
                            <InputError :message="form.errors.password" />
                        </div>

                        <div class="flex items-center gap-2">
                            <Checkbox v-model:checked="form.remember" id="remember" />
                            <Label for="remember" class="text-sm text-gray-600">Remember me</Label>
                        </div>

                        <button
                            type="submit"
                            :disabled="form.processing"
                            class="flex w-full items-center justify-center gap-2 rounded-xl py-2.5 text-sm font-semibold text-white transition-all hover:opacity-90 disabled:opacity-60"
                            :style="{ background: 'linear-gradient(135deg, ' + currentRole?.iconBg + ' 0%, ' + currentRole?.iconBg + 'cc 100%)' }"
                        >
                            <LoaderCircle v-if="form.processing" :size="16" class="animate-spin" />
                            Log in
                        </button>

                        <p class="text-center text-xs text-gray-400">
                            <button type="button" @click="backToRoleSelection" class="text-blue-600 hover:underline">← Back to role selection</button>
                        </p>
                    </form>
                </template>
            </div>

            <p class="mt-6 text-center text-xs text-black">© {{ new Date().getFullYear() }} CCDI Account Portal. All rights reserved.</p>
        </div>
    </div>
</template>
