<script setup lang="ts">
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

interface Admin {
    id: number;
    last_name: string;
    first_name: string;
    middle_initial: string | null;
    email: string;
    department: string;
    is_active: boolean;
    created_at: string;
}

interface Props {
    admins: {
        data: Admin[];
        links: any[];
    };
    stats?: {
        total_admins: number;
        total_active_admins: number;
        total_accounting: number;
        total_active_accounting: number;
    };
    canManage: boolean;
}

defineProps<Props>();

const breadcrumbs = [
    { title: 'Admin', href: route('admin.dashboard') },
    { title: 'Admin Users', href: route('users.index') },
];

const departmentBadge = (dept: string) => {
    const map: Record<string, string> = {
        Administrator: 'bg-purple-100 text-purple-800',
        Accounting: 'bg-blue-100 text-blue-800',
    };
    return map[dept] ?? 'bg-gray-100 text-gray-700';
};

const deactivate = (id: number) => {
    if (confirm('Deactivate this admin?')) {
        const form = useForm({});
        form.post(route('admin.users.deactivate', id));
    }
};

const reactivate = (id: number) => {
    const form = useForm({});
    form.post(route('admin.users.reactivate', id));
};
</script>

<template>
    <Head title="Admin Users" />
    <AppLayout>
        <div class="w-full p-6">
            <Breadcrumbs :items="breadcrumbs" />

            <div class="mb-6 flex items-center justify-between">
                <div>
                    <h1 class="text-3xl font-bold text-gray-900">Admin Users</h1>
                    <p class="mt-1 text-sm text-gray-500">Manage administrator accounts and permissions</p>
                </div>
                <Link v-if="canManage" :href="route('users.create')">
                    <Button>+ Create Staff</Button>
                </Link>
            </div>

            <!-- Department Cards with Active Counts -->
            <div v-if="stats" class="mb-6 grid grid-cols-1 gap-6 md:grid-cols-2">
                <div class="rounded-lg border-2 border-purple-200 bg-white p-6 shadow-sm transition-shadow hover:shadow-md">
                    <h3 class="mb-2 text-lg font-bold text-gray-900">Administrator</h3>
                    <p class="mb-4 text-sm text-gray-600">Full system administrator with all permissions</p>
                    <div class="flex items-baseline gap-2">
                        <span class="text-4xl font-bold text-purple-600">{{ stats.total_active_admins }}</span>
                        <span class="text-sm text-gray-500">Active Users</span>
                    </div>
                </div>
                <div class="rounded-lg border-2 border-blue-200 bg-white p-6 shadow-sm transition-shadow hover:shadow-md">
                    <h3 class="mb-2 text-lg font-bold text-gray-900">Accounting</h3>
                    <p class="mb-4 text-sm text-gray-600">Accounting department user with financial permissions</p>
                    <div class="flex items-baseline gap-2">
                        <span class="text-4xl font-bold text-blue-600">{{ stats.total_active_accounting }}</span>
                        <span class="text-sm text-gray-500">Active Users</span>
                    </div>
                </div>
            </div>

            <!-- Read-only notice -->
            <div
                v-if="!canManage"
                class="mb-4 flex items-center gap-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800"
            >
                <svg class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        stroke-width="2"
                        d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
                    />
                </svg>
                You have view-only access. Only Administrators can create, edit, or deactivate accounts.
            </div>

            <!-- Table -->
            <div class="overflow-hidden rounded-lg border border-gray-100 bg-white shadow-sm">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="border-b bg-gray-50">
                            <tr>
                                <th class="px-5 py-3 text-left font-medium text-gray-600">Name</th>
                                <th class="px-5 py-3 text-left font-medium text-gray-600">Email</th>
                                <th class="px-5 py-3 text-left font-medium text-gray-600">Department</th>
                                <th class="px-5 py-3 text-left font-medium text-gray-600">Status</th>
                                <th class="px-5 py-3 text-left font-medium text-gray-600">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="admin in admins.data" :key="admin.id" class="transition-colors hover:bg-gray-50">
                                <td class="px-5 py-4">
                                    <span class="font-medium text-gray-900">{{ admin.last_name }}, {{ admin.first_name }}</span>
                                    <span v-if="admin.middle_initial" class="text-gray-400"> {{ admin.middle_initial }}.</span>
                                </td>
                                <td class="px-5 py-4 text-gray-600">{{ admin.email }}</td>
                                <td class="px-5 py-4">
                                    <span :class="['rounded-full px-2.5 py-1 text-xs font-medium', departmentBadge(admin.department)]">
                                        {{ admin.department }}
                                    </span>
                                </td>
                                <td class="px-5 py-4">
                                    <span
                                        :class="[
                                            'rounded-full px-2.5 py-1 text-xs font-medium',
                                            admin.is_active ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-700',
                                        ]"
                                    >
                                        {{ admin.is_active ? 'Active' : 'Inactive' }}
                                    </span>
                                </td>
                                <td class="px-5 py-4">
                                    <div class="flex items-center gap-2">
                                        <Link :href="route('users.show', admin.id)">
                                            <Button variant="ghost" size="sm">View</Button>
                                        </Link>
                                        <template v-if="canManage">
                                            <Link :href="route('users.edit', admin.id)">
                                                <Button variant="outline" size="sm">Edit</Button>
                                            </Link>
                                            <Button v-if="admin.is_active" variant="destructive" size="sm" @click="deactivate(admin.id)"
                                                >Deactivate</Button
                                            >
                                            <Button v-else variant="outline" size="sm" @click="reactivate(admin.id)">Reactivate</Button>
                                        </template>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="admins.data.length === 0">
                                <td colspan="5" class="px-5 py-10 text-center text-gray-400">No admin users found.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div v-if="admins.links?.length > 3" class="flex justify-center gap-1 border-t bg-gray-50 px-5 py-3">
                    <Link
                        v-for="link in admins.links"
                        :key="link.label"
                        :href="link.url || '#'"
                        :class="[
                            'rounded px-3 py-1.5 text-xs font-medium transition-colors',
                            link.active ? 'bg-blue-600 text-white' : 'border bg-white text-gray-600 hover:bg-gray-100',
                            !link.url ? 'pointer-events-none opacity-40' : '',
                        ]"
                    >
                        {{ link.label }}
                    </Link>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
