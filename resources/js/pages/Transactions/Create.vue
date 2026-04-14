<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

defineProps<{
    users: { id: number; name: string; email: string }[];
}>();

// Inertia form
const form = useForm({
    user_id: '',
    type: 'charge',
    amount: '',
    payment_channel: 'cash',
});
const submit = () => {
    form.post(route('transactions.store'));
};
</script>

<template>
    <AppLayout>
        <Head title="Create Transaction" />

        <div class="mx-auto mt-8 max-w-2xl rounded-lg bg-white p-6 shadow">
            <h1 class="mb-4 text-xl font-bold">Create Transaction</h1>

            <form @submit.prevent="submit" class="space-y-5">
                <!-- User -->
                <div>
                    <label class="mb-1 block text-sm font-medium">Student</label>
                    <select v-model="form.user_id" class="w-full rounded border px-3 py-2">
                        <option value="">Select a student</option>
                        <option v-for="user in users" :key="user.id" :value="user.id">{{ user.name }} ({{ user.email }})</option>
                    </select>
                    <div v-if="form.errors.user_id" class="text-sm text-red-500">{{ form.errors.user_id }}</div>
                </div>

                <!-- Type -->
                <div>
                    <label class="mb-1 block text-sm font-medium">Type</label>
                    <select v-model="form.type" class="w-full rounded border px-3 py-2">
                        <option value="charge">Charge</option>
                        <option value="payment">Payment</option>
                    </select>
                    <div v-if="form.errors.type" class="text-sm text-red-500">{{ form.errors.type }}</div>
                </div>

                <!-- Amount -->
                <div>
                    <label class="mb-1 block text-sm font-medium">Amount</label>
                    <input v-model="form.amount" type="number" step="0.01" class="w-full rounded border px-3 py-2" placeholder="Enter amount" />
                    <div v-if="form.errors.amount" class="text-sm text-red-500">{{ form.errors.amount }}</div>
                </div>

                <!-- Payment Channel -->
                <div>
                    <label class="mb-1 block text-sm font-medium">Payment Channel</label>
                    <select v-model="form.payment_channel" class="w-full rounded border px-3 py-2">
                        <option value="cash">Cash</option>
                    </select>
                    <div v-if="form.errors.payment_channel" class="text-sm text-red-500">
                        {{ form.errors.payment_channel }}
                    </div>
                </div>

                <!-- Buttons -->
                <div class="flex items-center justify-between">
                    <Link :href="route('transactions.index')" class="rounded bg-gray-500 px-4 py-2 text-white hover:bg-gray-600"> Cancel </Link>
                    <button type="submit" class="rounded bg-blue-600 px-4 py-2 text-white hover:bg-blue-700" :disabled="form.processing">Save</button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
