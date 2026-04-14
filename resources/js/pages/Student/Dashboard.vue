<script setup lang="ts">
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import { useDataFormatting } from '@/composables/useDataFormatting';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { AlertCircle, Bell, CalendarClock, CheckCircle, Clock } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const { formatCurrency, formatDate, getTransactionStatusConfig, formatTransactionType } = useDataFormatting();

type Notification = {
    id: number;
    title: string;
    message: string;
    type: string | null;
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

type Account = { balance: number };

type RecentTransaction = {
    id: number;
    reference: string;
    type: string;
    amount: number;
    status: string;
    created_at: string;
    kind?: string;
};

type PaymentTerm = {
    id: number;
    term_name: string;
    term_order: number;
    percentage: number;
    amount: number;
    balance: number;
    due_date: string;
    status: string;
    remarks: string | null;
    paid_date: string | null;
};

type Assessment = {
    id: number;
    assessment_number: string;
    total_assessment: number;
    status: string;
    created_at: string;
};

type PaymentReminder = {
    id: number;
    type: string;
    message: string;
    outstanding_balance: number;
    status: string;
    read_at: string | null;
    sent_at: string;
    trigger_reason: string;
};

const props = defineProps<{
    account: Account;
    notifications: Notification[];
    recentTransactions: RecentTransaction[];
    paymentTerms?: PaymentTerm[];
    latestAssessment?: Assessment | null;
    paymentReminders?: PaymentReminder[];
    unreadReminderCount?: number;
    stats: {
        total_fees: number;
        total_paid: number;
        remaining_balance: number;
        pending_charges_count: number;
    };
}>();

const breadcrumbs = [{ title: 'Dashboard', href: route('dashboard') }, { title: 'Student Dashboard' }];

// ── Financial normalization ───────────────────────────────────────────────────

const normalizedStats = computed(() => {
    const safe = (v: any): number => {
        if (v == null) return 0;
        const n = Number(v);
        return isFinite(n) ? Math.max(0, n) : 0;
    };

    const totalFees = props.latestAssessment ? safe(props.latestAssessment.total_assessment) : safe(props.stats?.total_fees);

    const remainingBalance =
        props.paymentTerms && props.paymentTerms.length > 0
            ? safe(props.paymentTerms.reduce((s, t) => s + (t.balance || 0), 0))
            : safe(props.stats?.remaining_balance);

    return {
        total_fees: totalFees,
        total_paid: safe(props.stats?.total_paid),
        remaining_balance: remainingBalance,
        pending_charges_count: Math.floor(safe(props.stats?.pending_charges_count)),
    };
});

const financialDataIsConsistent = computed(() => {
    const { total_fees, total_paid, remaining_balance } = normalizedStats.value;
    if (props.paymentTerms && props.paymentTerms.length > 0) {
        const termsBalance = props.paymentTerms.reduce((s, t) => s + (t.balance || 0), 0);
        return Math.abs(remaining_balance - termsBalance) < 0.01;
    }
    return Math.abs(remaining_balance - Math.max(0, total_fees - total_paid)) < 0.01;
});

const pendingChargesInfo = computed(() => {
    const count = normalizedStats.value.pending_charges_count;
    return {
        count,
        hasWarning: count > 0,
        description: count === 0 ? 'All charges are processed' : 'Charges awaiting processing',
    };
});

const hasAwaitingApprovals = computed(() => props.recentTransactions.some((t) => t.status === 'awaiting_approval'));

// ── Payment term helpers ──────────────────────────────────────────────────────

const unpaidTerms = computed(() => (props.paymentTerms ?? []).filter((t) => t.balance > 0).sort((a, b) => a.term_order - b.term_order));

const getDueDateColor = (dueDate: string): 'red' | 'amber' | 'green' => {
    const diffDays = Math.ceil((new Date(dueDate).getTime() - Date.now()) / 86_400_000);
    if (diffDays <= 7) return 'red';
    if (diffDays <= 14) return 'amber';
    return 'green';
};

const nextPaymentDue = computed(() => {
    if (!unpaidTerms.value.length) return null;
    const term = unpaidTerms.value[0];
    const daysUntilDue = Math.ceil((new Date(term.due_date).getTime() - Date.now()) / 86_400_000);
    return {
        ...term,
        dueColor: getDueDateColor(term.due_date),
        daysUntilDue,
        formattedDueDate: formatDate(term.due_date),
        isDueOrOverdue: daysUntilDue <= 7,
    };
});

// ── Notification due-date helpers ─────────────────────────────────────────────

const getNotifDueDateColor = (dueDateStr: string | null): 'red' | 'amber' | 'green' => {
    if (!dueDateStr) return 'amber';
    return getDueDateColor(dueDateStr);
};

const dueDateLabel = (dueDateStr: string | null): string => {
    if (!dueDateStr) return '';
    const diffDays = Math.ceil((new Date(dueDateStr).getTime() - Date.now()) / 86_400_000);
    if (diffDays < 0) return `Overdue by ${Math.abs(diffDays)} day${Math.abs(diffDays) !== 1 ? 's' : ''}`;
    if (diffDays === 0) return 'Due today';
    if (diffDays === 1) return 'Due tomorrow';
    if (diffDays <= 14) return `Due in ${diffDays} days`;
    return `Due ${formatDate(dueDateStr)}`;
};

// ── Notification state ────────────────────────────────────────────────────────

const hiddenNotifications = ref<Set<number>>(new Set());

const activeNotifications = computed(() => {
    const now = Date.now();
    return props.notifications
        .filter((n) => {
            if (n.dismissed_at) return false;
            if (n.is_complete) return false;
            if (hiddenNotifications.value.has(n.id)) return false;
            if (n.start_date && new Date(n.start_date).getTime() > now) return false;
            if (n.end_date && new Date(n.end_date).getTime() < now) return false;
            return true;
        })
        .sort((a, b) => {
            if (a.type === 'payment_due' && b.type !== 'payment_due') return -1;
            if (a.type !== 'payment_due' && b.type === 'payment_due') return 1;
            if (a.due_date && b.due_date) {
                return new Date(a.due_date).getTime() - new Date(b.due_date).getTime();
            }
            return new Date(b.created_at).getTime() - new Date(a.created_at).getTime();
        });
});

const showAllNotifications = ref(false);
const visibleNotifications = computed(() => (showAllNotifications.value ? activeNotifications.value : activeNotifications.value.slice(0, 3)));
const hasMoreNotifications = computed(() => activeNotifications.value.length > 3);

const dismissNotification = (id: number) => {
    hiddenNotifications.value.add(id);
    const form = useForm({});
    form.post(route('notifications.dismiss', id), {
        preserveScroll: true,
        preserveState: true,
    });
};

// ── Payment Reminder actions ──────────────────────────────────────────────────

// Optimistically hide a dismissed reminder immediately, then sync to server.
const hiddenReminders = ref<Set<number>>(new Set());

const visibleReminders = computed(() => (props.paymentReminders ?? []).filter((r) => !hiddenReminders.value.has(r.id)));

const markReminderRead = (id: number) => {
    const form = useForm({});
    form.post(route('reminders.read', id), {
        preserveScroll: true,
        preserveState: true,
    });
};

const dismissReminder = (id: number) => {
    hiddenReminders.value.add(id);
    const form = useForm({});
    form.post(route('reminders.dismiss', id), {
        preserveScroll: true,
        preserveState: true,
    });
};
</script>

<template>
    <AppLayout>
        <Head title="Student Dashboard" />

        <div class="w-full space-y-5 p-6">
            <Breadcrumbs :items="breadcrumbs" />

            <!-- Welcome Banner -->
            <div class="relative overflow-hidden rounded-2xl p-6 text-white shadow-md" style="background: linear-gradient(135deg, hsl(220 85% 18%) 0%, hsl(215 80% 28%) 60%, hsl(210 75% 35%) 100%);">
                <div class="relative z-10 flex items-start justify-between gap-4">
                    <div>
                        <p class="mb-1 text-sm font-medium text-blue-200">Student Portal</p>
                        <h1 class="text-2xl font-bold text-white">Welcome back, Student!</h1>
                        <p class="mt-1 text-sm text-blue-100/80">Here's your financial overview and important updates</p>
                    </div>
                    <div class="flex-shrink-0 text-right">
                        <p class="text-xs text-blue-200">Remaining Balance</p>
                        <p class="text-3xl font-extrabold text-white">{{ formatCurrency(normalizedStats.remaining_balance) }}</p>
                    </div>
                </div>
                <!-- Decorative circles -->
                <div class="pointer-events-none absolute -top-8 -right-8 h-40 w-40 rounded-full opacity-10" style="background: radial-gradient(circle, #fff 0%, transparent 70%);" />
                <div class="pointer-events-none absolute -bottom-10 -left-4 h-32 w-32 rounded-full opacity-10" style="background: radial-gradient(circle, #60a5fa 0%, transparent 70%);" />
            </div>

            <!-- Awaiting Approval Banner -->
            <div v-if="hasAwaitingApprovals" class="flex items-center gap-3 rounded-xl border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-800">
                <span class="h-2 w-2 animate-pulse rounded-full bg-blue-500 flex-shrink-0"></span>
                <p><strong>Checking for updates…</strong> Your payment is awaiting verification. This page will update automatically.</p>
            </div>

            <!-- Stats Grid -->
            <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                <!-- Total Assessment -->
                <div class="ccdi-stat-card">
                    <div class="min-w-0">
                        <p class="truncate text-xs font-semibold uppercase tracking-wide text-muted-foreground">Total Assessment</p>
                        <p class="text-xl font-bold text-foreground">{{ formatCurrency(normalizedStats.total_fees) }}</p>
                        <p class="text-xs text-muted-foreground">Current semester</p>
                    </div>
                </div>
                <!-- Total Paid -->
                <div class="ccdi-stat-card">
                    <div class="min-w-0">
                        <p class="truncate text-xs font-semibold uppercase tracking-wide text-muted-foreground">Total Paid</p>
                        <p class="text-xl font-bold text-emerald-600">{{ formatCurrency(normalizedStats.total_paid) }}</p>
                        <p class="text-xs text-muted-foreground">All verified payments</p>
                    </div>
                </div>
                <!-- Remaining -->
                <div class="ccdi-stat-card">
                    <div class="min-w-0">
                        <p class="truncate text-xs font-semibold uppercase tracking-wide text-muted-foreground">Remaining</p>
                        <p class="text-xl font-bold" :class="normalizedStats.remaining_balance > 0 ? 'text-red-600' : 'text-emerald-600'">
                            {{ formatCurrency(normalizedStats.remaining_balance) }}
                        </p>
                        <p class="text-xs text-muted-foreground">Outstanding balance</p>
                    </div>
                </div>
                <!-- Pending Charges -->
                <div class="ccdi-stat-card">
                    <div class="min-w-0">
                        <p class="truncate text-xs font-semibold uppercase tracking-wide text-muted-foreground">Pending Terms</p>
                        <p class="text-xl font-bold" :class="pendingChargesInfo.hasWarning ? 'text-amber-600' : 'text-foreground'">
                            {{ pendingChargesInfo.count }}
                        </p>
                        <p class="text-xs text-muted-foreground">{{ pendingChargesInfo.count === 0 ? 'All settled' : 'Awaiting payment' }}</p>
                    </div>
                </div>
            </div>

            <!-- Main content grid -->
            <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
                <!-- Left column (2/3) -->
                <div class="space-y-5 lg:col-span-2">

                    <!-- Payment Reminders -->
                    <div v-if="visibleReminders.length > 0" class="ccdi-card p-5">
                        <div class="mb-4 flex items-center gap-2">
                            <h2 class="text-base font-semibold text-foreground">Payment Reminders</h2>
                            <span v-if="props.unreadReminderCount && props.unreadReminderCount > 0" class="ccdi-badge-red">{{ props.unreadReminderCount }} new</span>
                        </div>
                        <div class="space-y-2.5">
                            <div
                                v-for="reminder in visibleReminders" :key="reminder.id"
                                class="flex items-start justify-between gap-3 rounded-xl border p-3.5"
                                :class="reminder.type === 'overdue' || reminder.type === 'approaching_due' ? 'border-red-200 bg-red-50' : reminder.type === 'partial_payment' ? 'border-amber-200 bg-amber-50' : 'border-blue-200 bg-blue-50'"
                            >
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-medium" :class="reminder.type === 'overdue' || reminder.type === 'approaching_due' ? 'text-red-900' : reminder.type === 'partial_payment' ? 'text-amber-900' : 'text-blue-900'">
                                        {{ reminder.message }}
                                    </p>
                                    <p class="mt-0.5 text-xs text-muted-foreground">{{ formatDate(reminder.sent_at) }}</p>
                                </div>
                                <div class="flex flex-shrink-0 items-center gap-1.5">
                                    <span v-if="reminder.status !== 'read'" class="ccdi-badge-red">Unread</span>
                                    <button v-if="reminder.status !== 'read'" @click="markReminderRead(reminder.id)" class="ccdi-badge-blue cursor-pointer hover:opacity-80">✓ Mark Read</button>
                                    <span v-else class="ccdi-badge-gray">Read</span>
                                    <button @click="dismissReminder(reminder.id)" class="rounded-lg p-1 text-muted-foreground hover:bg-muted hover:text-foreground transition-colors">✕</button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Recent Transactions -->
                    <div class="ccdi-card">
                        <div class="flex items-center justify-between border-b border-border px-5 py-4">
                            <h2 class="text-base font-semibold text-foreground">Recent Transactions</h2>
                            <Link :href="route('transactions.index')" class="text-sm font-medium text-blue-600 hover:text-blue-700 hover:underline">View All →</Link>
                        </div>
                        <div v-if="!recentTransactions.length" class="flex flex-col items-center justify-center py-12 text-center">
                            <p class="text-sm font-medium text-muted-foreground">No transactions yet</p>
                            <p class="mt-1 text-xs text-muted-foreground">Payments you make will appear here</p>
                        </div>
                        <div v-else class="divide-y divide-border">
                            <div v-for="transaction in recentTransactions" :key="transaction.id" class="flex items-center justify-between px-5 py-3.5 hover:bg-muted/50 transition-colors">
                                <div class="flex-1">
                                    <p class="text-sm font-medium text-foreground">{{ formatTransactionType(transaction.type) }}</p>
                                    <p class="text-xs text-muted-foreground">{{ transaction.reference || 'N/A' }} · {{ transaction.created_at ? formatDate(transaction.created_at) : '-' }}</p>
                                </div>
                                <div class="text-right">
                                    <p class="text-sm font-semibold text-foreground">{{ formatCurrency(transaction.amount) }}</p>
                                    <span class="inline-block rounded-md px-2 py-0.5 text-xs font-medium" :class="{ ...getTransactionStatusConfig(transaction.status) }">
                                        {{ getTransactionStatusConfig(transaction.status).label }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right column (1/3) -->
                <div class="space-y-4">
                    <!-- Quick Actions -->
                    <div class="ccdi-card p-5">
                        <h2 class="mb-3.5 text-base font-semibold text-foreground">Quick Actions</h2>
                        <div class="space-y-2.5">
                            <Link :href="route('student.account')" class="block w-full rounded-xl border border-border bg-card px-4 py-2.5 text-center text-sm font-medium text-foreground transition-all hover:border-blue-300 hover:bg-blue-50 hover:text-blue-700">
                                View Account
                            </Link>
                            <Link :href="route('student.account', { tab: 'payment' })" class="block w-full rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-2.5 text-center text-sm font-medium text-emerald-800 transition-all hover:bg-emerald-100">
                                Make Payment
                            </Link>
                            <Link :href="route('transactions.index')" class="block w-full rounded-xl border border-border bg-card px-4 py-2.5 text-center text-sm font-medium text-foreground transition-all hover:border-purple-300 hover:bg-purple-50 hover:text-purple-700">
                                Transaction History
                            </Link>
                        </div>
                    </div>

                    <!-- Next Payment Due -->
                    <div v-if="nextPaymentDue" class="ccdi-card overflow-hidden">
                        <div class="px-5 py-3 border-b border-border">
                            <h2 class="text-base font-semibold text-foreground">Next Payment Due</h2>
                        </div>
                        <div class="p-5">
                            <div class="mb-4 rounded-xl border p-4" :class="nextPaymentDue.dueColor === 'red' ? 'border-red-200 bg-red-50' : nextPaymentDue.dueColor === 'amber' ? 'border-amber-200 bg-amber-50' : 'border-emerald-200 bg-emerald-50'">
                                <div class="flex items-start justify-between gap-2">
                                    <div>
                                        <p class="text-xs font-medium mb-0.5" :class="nextPaymentDue.dueColor === 'red' ? 'text-red-700' : nextPaymentDue.dueColor === 'amber' ? 'text-amber-700' : 'text-emerald-700'">{{ nextPaymentDue.term_name }}</p>
                                        <p class="text-2xl font-extrabold" :class="nextPaymentDue.dueColor === 'red' ? 'text-red-700' : nextPaymentDue.dueColor === 'amber' ? 'text-amber-700' : 'text-emerald-700'">{{ formatCurrency(nextPaymentDue.balance) }}</p>
                                    </div>
                                    <div class="rounded-lg p-2 flex-shrink-0" :class="nextPaymentDue.dueColor === 'red' ? 'bg-red-200' : nextPaymentDue.dueColor === 'amber' ? 'bg-amber-200' : 'bg-emerald-200'">
                                        <AlertCircle v-if="nextPaymentDue.dueColor === 'red'" :size="18" :class="nextPaymentDue.dueColor === 'red' ? 'text-red-700' : ''" />
                                        <Clock v-else-if="nextPaymentDue.dueColor === 'amber'" :size="18" class="text-amber-700" />
                                        <CheckCircle v-else :size="18" class="text-emerald-700" />
                                    </div>
                                </div>
                                <div class="mt-3 flex items-center justify-between border-t pt-3" :class="nextPaymentDue.dueColor === 'red' ? 'border-red-200' : nextPaymentDue.dueColor === 'amber' ? 'border-amber-200' : 'border-emerald-200'">
                                    <div>
                                        <p class="text-xs text-muted-foreground">Due date</p>
                                        <p class="text-sm font-semibold" :class="nextPaymentDue.dueColor === 'red' ? 'text-red-700' : nextPaymentDue.dueColor === 'amber' ? 'text-amber-700' : 'text-foreground'">{{ nextPaymentDue.formattedDueDate }}</p>
                                    </div>
                                    <span v-if="nextPaymentDue.daysUntilDue >= 0" class="rounded-lg px-2.5 py-1 text-xs font-semibold" :class="nextPaymentDue.dueColor === 'red' ? 'bg-red-200 text-red-800' : nextPaymentDue.dueColor === 'amber' ? 'bg-amber-200 text-amber-800' : 'bg-emerald-200 text-emerald-800'">
                                        {{ nextPaymentDue.daysUntilDue }} day{{ nextPaymentDue.daysUntilDue !== 1 ? 's' : '' }} left
                                    </span>
                                    <span v-else class="rounded-lg bg-red-200 px-2.5 py-1 text-xs font-semibold text-red-800">
                                        {{ Math.abs(nextPaymentDue.daysUntilDue) }} day{{ Math.abs(nextPaymentDue.daysUntilDue) !== 1 ? 's' : '' }} overdue
                                    </span>
                                </div>
                            </div>
                            <div class="flex gap-2">
                                <Link :href="route('student.account')" class="flex-1 rounded-xl border border-border bg-card py-2 text-center text-sm font-medium text-foreground transition-all hover:bg-muted">View Details</Link>
                                <Link :href="route('student.account', { tab: 'payment', term_id: nextPaymentDue.id })" class="flex-1 rounded-xl py-2 text-center text-sm font-semibold text-white transition-all hover:opacity-90" style="background: linear-gradient(135deg, #16a34a, #15803d);">Pay Now</Link>
                            </div>
                        </div>
                    </div>

                    <!-- All paid state -->
                    <div v-if="normalizedStats.remaining_balance === 0" class="ccdi-card overflow-hidden">
                        <div class="flex flex-col items-center gap-2 bg-emerald-50 p-6 text-center">
                            <div class="flex h-12 w-12 items-center justify-center rounded-full bg-emerald-200">
                                <CheckCircle :size="22" class="text-emerald-700" />
                            </div>
                            <p class="font-semibold text-emerald-900">Account in Good Standing</p>
                            <p class="text-xs text-emerald-700">All payments are current. No action required.</p>
                        </div>
                    </div>

                    <!-- Data integrity warning -->
                    <div v-if="!financialDataIsConsistent" class="rounded-xl border border-amber-300 bg-amber-50 p-4">
                        <p class="text-xs text-amber-800"><strong>⚠ Note:</strong> There is a discrepancy in your financial data. Please contact the accounting office if this persists.</p>
                    </div>

                    <!-- Notifications -->
                    <div v-if="activeNotifications.length" class="space-y-3">
                        <div class="flex items-center gap-2 px-1">
                            <Bell class="h-4 w-4 text-blue-600" />
                            <h2 class="text-sm font-semibold text-foreground">Important Updates</h2>
                        </div>
                        <div class="space-y-2.5">
                            <div
                                v-for="notification in visibleNotifications" :key="notification.id"
                                class="ccdi-card p-4 transition-all hover:shadow-md"
                                :class="notification.type === 'payment_due' ? 'border-l-4 border-l-amber-500' : 'border-l-4 border-l-blue-500'"
                            >
                                <div class="mb-2 flex items-start justify-between gap-2">
                                    <h3 class="flex-1 text-sm font-semibold text-foreground">{{ notification.title }}</h3>
                                    <button @click="dismissNotification(notification.id)" class="flex-shrink-0 rounded-lg p-1 text-muted-foreground hover:bg-muted hover:text-foreground transition-colors" title="Dismiss">✕</button>
                                </div>
                                <div v-if="notification.type === 'payment_due' && notification.due_date" class="mb-2">
                                    <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold" :class="getNotifDueDateColor(notification.due_date) === 'red' ? 'bg-red-100 text-red-700 ring-1 ring-red-200' : getNotifDueDateColor(notification.due_date) === 'amber' ? 'bg-amber-100 text-amber-700 ring-1 ring-amber-200' : 'bg-emerald-100 text-emerald-700 ring-1 ring-emerald-200'">
                                        <CalendarClock :size="11" />
                                        {{ dueDateLabel(notification.due_date) }} · {{ formatDate(notification.due_date) }}
                                    </span>
                                </div>
                                <p class="text-xs leading-relaxed text-muted-foreground">{{ notification.message }}</p>
                                <div class="mt-3 flex items-center justify-between gap-2 border-t border-border pt-3">
                                    <div class="space-y-0.5 text-xs text-muted-foreground">
                                        <p v-if="notification.start_date">From: {{ formatDate(notification.start_date) }}</p>
                                        <p v-if="notification.end_date">Until: {{ formatDate(notification.end_date) }}</p>
                                    </div>
                                    <Link v-if="notification.type === 'payment_due' && notification.payment_term_id" :href="route('student.account', { tab: 'payment', term_id: notification.payment_term_id })" class="flex-shrink-0 rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white transition-all hover:bg-emerald-700">Pay Now</Link>
                                </div>
                            </div>
                        </div>
                        <div v-if="hasMoreNotifications" class="mt-2">
                            <button @click="showAllNotifications = !showAllNotifications" class="w-full rounded-xl border border-border bg-card py-2.5 text-sm font-medium text-foreground transition-all hover:bg-muted">
                                {{ showAllNotifications ? 'Show Less' : `View More (${activeNotifications.length - 3} more)` }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
