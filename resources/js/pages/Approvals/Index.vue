<script setup lang="ts">
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import { useDataFormatting } from '@/composables/useDataFormatting';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

interface WorkflowMeta {
    transaction_id: number;
    amount: number;
    payment_method: string;
    term_name: string;
    year?: number | string;
    semester?: string;
    student_user_id: number;
    submitted_at: string;
}

interface Approval {
    id: number;
    step_name: string;
    status: 'pending' | 'approved' | 'rejected';
    comments: string | null;
    created_at: string;
    workflow_instance: {
        metadata: WorkflowMeta;
        workflow: { name: string };
        workflowable: {
            reference: string;
            amount: number;
            payment_channel?: string;
            meta?: { term_name?: string; description?: string };
            type?: string;
            user?: { first_name: string; last_name: string; account_id: string };
        };
    };
}

const props = defineProps<{
    approvals: { data: Approval[]; links: any[] };
    filters: { status?: string; year?: string; semester?: string };
}>();

const breadcrumbs = [
    { title: 'Dashboard', href: route('accounting.dashboard') },
    { title: 'Payment Approvals', href: route('approvals.index') },
];

const { formatCurrency } = useDataFormatting();

const filters = ref({
    status: props.filters.status ?? '',
    year: props.filters.year ?? '',
    semester: props.filters.semester ?? '',
});

const searchQuery = ref('');
const filterStatus = ref<string>('all');
const showRejectDialog = ref(false);
const selectedApprovalId = ref<number | null>(null);

const rejectForm = useForm({ comments: '' });
const approveForm = useForm({});

// Helper accessors
const getStudentName = (a: Approval) => {
    const u = a.workflow_instance?.workflowable?.user;
    return u ? `${u.last_name}, ${u.first_name}` : 'Unknown Student';
};
const getAccountId = (a: Approval) => a.workflow_instance?.workflowable?.user?.account_id ?? '—';
const getReference = (a: Approval) => a.workflow_instance?.workflowable?.reference ?? '—';
const getAmount = (a: Approval) => a.workflow_instance?.workflowable?.amount ?? a.workflow_instance?.metadata?.amount ?? 0;
const getMethod = (a: Approval) => {
    const m = a.workflow_instance?.workflowable?.payment_channel ?? a.workflow_instance?.metadata?.payment_method ?? '';
    return ({ cash: 'Cash', gcash: 'GCash', bank_transfer: 'Bank Transfer', credit_card: 'Credit Card', debit_card: 'Debit Card' })[m] ?? m;
};
const getTermName = (a: Approval) =>
    a.workflow_instance?.workflowable?.meta?.term_name ??
    a.workflow_instance?.metadata?.term_name ??
    a.workflow_instance?.workflowable?.type ?? '—';

const formatDate = (d: string) =>
    new Date(d).toLocaleString('en-PH', { dateStyle: 'medium', timeStyle: 'short' });

const capitalize = (str: string) => str.charAt(0).toUpperCase() + str.slice(1);

// Counts
const pendingCount = computed(() => props.approvals.data.filter((a) => a.status === 'pending').length);
const approvedCount = computed(() => props.approvals.data.filter((a) => a.status === 'approved').length);
const rejectedCount = computed(() => props.approvals.data.filter((a) => a.status === 'rejected').length);

// Filtered list — client-side on top of server status filter
const filteredApprovals = computed(() => {
    let list = props.approvals.data;

    if (filterStatus.value !== 'all') {
        const map: Record<string, string> = { pending: 'pending', approved: 'approved', rejected: 'rejected' };
        const target = map[filterStatus.value];
        if (target) list = list.filter((a) => a.status === target);
    }

    if (filters.value.year) {
        list = list.filter((a) => String(a.workflow_instance?.metadata?.year) === filters.value.year);
    }

    if (searchQuery.value.trim()) {
        const q = searchQuery.value.toLowerCase();
        list = list.filter((a) => {
            return (
                getStudentName(a).toLowerCase().includes(q) ||
                getReference(a).toLowerCase().includes(q) ||
                getAccountId(a).toLowerCase().includes(q)
            );
        });
    }

    return list;
});

const uniqueYears = computed(() => {
    const years = new Set<string>();
    props.approvals.data.forEach((a) => {
        const y = a.workflow_instance?.metadata?.year;
        if (y) years.add(String(y));
    });
    return Array.from(years).sort((a, b) => parseInt(b) - parseInt(a));
});

const applyServerFilter = () => {
    const params: Record<string, string> = {};
    if (filters.value.status) params.status = filters.value.status;
    if (filters.value.year) params.year = filters.value.year;
    router.get(route('approvals.index'), params, { preserveState: true, replace: true });
};

const approve = (id: number) => {
    approveForm.post(route('approvals.approve', id), { preserveScroll: true });
};

const openRejectDialog = (id: number) => {
    selectedApprovalId.value = id;
    rejectForm.reset();
    showRejectDialog.value = true;
};

const submitRejection = () => {
    if (!selectedApprovalId.value) return;
    rejectForm.post(route('approvals.reject', selectedApprovalId.value), {
        onSuccess: () => { showRejectDialog.value = false; },
    });
};
</script>

<template>
    <AppLayout>
        <Head title="Payment Approvals" />

        <div class="w-full space-y-5 p-6">
            <Breadcrumbs :items="breadcrumbs" />

            <!-- Page Header -->
            <div class="ccdi-page-header">
                <div>
                    <h1 class="ccdi-section-title">Payment Approvals</h1>
                    <p class="ccdi-section-desc">Review and approve student payment submissions</p>
                </div>
                <button @click="router.reload()" class="ccdi-btn-secondary text-sm">
                    Refresh
                </button>
            </div>

            <!-- Stats -->
            <div class="grid grid-cols-3 gap-4">
                <div class="ccdi-stat-card">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Pending</p>
                        <p class="text-xl font-bold text-amber-600">{{ pendingCount }}</p>
                        <p class="text-xs text-muted-foreground">Awaiting review</p>
                    </div>
                </div>
                <div class="ccdi-stat-card">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Approved</p>
                        <p class="text-xl font-bold text-emerald-600">{{ approvedCount }}</p>
                        <p class="text-xs text-muted-foreground">This page</p>
                    </div>
                </div>
                <div class="ccdi-stat-card">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Rejected</p>
                        <p class="text-xl font-bold text-red-600">{{ rejectedCount }}</p>
                        <p class="text-xs text-muted-foreground">This page</p>
                    </div>
                </div>
            </div>

            <!-- Filter row -->
            <div class="flex flex-wrap items-center gap-3">
                <!-- Status pills -->
                <div class="flex gap-1 rounded-xl border border-border bg-muted/30 p-1">
                    <button
                        v-for="f in ['all', 'pending', 'approved', 'rejected']"
                        :key="f"
                        @click="filterStatus = f"
                        class="rounded-lg px-4 py-1.5 text-sm font-medium capitalize transition-all"
                        :class="filterStatus === f ? 'bg-card text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground'"
                    >
                        {{ f }}
                        <span v-if="f === 'pending' && pendingCount > 0" class="ml-1.5 inline-flex h-4 w-4 items-center justify-center rounded-full bg-amber-500 text-xs font-bold text-white">{{ pendingCount }}</span>
                    </button>
                </div>

                <!-- Year filter -->
                <select v-model="filters.year" @change="applyServerFilter"
                        class="rounded-xl border border-border bg-card px-3 py-2 text-sm text-foreground shadow-sm focus:outline-none focus:ring-2 focus:ring-ring">
                    <option value="">All Years</option>
                    <option v-for="y in uniqueYears" :key="y" :value="y">{{ y }}</option>
                </select>

                <!-- Search -->
                <div class="relative ml-auto">
                    <svg class="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>
                    <input v-model="searchQuery" type="text" placeholder="Search student, reference…"
                           class="w-64 rounded-xl border border-border bg-card py-2 pl-9 pr-4 text-sm focus:outline-none focus:ring-2 focus:ring-ring" />
                </div>
            </div>

            <!-- Table -->
            <div class="ccdi-card overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-border">
                        <thead class="bg-muted/40">
                            <tr>
                                <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground">Reference</th>
                                <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground">Student</th>
                                <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground">Payment Term</th>
                                <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground">Method</th>
                                <th class="px-5 py-3 text-right text-xs font-semibold uppercase tracking-wider text-muted-foreground">Amount</th>
                                <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground">Submitted</th>
                                <th class="px-5 py-3 text-center text-xs font-semibold uppercase tracking-wider text-muted-foreground">Status</th>
                                <th class="px-5 py-3 text-right text-xs font-semibold uppercase tracking-wider text-muted-foreground">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border bg-card">
                            <tr v-for="approval in filteredApprovals" :key="approval.id"
                                class="transition-colors hover:bg-muted/30"
                                :class="approval.status === 'pending' ? 'bg-amber-50/20' : ''">
                                <td class="px-5 py-3.5">
                                    <span class="font-mono text-xs text-blue-600">{{ getReference(approval) }}</span>
                                </td>
                                <td class="px-5 py-3.5">
                                    <div class="flex items-center gap-2.5">
                                        <div class="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-full bg-blue-100 text-xs font-bold text-blue-700">
                                            {{ getStudentName(approval).charAt(0) }}
                                        </div>
                                        <div>
                                            <p class="text-sm font-medium text-foreground">{{ getStudentName(approval) }}</p>
                                            <p class="font-mono text-xs text-muted-foreground">{{ getAccountId(approval) }}</p>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-5 py-3.5 text-sm text-foreground">{{ getTermName(approval) }}</td>
                                <td class="px-5 py-3.5">
                                    <span class="ccdi-badge-blue">{{ getMethod(approval) || 'N/A' }}</span>
                                </td>
                                <td class="px-5 py-3.5 text-right">
                                    <span class="text-sm font-bold text-emerald-600">{{ formatCurrency(getAmount(approval)) }}</span>
                                </td>
                                <td class="px-5 py-3.5 text-sm text-muted-foreground">{{ formatDate(approval.created_at) }}</td>
                                <td class="px-5 py-3.5 text-center">
                                    <span :class="approval.status === 'pending' ? 'ccdi-badge-yellow' : approval.status === 'approved' ? 'ccdi-badge-green' : 'ccdi-badge-red'">
                                        {{ capitalize(approval.status) }}
                                    </span>
                                </td>
                                <td class="px-5 py-3.5 text-right">
                                    <div class="flex items-center justify-end gap-1.5">
                                        <Link :href="route('approvals.show', approval.id)"
                                              class="rounded-xl border border-border bg-card p-1.5 text-muted-foreground transition-all hover:border-blue-300 hover:bg-blue-50 hover:text-blue-700"
                                              title="View details">
                                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" /></svg>
                                        </Link>
                                        <template v-if="approval.status === 'pending'">
                                            <button @click="approve(approval.id)"
                                                    :disabled="approveForm.processing"
                                                    class="rounded-xl border border-emerald-300 bg-emerald-50 p-1.5 text-emerald-700 transition-all hover:bg-emerald-100 disabled:opacity-50"
                                                    title="Approve">
                                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" /></svg>
                                            </button>
                                            <button @click="openRejectDialog(approval.id)"
                                                    class="rounded-xl border border-red-300 bg-red-50 p-1.5 text-red-700 transition-all hover:bg-red-100"
                                                    title="Reject">
                                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                                            </button>
                                        </template>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Empty state -->
                <div v-if="!filteredApprovals.length" class="flex flex-col items-center justify-center py-16 text-center">
                    <div class="mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-muted">
                        <svg class="h-6 w-6 text-muted-foreground" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                    </div>
                    <p class="text-base font-semibold text-foreground">No {{ filterStatus === 'all' ? '' : filterStatus }} approvals</p>
                    <p class="mt-1 text-sm text-muted-foreground">{{ filterStatus === 'pending' ? 'All payments have been reviewed.' : 'Nothing to show here.' }}</p>
                </div>
            </div>

            <!-- Pagination -->
            <div v-if="approvals.links?.length > 3" class="flex justify-center gap-1">
                <template v-for="link in approvals.links" :key="link.label">
                    <Link
                        v-if="link.url"
                        :href="link.url"
                        class="rounded-xl border border-border px-3 py-1.5 text-sm transition-all hover:bg-muted"
                        :class="link.active ? 'bg-primary text-primary-foreground border-primary' : 'bg-card text-foreground'"
                        v-html="link.label"
                    />
                    <span v-else class="rounded-xl border border-border bg-muted/30 px-3 py-1.5 text-sm text-muted-foreground" v-html="link.label" />
                </template>
            </div>
        </div>

        <!-- Reject Dialog -->
        <div v-if="showRejectDialog" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
            <div class="w-full max-w-md rounded-2xl bg-card p-6 shadow-xl">
                <h2 class="mb-1 text-lg font-semibold text-foreground">Reject Payment</h2>
                <p class="mb-4 text-sm text-muted-foreground">Please provide a reason for rejecting this payment submission.</p>
                <textarea
                    v-model="rejectForm.comments"
                    rows="3"
                    placeholder="Rejection reason (required)..."
                    class="w-full rounded-xl border border-border bg-background p-3 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                ></textarea>
                <p v-if="rejectForm.errors.comments" class="mt-1 text-xs text-red-600">{{ rejectForm.errors.comments }}</p>
                <div class="mt-4 flex justify-end gap-3">
                    <button @click="showRejectDialog = false" class="ccdi-btn-secondary">Cancel</button>
                    <button @click="submitRejection" :disabled="rejectForm.processing || !rejectForm.comments.trim()"
                            class="inline-flex items-center gap-2 rounded-xl bg-red-600 px-4 py-2.5 text-sm font-semibold text-white transition-all hover:bg-red-700 disabled:opacity-60">
                        <span v-if="rejectForm.processing" class="h-4 w-4 animate-spin rounded-full border-2 border-white/30 border-t-white"></span>
                        {{ rejectForm.processing ? 'Rejecting…' : 'Reject Payment' }}
                    </button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
