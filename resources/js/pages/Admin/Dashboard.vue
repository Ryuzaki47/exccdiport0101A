<script setup lang="ts">
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

import { computed } from 'vue';

interface Props {
    stats?: {
        total_admins: number;
        active_admins: number;
        inactive_admins: number;
        pending_approvals: number;
        total_users: number;
        total_students: number;
        recent_notifications: Array<{
            id: number;
            title: string;
            target_role: string;
            start_date: string;
            end_date: string;
            created_at: string;
        }>;
        system_health: {
            status: string;
        };
    };
}

const props = withDefaults(defineProps<Props>(), {
    stats: () => ({
        total_admins: 0,
        active_admins: 0,
        inactive_admins: 0,
        pending_approvals: 0,
        total_users: 0,
        total_students: 0,
        recent_notifications: [],
        system_health: {
            status: 'operational',
        },
    }),
});

const breadcrumbs = [
    { title: 'Admin', href: route('admin.dashboard') },
    { title: 'Dashboard', href: route('admin.dashboard') },
];

const adminStats = computed(() => [
    {
        title: 'Total Admins',
        value: props.stats?.total_admins || 0,
        description: `${props.stats?.active_admins || 0} active`,
        color: 'blue',
    },
    {
        title: 'Total Users',
        value: props.stats?.total_users || 0,
        description: `${props.stats?.total_students || 0} students`,
        color: 'purple',
    },
    {
        title: 'Pending Approvals',
        value: props.stats?.pending_approvals || 0,
        description: 'Awaiting action',
        color: 'orange',
    },
    {
        title: 'System Status',
        value: 'Operational',
        description: 'All systems healthy',
        color: 'green',
    },
]);

const getColorClass = (color: string) => {
    const colors: Record<string, string> = {
        blue: 'text-blue-500',
        purple: 'text-purple-500',
        orange: 'text-orange-500',
        green: 'text-green-500',
    };
    return colors[color] || 'text-gray-500';
};
</script>

<template>
    <AppLayout>
        <Head title="Admin Dashboard" />

        <div class="w-full space-y-5 p-6">
            <Breadcrumbs :items="breadcrumbs" />

            <!-- Page header -->
            <div class="ccdi-page-header">
                <div>
                    <h1 class="ccdi-section-title">Admin Dashboard</h1>
                    <p class="ccdi-section-desc">Welcome to your administration center</p>
                </div>
            </div>

            <!-- Stats Grid -->
            <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                <div class="ccdi-stat-card">
                    <div class="min-w-0">
                        <p class="truncate text-xs font-semibold uppercase tracking-wide text-muted-foreground">Total Admins</p>
                        <p class="text-xl font-bold text-foreground">{{ props.stats?.total_admins || 0 }}</p>
                        <p class="text-xs text-muted-foreground">{{ props.stats?.active_admins || 0 }} active</p>
                    </div>
                </div>
                <div class="ccdi-stat-card">
                    <div class="min-w-0">
                        <p class="truncate text-xs font-semibold uppercase tracking-wide text-muted-foreground">Total Users</p>
                        <p class="text-xl font-bold text-foreground">{{ props.stats?.total_users || 0 }}</p>
                        <p class="text-xs text-muted-foreground">{{ props.stats?.total_students || 0 }} students</p>
                    </div>
                </div>
                <div class="ccdi-stat-card">
                    <div class="min-w-0">
                        <p class="truncate text-xs font-semibold uppercase tracking-wide text-muted-foreground">Pending Approvals</p>
                        <p class="text-xl font-bold" :class="(props.stats?.pending_approvals || 0) > 0 ? 'text-amber-600' : 'text-foreground'">{{ props.stats?.pending_approvals || 0 }}</p>
                        <p class="text-xs text-muted-foreground">Awaiting action</p>
                    </div>
                </div>
                <div class="ccdi-stat-card">
                    <div class="min-w-0">
                        <p class="truncate text-xs font-semibold uppercase tracking-wide text-muted-foreground">System Status</p>
                        <p class="text-xl font-bold text-emerald-600">Operational</p>
                        <p class="text-xs text-muted-foreground">All systems healthy</p>
                    </div>
                </div>
            </div>

            <!-- Main grid: Quick Actions + System Status -->
            <div class="grid grid-cols-1 gap-5 lg:grid-cols-2">
                <!-- Quick Actions -->
                <div class="ccdi-card p-5">
                    <h2 class="mb-4 text-base font-semibold text-foreground">Quick Actions</h2>
                    <p class="mb-4 text-xs text-muted-foreground">Common administrative tasks</p>
                    <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                        <Link :href="route('users.create')" class="rounded-xl border border-border bg-card px-4 py-3 text-sm font-medium text-foreground transition-all hover:border-blue-300 hover:bg-blue-50 hover:text-blue-700">
                            Add Staff
                        </Link>
                        <Link :href="route('student-fees.create-student')" class="rounded-xl border border-border bg-card px-4 py-3 text-sm font-medium text-foreground transition-all hover:border-emerald-300 hover:bg-emerald-50 hover:text-emerald-700">
                            Add Student
                        </Link>
                        <Link :href="route('users.index')" class="rounded-xl border border-border bg-card px-4 py-3 text-sm font-medium text-foreground transition-all hover:border-purple-300 hover:bg-purple-50 hover:text-purple-700">
                            View Staffs
                        </Link>
                        <Link :href="route('admin.notifications.index')" class="rounded-xl border border-border bg-card px-4 py-3 text-sm font-medium text-foreground transition-all hover:border-amber-300 hover:bg-amber-50 hover:text-amber-700">
                            Manage Notifications
                        </Link>
                        <Link :href="route('approvals.index')" class="rounded-xl border border-border bg-card px-4 py-3 text-sm font-medium text-foreground transition-all hover:border-rose-300 hover:bg-rose-50 hover:text-rose-700">
                            Payment Approvals
                        </Link>
                        <Link :href="route('students.archive')" class="rounded-xl border border-border bg-card px-4 py-3 text-sm font-medium text-foreground transition-all hover:border-gray-400 hover:bg-gray-50 hover:text-gray-700">
                            Student Archive
                        </Link>
                    </div>
                </div>

                <!-- System Status -->
                <div class="ccdi-card p-5">
                    <div class="mb-4 flex items-center gap-2">
                        <h2 class="text-base font-semibold text-foreground">System Status</h2>
                        <span class="ml-auto ccdi-badge-green text-xs">Real-time</span>
                    </div>

                    <!-- Overall health -->
                    <div class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 p-4">
                        <div>
                            <p class="text-sm font-semibold text-emerald-900">All Systems Operational</p>
                            <p class="text-xs text-emerald-700">All services are running normally</p>
                        </div>
                    </div>

                    <!-- Service rows -->
                    <div class="space-y-2.5">
                        <div class="flex items-center justify-between rounded-xl border border-border bg-muted/30 px-4 py-3">
                            <div class="flex items-center gap-2.5">
                                <span class="h-2 w-2 rounded-full bg-emerald-500 animate-pulse"></span>
                                <span class="text-sm font-medium text-foreground">Database</span>
                            </div>
                            <span class="ccdi-badge-green">Online</span>
                        </div>
                        <div class="flex items-center justify-between rounded-xl border border-border bg-muted/30 px-4 py-3">
                            <div class="flex items-center gap-2.5">
                                <span class="h-2 w-2 rounded-full bg-emerald-500 animate-pulse"></span>
                                <span class="text-sm font-medium text-foreground">API</span>
                            </div>
                            <span class="ccdi-badge-green">Online</span>
                        </div>
                        <div class="flex items-center justify-between rounded-xl border border-border bg-muted/30 px-4 py-3">
                            <div class="flex items-center gap-2.5">
                                <span class="h-2 w-2 rounded-full bg-emerald-500 animate-pulse"></span>
                                <span class="text-sm font-medium text-foreground">Auth</span>
                            </div>
                            <span class="ccdi-badge-green">Online</span>
                        </div>
                        <div class="flex items-center justify-between rounded-xl border border-border bg-muted/30 px-4 py-3">
                            <div class="flex items-center gap-2.5">
                                <span class="h-2 w-2 rounded-full bg-emerald-500 animate-pulse"></span>
                                <span class="text-sm font-medium text-foreground">Payment Gateway</span>
                            </div>
                            <span class="ccdi-badge-green">Online</span>
                        </div>
                    </div>

                    <!-- Admin role breakdown -->
                    <div class="mt-5">
                        <p class="mb-3 text-xs font-semibold uppercase tracking-wider text-muted-foreground">Admin Roles</p>
                        <div class="space-y-2">
                            <div class="flex items-center justify-between text-sm">
                                <span class="text-muted-foreground">Active Admins</span>
                                <span class="font-semibold text-foreground">{{ stats?.active_admins ?? 0 }}</span>
                            </div>
                            <div class="h-2 w-full overflow-hidden rounded-full bg-muted">
                                <div class="h-full rounded-full bg-blue-500 transition-all" :style="{ width: stats?.total_admins ? ((stats.active_admins / stats.total_admins) * 100) + '%' : '0%' }"></div>
                            </div>
                            <div class="flex items-center justify-between text-sm">
                                <span class="text-muted-foreground">Inactive Admins</span>
                                <span class="font-semibold text-foreground">{{ stats?.inactive_admins ?? 0 }}</span>
                            </div>
                            <div class="h-2 w-full overflow-hidden rounded-full bg-muted">
                                <div class="h-full rounded-full bg-gray-400 transition-all" :style="{ width: stats?.total_admins ? ((stats.inactive_admins / stats.total_admins) * 100) + '%' : '0%' }"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recent Notifications -->
            <div v-if="stats?.recent_notifications?.length" class="ccdi-card">
                <div class="flex items-center justify-between border-b border-border px-5 py-4">
                    <h2 class="text-base font-semibold text-foreground">Recent Notifications</h2>
                    <Link href="/admin/notifications" class="text-sm font-medium text-blue-600 hover:text-blue-700 hover:underline">View All →</Link>
                </div>
                <div class="divide-y divide-border">
                    <div v-for="notif in stats.recent_notifications" :key="notif.id" class="flex items-start justify-between px-5 py-3.5 hover:bg-muted/30 transition-colors">
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium text-foreground">{{ notif.title }}</p>
                            <p class="text-xs text-muted-foreground mt-0.5">Target: {{ notif.target_role }} · {{ notif.created_at }}</p>
                        </div>
                        <span class="ccdi-badge-blue flex-shrink-0">{{ notif.target_role }}</span>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>