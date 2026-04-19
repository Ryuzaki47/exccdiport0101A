<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, useForm, Link } from '@inertiajs/vue3';
import {
    AlertCircle, Bell, BellOff, Calendar,
    CalendarClock, CheckCircle2, Megaphone,
} from 'lucide-vue-next';
import { computed } from 'vue';

type NotificationType = 'general' | 'payment_due' | 'payment_approved' | 'payment_rejected' | null;

type Notification = {
    id: number;
    title: string;
    message: string | null;
    type: NotificationType;
    start_date: string | null;
    end_date: string | null;
    due_date: string | null;
    payment_term_id: number | null;
    target_role: string;
    is_active: boolean;
    is_complete: boolean;
    dismissed_at: string | null;
    created_at: string;
};

const props = defineProps<{
    notifications: Notification[];
}>();

// ── Forms ───────────────────────────────────────────────────────────────────
const dismissForm = useForm({});

function dismiss(id: number) {
    dismissForm.post(route('notifications.dismiss', id));
}

// ── Formatting ───────────────────────────────────────────────────────────────
const formatDate = (date: string | null) => {
    if (!date) return '';
    // date is "YYYY-MM-DD" — append T12:00 so local parsing doesn't shift the day
    return new Date(date + 'T12:00:00').toLocaleDateString('en-PH', {
        month: 'long', day: 'numeric', year: 'numeric',
    });
};

// ── Type config ───────────────────────────────────────────────────────────────
const typeConfig: Record<string, {
    label: string;
    icon: any;
    cardClass: string;
    badgeClass: string;
    iconClass: string;
}> = {
    payment_due: {
        label: 'Payment Due',
        icon: CalendarClock,
        cardClass: 'border-amber-200 bg-amber-50',
        badgeClass: 'bg-amber-100 text-amber-800',
        iconClass: 'text-amber-600',
    },
    payment_approved: {
        label: 'Payment Approved',
        icon: CheckCircle2,
        cardClass: 'border-emerald-200 bg-emerald-50',
        badgeClass: 'bg-emerald-100 text-emerald-800',
        iconClass: 'text-emerald-600',
    },
    payment_rejected: {
        label: 'Payment Rejected',
        icon: AlertCircle,
        cardClass: 'border-red-200 bg-red-50',
        badgeClass: 'bg-red-100 text-red-800',
        iconClass: 'text-red-600',
    },
    general: {
        label: 'Announcement',
        icon: Megaphone,
        cardClass: 'border-blue-200 bg-blue-50',
        badgeClass: 'bg-blue-100 text-blue-800',
        iconClass: 'text-blue-600',
    },
};

function cfg(type: NotificationType) {
    return typeConfig[type ?? 'general'] ?? typeConfig.general;
}
</script>

<template>
    <AppLayout>
        <Head title="Notifications" />

        <div class="mx-auto max-w-3xl p-6">
            <!-- Header -->
            <div class="mb-6 flex items-center gap-3">
                <div class="rounded-xl bg-blue-100 p-3">
                    <Bell :size="24" class="text-blue-600" />
                </div>
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">Notifications</h1>
                    <p class="text-sm text-gray-500">
                        {{ notifications.length }}
                        notification{{ notifications.length !== 1 ? 's' : '' }}
                    </p>
                </div>
            </div>

            <!-- List -->
            <div v-if="notifications.length" class="space-y-3">
                <div
                    v-for="n in notifications"
                    :key="n.id"
                    :class="['rounded-xl border p-5 transition-all', cfg(n.type).cardClass]"
                >
                    <!-- Type badge + title -->
                    <div class="mb-3 flex items-start gap-3">
                        <component
                            :is="cfg(n.type).icon"
                            :size="20"
                            :class="['mt-0.5 shrink-0', cfg(n.type).iconClass]"
                        />
                        <div class="min-w-0 flex-1">
                            <span
                                :class="[
                                    'mb-1 inline-block rounded-md px-2 py-0.5 text-xs font-semibold',
                                    cfg(n.type).badgeClass,
                                ]"
                            >
                                {{ cfg(n.type).label }}
                            </span>
                            <h3 class="text-base font-semibold text-gray-900">{{ n.title }}</h3>
                        </div>
                    </div>

                    <!-- Message -->
                    <p
                        v-if="n.message"
                        class="mb-4 pl-8 text-sm leading-relaxed whitespace-pre-line text-gray-700"
                    >
                        {{ n.message }}
                    </p>

                    <!-- Footer row -->
                    <div
                        class="flex flex-wrap items-center justify-between gap-2 border-t border-black/10 pt-3 pl-8"
                    >
                        <div class="flex flex-wrap gap-4 text-xs text-gray-500">
                            <span
                                v-if="n.due_date"
                                class="flex items-center gap-1 font-semibold text-amber-700"
                            >
                                <CalendarClock :size="13" />
                                Due: {{ formatDate(n.due_date) }}
                            </span>
                            <span v-if="n.start_date" class="flex items-center gap-1">
                                <Calendar :size="13" />
                                {{ formatDate(n.start_date) }}
                                <span v-if="n.end_date"> — {{ formatDate(n.end_date) }}</span>
                            </span>
                        </div>

                        <div class="flex items-center gap-2">
                            <Link
                                v-if="n.type === 'payment_due' && n.payment_term_id"
                                :href="route('student.account')"
                                class="rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-emerald-700"
                            >
                                Pay Now
                            </Link>
                            <button
                                @click="dismiss(n.id)"
                                :disabled="dismissForm.processing"
                                class="rounded-lg border border-black/15 px-3 py-1.5 text-xs font-medium text-gray-600 transition hover:bg-white/60 disabled:opacity-50"
                            >
                                Dismiss
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Empty state -->
            <div v-else class="py-20 text-center">
                <BellOff :size="56" class="mx-auto mb-4 text-gray-300" />
                <p class="text-lg font-medium text-gray-500">You're all caught up</p>
                <p class="mt-1 text-sm text-gray-400">No active notifications at the moment.</p>
            </div>
        </div>
    </AppLayout>
</template>