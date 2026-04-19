<script setup lang="ts">
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { Bell, Calendar, CalendarClock, Edit2, Plus, Trash2, Users } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface Notification {
    id: number;
    title: string;
    message: string;
    type?: string;
    target_role: string;
    start_date: string;
    end_date?: string | null;
    due_date?: string | null;
    payment_term_id?: number | null;
    is_active: boolean;
    is_complete: boolean;
    target_term_name?: string | null;
    term_ids?: number[] | null;
    trigger_days_before_due?: number | null;
    user_id?: number | null;
    dismissed_at?: string | null;
    created_at: string;
    updated_at: string;
}

interface Props {
    notifications: Notification[];
}

const props = withDefaults(defineProps<Props>(), {
    notifications: () => [],
});

const breadcrumbs = [
    { title: 'Admin', href: route('admin.dashboard') },
    { title: 'Notifications', href: route('notifications.index') },
];

const searchQuery = ref('');

const filteredNotifications = computed(() => {
    if (!searchQuery.value) return props.notifications;
    const q = searchQuery.value.toLowerCase();
    return props.notifications.filter(
        (n) => n.title.toLowerCase().includes(q) || n.message?.toLowerCase().includes(q) || (n.target_term_name ?? '').toLowerCase().includes(q),
    );
});

const deleteNotification = (id: number) => {
    if (confirm('Are you sure you want to delete this notification?')) {
        router.delete(route('notifications.destroy', id));
    }
};

const getRoleColor = (role: string) => {
    const colors: Record<string, string> = {
        student: 'bg-blue-100 text-blue-800',
        accounting: 'bg-purple-100 text-purple-800',
        admin: 'bg-indigo-100 text-indigo-800',
        all: 'bg-gray-100 text-gray-800',
    };
    return colors[role] || 'bg-gray-100 text-gray-800';
};

const getTypeLabel = (type?: string) => {
    const labels: Record<string, string> = {
        general: '📢 General',
        payment_due: '💳 Payment Due',
        payment_approved: '✅ Approved',
        payment_rejected: '❌ Rejected',
    };
    return labels[type || 'general'] || 'General';
};

const getTypeColor = (type?: string) => {
    const colors: Record<string, string> = {
        general: 'bg-blue-100 text-blue-800',
        payment_due: 'bg-amber-100 text-amber-800',
        payment_approved: 'bg-emerald-100 text-emerald-800',
        payment_rejected: 'bg-red-100 text-red-800',
    };
    return colors[type || 'general'] || 'bg-gray-100 text-gray-800';
};

/**
 * Colour-code a due_date by urgency — same logic as the student-facing chip.
 */
const getDueDateChipClass = (dueDateStr: string | null | undefined): string => {
    if (!dueDateStr) return 'bg-gray-100 text-gray-700';
    const diffDays = Math.ceil((new Date(dueDateStr).getTime() - Date.now()) / 86_400_000);
    if (diffDays < 0) return 'bg-red-100 text-red-700 ring-1 ring-red-200';
    if (diffDays <= 7) return 'bg-red-100 text-red-700 ring-1 ring-red-200';
    if (diffDays <= 14) return 'bg-amber-100 text-amber-700 ring-1 ring-amber-200';
    return 'bg-green-100 text-green-700 ring-1 ring-green-200';
};

const formatDueDate = (dueDateStr: string | null | undefined): string => {
    if (!dueDateStr) return '';
    const d = new Date(dueDateStr);
    return d.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
};

/**
 * Format a "YYYY-MM-DD" date string from the server for display.
 * Appending T12:00:00 prevents the date from shifting back one day due
 * to UTC-to-local conversion in Philippine timezone (UTC+8).
 */
const formatAdminDate = (dateStr: string | null | undefined): string => {
    if (!dateStr) return '';
    const d = dateStr.split('T')[0]; // strip any time component defensively
    return new Date(d + 'T12:00:00').toLocaleDateString('en-US', {
        month: 'short', day: 'numeric', year: 'numeric',
    });
};

const todayStr = new Date().toLocaleDateString('en-CA'); // "YYYY-MM-DD" in local time

const isActive = (notification: Notification) => {
    if (!notification.is_active || notification.is_complete) return false;
    const start = notification.start_date?.split('T')[0] ?? '';
    const end   = notification.end_date?.split('T')[0] ?? null;
    return start <= todayStr && (end === null || end >= todayStr);
};
</script>

<template>
    <Head title="Payment Notifications" />

    <AppLayout>
        <div class="w-full p-6">
            <Breadcrumbs :items="breadcrumbs" />

            <!-- Header -->
            <div class="mb-6 flex items-center justify-between">
                <div>
                    <h1 class="mb-2 text-3xl font-bold text-gray-900">Payment Notifications</h1>
                    <p class="text-gray-600">Create and manage notifications for students</p>
                </div>
                <Link :href="route('notifications.create')">
                    <Button>
                        <Plus class="mr-2 h-4 w-4" />
                        Create Notification
                    </Button>
                </Link>
            </div>

            <!-- Search -->
            <div class="mb-6">
                <input
                    v-model="searchQuery"
                    type="text"
                    placeholder="Search by title, message, or term name..."
                    class="w-full rounded-lg border border-gray-300 px-4 py-3 focus:border-transparent focus:ring-2 focus:ring-blue-500"
                />
            </div>

            <!-- Empty state -->
            <div v-if="filteredNotifications.length === 0" class="py-16 text-center">
                <Bell class="mx-auto mb-4 h-12 w-12 text-gray-300" />
                <h3 class="mb-2 text-lg font-semibold text-gray-700">No notifications found</h3>
                <p class="mb-4 text-gray-600">
                    {{ searchQuery ? 'Try adjusting your search' : 'Create your first notification to get started' }}
                </p>
                <Link v-if="!searchQuery" :href="route('notifications.create')">
                    <Button variant="outline">
                        <Plus class="mr-2 h-4 w-4" />
                        Create First Notification
                    </Button>
                </Link>
            </div>

            <!-- Notifications List -->
            <div v-else class="space-y-4">
                <Card v-for="notification in filteredNotifications" :key="notification.id">
                    <CardContent class="pt-6">
                        <!-- Title row + status badge -->
                        <div class="mb-3 flex items-start justify-between">
                            <div class="flex flex-wrap items-center gap-2">
                                <h3 class="text-lg font-semibold text-gray-900">{{ notification.title }}</h3>

                                <!-- Active / Inactive / Complete -->
                                <span
                                    v-if="notification.is_complete"
                                    class="inline-flex items-center rounded-full bg-gray-200 px-3 py-1 text-xs font-medium text-gray-600"
                                >
                                    ✓ Completed
                                </span>
                                <span
                                    v-else-if="isActive(notification)"
                                    class="inline-flex items-center rounded-full bg-green-100 px-3 py-1 text-xs font-medium text-green-800"
                                >
                                    ● Active
                                </span>
                                <span v-else class="inline-flex items-center rounded-full bg-gray-100 px-3 py-1 text-xs font-medium text-gray-600">
                                    ○ Inactive
                                </span>

                                <!-- Specific student badge -->
                                <span
                                    v-if="notification.user_id"
                                    class="inline-flex items-center rounded-full bg-purple-100 px-3 py-1 text-xs font-medium text-purple-800"
                                >
                                    👤 Personal
                                </span>
                            </div>
                        </div>

                        <!-- Message -->
                        <p v-if="notification.message" class="mb-4 text-sm leading-relaxed text-gray-700">
                            {{ notification.message }}
                        </p>

                        <!-- Metadata chips row -->
                        <div class="flex flex-wrap items-center gap-2 text-xs">
                            <!-- Audience / Role -->
                            <span
                                :class="[
                                    'inline-flex items-center gap-1 rounded-full px-2.5 py-1 font-medium',
                                    getRoleColor(notification.target_role),
                                ]"
                            >
                                <Users class="h-3 w-3" />
                                {{ notification.target_role.charAt(0).toUpperCase() + notification.target_role.slice(1) }}
                            </span>

                            <!-- Type -->
                            <span v-if="notification.type" :class="['rounded-full px-2.5 py-1 font-medium', getTypeColor(notification.type)]">
                                {{ getTypeLabel(notification.type) }}
                            </span>

                            <!-- ── Term filter badge (KEY: shows admin WHO sees the notification) ── -->
                            <span
                                v-if="notification.target_term_name"
                                class="inline-flex items-center gap-1 rounded-full bg-indigo-100 px-2.5 py-1 font-medium text-indigo-800"
                            >
                                🎓 {{ notification.target_term_name }} only
                            </span>
                            <span
                                v-else-if="notification.term_ids && notification.term_ids.length > 0"
                                class="inline-flex items-center gap-1 rounded-full bg-indigo-100 px-2.5 py-1 font-medium text-indigo-800"
                            >
                                🎓 {{ notification.term_ids.length }} specific term(s)
                            </span>

                            <!-- ── Due date chip (same colour logic as student dashboard) ── -->
                            <span
                                v-if="notification.due_date"
                                :class="[
                                    'inline-flex items-center gap-1 rounded-full px-2.5 py-1 font-medium',
                                    getDueDateChipClass(notification.due_date),
                                ]"
                            >
                                <CalendarClock class="h-3 w-3" />
                                Due: {{ formatDueDate(notification.due_date) }}
                            </span>

                            <!-- Visibility window -->
                            <span class="inline-flex items-center gap-1 rounded-full bg-gray-100 px-2.5 py-1 font-medium text-gray-600">
                                <Calendar class="h-3 w-3" />
                                {{ formatAdminDate(notification.start_date) }}
                                <span v-if="notification.end_date">
                                    → {{ formatAdminDate(notification.end_date) }}
                                </span>
                                <span v-else>→ ongoing</span>
                            </span>

                            <!-- Trigger days note -->
                            <span
                                v-if="notification.trigger_days_before_due"
                                class="rounded-full bg-yellow-100 px-2.5 py-1 font-medium text-yellow-800"
                            >
                                ⏱ Shows {{ notification.trigger_days_before_due }}d before due
                            </span>

                            <!-- Created at -->
                            <span class="ml-auto text-gray-400"> Created {{ formatAdminDate(notification.created_at) }} </span>
                        </div>

                        <!-- Actions -->
                        <div class="mt-4 flex justify-end gap-2 border-t pt-4">
                            <Link :href="route('notifications.edit', notification.id)" as="button">
                                <Button variant="outline" size="sm">
                                    <Edit2 class="mr-2 h-4 w-4" />
                                    Edit
                                </Button>
                            </Link>
                            <button @click="deleteNotification(notification.id)">
                                <Button variant="outline" size="sm" class="text-red-600 hover:text-red-700">
                                    <Trash2 class="mr-2 h-4 w-4" />
                                    Delete
                                </Button>
                            </button>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </div>
    </AppLayout>
</template>