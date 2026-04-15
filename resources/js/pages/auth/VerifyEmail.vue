<script setup lang="ts">
import { Button } from '@/components/ui/button';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { LoaderCircle } from 'lucide-vue-next';

defineProps<{
    status?: string;
}>();

const form = useForm({});
const logoutForm = useForm({});

const handleLogout = () => {
    logoutForm.post(route('logout'));
};
</script>

<template>
    <AuthLayout title="Verify email" description="Please verify your email address by clicking on the link we just emailed to you.">
        <Head title="Email verification" />

        <div v-if="status === 'verification-link-sent'" class="mb-4 text-center text-sm font-medium text-green-600">
            A new verification link has been sent to the email address you provided during registration.
        </div>

        <div class="space-y-6 text-center">
            <form @submit.prevent="form.post(route('verification.send'))">
                <Button :disabled="form.processing" type="submit" variant="secondary">
                    <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
                    Resend verification email
                </Button>
            </form>

            <button
                type="button"
                class="mx-auto block text-sm text-foreground underline decoration-neutral-300 underline-offset-4 transition-colors duration-300 ease-out hover:decoration-current! dark:decoration-neutral-500"
                :disabled="logoutForm.processing"
                @click="handleLogout"
            >
                Log out
            </button>
        </div>
    </AuthLayout>
</template>