<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import { useDataFormatting } from '@/composables/useDataFormatting';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { AlertCircle, CheckCircle, Clock } from 'lucide-vue-next';

const { formatCurrency, formatDate } = useDataFormatting();

// ── Types ─────────────────────────────────────────────────────────────────────

type PaymentTerm = {
    id: number;
    term_name: string;
    term_order: number;
    percentage: number;
    amount: number;
    balance: number;
    due_date: string | null;
    status: string;
    remarks: string | null;
};

type Assessment = {
    id: number;
    assessment_number: string;
    year_level: string;
    semester: string;
    school_year: string;
    total_assessment: number;
    status: string;
};

type PendingPayment = {
    id: number;
    reference: string;
    amount: number;
    selected_term_id: number | null;
    term_name: string;
    created_at: string;
};

// ── Props ─────────────────────────────────────────────────────────────────────

const props = withDefaults(
    defineProps<{
        assessment: Assessment | null;
        paymentTerms: PaymentTerm[];
        pendingApprovalPayments: PendingPayment[];
        preselectedTermId?: number | null;
        availablePaymentMethods?: string[];
        student: {
            id: number;
            name: string;
            account_id: string;
            course: string;
            year_level: string;
        };
    }>(),
    {
        paymentTerms: () => [],
        pendingApprovalPayments: () => [],
        preselectedTermId: null,
        availablePaymentMethods: () => ['gcash', 'bank_transfer', 'credit_card', 'debit_card'],
    },
);

// ── Breadcrumbs ───────────────────────────────────────────────────────────────

const breadcrumbs = [
    { title: 'My Account', href: route('student.account') },
    { title: 'Make Payment' },
];

// ── Payment methods ───────────────────────────────────────────────────────────

const allPaymentMethods = [
    { value: 'gcash',         label: 'GCash' },
    { value: 'bank_transfer', label: 'Bank Transfer' },
    { value: 'credit_card',   label: 'Credit Card' },
    { value: 'debit_card',    label: 'Debit Card' },
];

const availablePaymentMethods = computed(() =>
    allPaymentMethods.filter((m) => props.availablePaymentMethods.includes(m.value)),
);

// ── Pending payments indexed by term ─────────────────────────────────────────

const pendingByTerm = computed<Record<number, number>>(() => {
    const map: Record<number, number> = {};
    props.pendingApprovalPayments.forEach((p) => {
        if (p.selected_term_id !== null) {
            map[p.selected_term_id] = (map[p.selected_term_id] || 0) + p.amount;
        }
    });
    return map;
});

// ── Available terms ───────────────────────────────────────────────────────────

const availableTerms = computed(() => {
    const unpaid = props.paymentTerms
        .filter((t) => t.balance > 0)
        .sort((a, b) => a.term_order - b.term_order);

    return unpaid.map((term, index) => {
        const pendingAmount = pendingByTerm.value[term.id] ?? 0;
        return {
            ...term,
            isSelectable: index === 0 && pendingAmount === 0,
            hasPending: pendingAmount > 0,
            pendingAmount,
        };
    });
});

const totalOutstandingBalance = computed(() =>
    props.paymentTerms.reduce((sum, t) => sum + Number(t.balance), 0),
);

const effectiveBalance = computed(() => {
    const totalPending = props.pendingApprovalPayments.reduce((s, p) => s + p.amount, 0);
    return Math.max(0, totalOutstandingBalance.value - totalPending);
});

// ── Form ──────────────────────────────────────────────────────────────────────

const form = useForm({
    amount: 0 as number,
    payment_method: computed(() => availablePaymentMethods.value[0]?.value ?? 'gcash').value,
    paid_at: new Date().toISOString().split('T')[0],
    selected_term_id: props.preselectedTermId ?? (null as number | null),
    description: '' as string,
});

watch(() => form.selected_term_id, (termId) => {
    if (!termId) return;
    const term = availableTerms.value.find((t) => t.id === termId);
    if (term) form.amount = term.balance;
});

if (props.preselectedTermId) {
    form.selected_term_id = props.preselectedTermId;
}

const selectedTerm = computed(() =>
    availableTerms.value.find((t) => t.id === form.selected_term_id) ?? null,
);

// ── Bank transfer specific state ──────────────────────────────────────────────

const isBankTransfer = computed(() => form.payment_method === 'bank_transfer');
const bankReferenceNumber = ref('');
const bankDetails = ref<{ account_name: string; account_number: string; bank_name: string } | null>(null);
const bankDetailsLoading = ref(false);

// Load bank details when bank transfer is selected
watch(isBankTransfer, async (val) => {
    if (!val || bankDetails.value) return;
    bankDetailsLoading.value = true;
    try {
        const res = await fetch(route('payment.bank-details'), {
            credentials: 'same-origin',
        });
        if (res.ok) {
            const data = await res.json();
            bankDetails.value = data.bank_details;
        }
    } catch {
        // Non-fatal — bank details are informational only
    } finally {
        bankDetailsLoading.value = false;
    }
}, { immediate: false });

// ── Validation ────────────────────────────────────────────────────────────────

const validationError = computed<string | null>(() => {
    if (!props.assessment) return 'No active assessment found. Please contact accounting.';
    if (totalOutstandingBalance.value <= 0) return 'Your account has no outstanding balance.';
    if (effectiveBalance.value <= 0) return 'Your full outstanding balance is awaiting approval.';
    if (!form.selected_term_id) return 'Please select a payment term.';
    if (selectedTerm.value?.hasPending) {
        return `A payment for ${selectedTerm.value.term_name} is already awaiting approval.`;
    }
    if (!form.amount || form.amount <= 0) return 'Please enter a valid payment amount.';
    if (form.amount > effectiveBalance.value) return 'Amount exceeds your available balance.';
    if (isBankTransfer.value && !bankReferenceNumber.value.trim()) {
        return 'Please enter your bank transfer reference number.';
    }
    return null;
});

// ── State ─────────────────────────────────────────────────────────────────────

const isCheckingOut = ref(false);
const checkoutError = ref<string | null>(null);
const submitSuccess = ref(false);

const canSubmit = computed(() =>
    !validationError.value && !form.processing && !isCheckingOut.value,
);

// ── Submission ────────────────────────────────────────────────────────────────

const submit = () => {
    if (!canSubmit.value) return;
    checkoutError.value = null;

    if (isBankTransfer.value) {
        submitBankTransfer();
    } else {
        // gcash, credit_card, debit_card → PayMongo checkout
        submitCheckout();
    }
};

const submitCheckout = async () => {
    isCheckingOut.value = true;
    checkoutError.value = null;

    try {
        const page = usePage();
        const csrfToken = (page.props.csrf_token as string) ?? '';

        const response = await fetch(route('payment.checkout'), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({
                amount: form.amount,
                description: `${selectedTerm.value?.term_name || 'Payment'} - ${form.description || ''}`.trim(),
                selected_term_id: form.selected_term_id,
                payment_method: form.payment_method,
            }),
        });

        const data = await response.json();

        if (!response.ok) {
            throw new Error(data.error || data.message || `Server error: ${response.status}`);
        }

        if (!data.checkout_url) {
            throw new Error('No checkout URL returned. Please try again or contact support.');
        }

        window.location.href = data.checkout_url;

    } catch (error) {
        console.error('Checkout error:', error);
        checkoutError.value = error instanceof Error
            ? error.message
            : 'An unexpected error occurred. Please try again.';
    } finally {
        isCheckingOut.value = false;
    }
};

const submitBankTransfer = async () => {
    isCheckingOut.value = true;
    checkoutError.value = null;

    try {
        const page = usePage();
        const csrfToken = (page.props.csrf_token as string) ?? '';

        const response = await fetch(route('payment.bank-transfer'), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({
                amount: form.amount,
                reference_number: bankReferenceNumber.value.trim(),
                selected_term_id: form.selected_term_id,
            }),
        });

        const data = await response.json();

        if (!response.ok) {
            throw new Error(data.error || data.message || `Server error: ${response.status}`);
        }

        // Redirect to proof upload page with the transaction id returned
        if (data.transaction_id) {
            router.get(route('payment.proof.show', data.transaction_id));
        } else {
            // Fallback: show success and redirect to account
            submitSuccess.value = true;
            setTimeout(() => {
                router.get(route('student.account'), { tab: 'history' });
            }, 2000);
        }

    } catch (error) {
        console.error('Bank transfer error:', error);
        checkoutError.value = error instanceof Error
            ? error.message
            : 'An unexpected error occurred. Please try again.';
    } finally {
        isCheckingOut.value = false;
    }
};

// ── Due date helpers ──────────────────────────────────────────────────────────

const isOverdue = (dueDate: string | null): boolean => {
    if (!dueDate) return false;
    const due = new Date(dueDate);
    const today = new Date();
    due.setHours(0, 0, 0, 0);
    today.setHours(0, 0, 0, 0);
    return due < today;
};

const dueDateUrgency = (dueDate: string | null): 'red' | 'amber' | 'green' | null => {
    if (!dueDate) return null;
    const diffDays = Math.ceil((new Date(dueDate).getTime() - Date.now()) / 86_400_000);
    if (diffDays < 0) return 'red';
    if (diffDays <= 7) return 'red';
    if (diffDays <= 14) return 'amber';
    return 'green';
};
</script>

<template>
    <AppLayout>
        <Head title="Make Payment" />

        <div class="w-full p-6">
            <Breadcrumbs :items="breadcrumbs" />

            <div class="mb-6">
                <h1 class="text-2xl font-bold text-gray-900">Make a Payment</h1>
                <p class="text-sm text-muted-foreground mt-1">
                    Submit your payment for accounting verification.
                </p>
            </div>

            <!-- Success State -->
            <div
                v-if="submitSuccess"
                class="mb-6 rounded-xl border border-green-300 bg-green-50 p-5 flex items-center gap-3"
            >
                <CheckCircle :size="22" class="text-green-600 flex-shrink-0" />
                <div>
                    <p class="font-semibold text-green-900">Payment submitted successfully!</p>
                    <p class="text-sm text-green-700">
                        Your payment is awaiting accounting verification. Redirecting…
                    </p>
                </div>
            </div>

            <div v-else class="grid grid-cols-1 gap-6 lg:grid-cols-3">

                <!-- ── Left: Payment Form ─────────────────────────────────── -->
                <div class="lg:col-span-2 space-y-6">

                    <!-- No assessment guard -->
                    <div
                        v-if="!assessment"
                        class="rounded-xl border border-amber-300 bg-amber-50 p-5"
                    >
                        <p class="font-semibold text-amber-900">No active assessment found</p>
                        <p class="text-sm text-amber-700 mt-1">
                            Please contact the accounting office to create your assessment first.
                        </p>
                    </div>

                    <!-- Fully paid guard -->
                    <div
                        v-else-if="totalOutstandingBalance <= 0"
                        class="rounded-xl border border-green-300 bg-green-50 p-5 flex items-center gap-3"
                    >
                        <CheckCircle :size="22" class="text-green-600" />
                        <div>
                            <p class="font-semibold text-green-900">Account fully paid!</p>
                            <p class="text-sm text-green-700">You have no outstanding balance.</p>
                        </div>
                    </div>

                    <!-- Pending approvals warning -->
                    <div
                        v-if="pendingApprovalPayments.length > 0"
                        class="rounded-xl border border-amber-300 bg-amber-50 p-4"
                    >
                        <div class="flex items-start gap-3">
                            <AlertCircle :size="20" class="mt-0.5 flex-shrink-0 text-amber-600" />
                            <div class="flex-1">
                                <p class="mb-2 font-semibold text-amber-900">
                                    ⏳ Pending Payment(s) Awaiting Approval
                                </p>
                                <div class="space-y-1 text-sm text-amber-800">
                                    <div
                                        v-for="payment in pendingApprovalPayments"
                                        :key="payment.id"
                                        class="flex justify-between"
                                    >
                                        <span>{{ payment.term_name }} ({{ payment.reference }})</span>
                                        <span class="font-semibold">{{ formatCurrency(payment.amount) }}</span>
                                    </div>
                                </div>
                                <p class="mt-2 text-xs italic text-amber-700">
                                    Wait for accounting to verify your pending payment(s) before submitting another
                                    payment for the same term.
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Payment Form -->
                    <div
                        v-if="assessment && totalOutstandingBalance > 0"
                        class="ccdi-card p-6 space-y-5"
                    >
                        <h2 class="text-base font-semibold text-gray-900 border-b pb-3">
                            Payment Details
                        </h2>

                        <!-- Term selection -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Payment Term <span class="text-red-500">*</span>
                            </label>
                            <select
                                v-model.number="form.selected_term_id"
                                class="w-full rounded-lg border px-4 py-2 shadow-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 disabled:cursor-not-allowed disabled:bg-gray-100"
                                :disabled="effectiveBalance <= 0"
                            >
                                <option :value="null">— Select a term —</option>
                                <option
                                    v-for="term in availableTerms"
                                    :key="term.id"
                                    :value="term.id"
                                    :disabled="!term.isSelectable"
                                >
                                    {{ term.term_name }}
                                    {{ term.hasPending
                                        ? ` (⏳ Pending ₱${formatCurrency(term.pendingAmount)})`
                                        : ` — Balance: ₱${formatCurrency(term.balance)}`
                                    }}
                                    {{ !term.isSelectable && !term.hasPending ? ' (Pay previous term first)' : '' }}
                                </option>
                            </select>
                            <p class="mt-1 text-xs text-gray-500">
                                Only the first unpaid term is available. Terms must be paid sequentially.
                            </p>
                            <p v-if="form.errors.selected_term_id" class="mt-1 text-sm text-red-500">
                                {{ form.errors.selected_term_id }}
                            </p>
                        </div>

                        <!-- Amount -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Amount (₱) <span class="text-red-500">*</span>
                            </label>
                            <input
                                v-model="form.amount"
                                type="number"
                                step="0.01"
                                min="0.01"
                                :max="effectiveBalance"
                                placeholder="0.00"
                                :disabled="effectiveBalance <= 0 || !form.selected_term_id"
                                class="w-full rounded-lg border px-4 py-2 shadow-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 disabled:cursor-not-allowed disabled:bg-gray-100"
                            />
                            <p class="mt-1 text-xs text-gray-500">
                                Max: {{ formatCurrency(effectiveBalance) }}
                                <span v-if="pendingApprovalPayments.length > 0" class="text-amber-600 ml-1">
                                    (excludes {{ formatCurrency(totalOutstandingBalance - effectiveBalance) }} awaiting approval)
                                </span>
                            </p>
                            <p v-if="form.errors.amount" class="mt-1 text-sm text-red-500">
                                {{ form.errors.amount }}
                            </p>
                        </div>

                        <!-- Payment Method -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Payment Method <span class="text-red-500">*</span>
                            </label>
                            <select
                                v-model="form.payment_method"
                                class="w-full rounded-lg border px-4 py-2 shadow-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                            >
                                <option
                                    v-for="method in availablePaymentMethods"
                                    :key="method.value"
                                    :value="method.value"
                                >
                                    {{ method.label }}
                                </option>
                            </select>
                            <p v-if="form.errors.payment_method" class="mt-1 text-sm text-red-500">
                                {{ form.errors.payment_method }}
                            </p>
                        </div>

                        <!-- Bank Transfer: Bank Details + Reference Number -->
                        <div v-if="isBankTransfer" class="space-y-4">

                            <!-- Bank account info -->
                            <div class="rounded-lg border border-blue-200 bg-blue-50 p-4">
                                <p class="text-sm font-semibold text-blue-900 mb-2">
                                    Transfer to this account:
                                </p>
                                <div v-if="bankDetailsLoading" class="text-sm text-blue-600">
                                    Loading bank details…
                                </div>
                                <div v-else-if="bankDetails" class="space-y-1 text-sm text-blue-800">
                                    <div class="flex justify-between">
                                        <span class="text-blue-600">Bank</span>
                                        <span class="font-semibold">{{ bankDetails.bank_name }}</span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-blue-600">Account Name</span>
                                        <span class="font-semibold">{{ bankDetails.account_name }}</span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-blue-600">Account Number</span>
                                        <span class="font-mono font-semibold">{{ bankDetails.account_number }}</span>
                                    </div>
                                </div>
                                <div v-else class="space-y-1 text-sm text-blue-800">
                                    <p>Transfer your payment to the school's official bank account.</p>
                                    <p>Contact the accounting office for bank details.</p>
                                </div>
                                <p class="mt-3 text-xs text-blue-600">
                                    After transferring, enter your reference number below and upload proof of payment.
                                </p>
                            </div>

                            <!-- Reference number input -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">
                                    Bank Transfer Reference Number <span class="text-red-500">*</span>
                                </label>
                                <input
                                    v-model="bankReferenceNumber"
                                    type="text"
                                    placeholder="e.g. 202504191234567"
                                    maxlength="100"
                                    class="w-full rounded-lg border px-4 py-2 shadow-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                />
                                <p class="mt-1 text-xs text-gray-500">
                                    Found on your bank receipt or transfer confirmation.
                                </p>
                            </div>
                        </div>

                        <!-- Payment Date (not shown for bank transfer — reference date is used) -->
                        <div v-if="!isBankTransfer">
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Payment Date <span class="text-red-500">*</span>
                            </label>
                            <input
                                v-model="form.paid_at"
                                type="date"
                                :max="new Date().toISOString().split('T')[0]"
                                class="w-full rounded-lg border px-4 py-2 shadow-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                            />
                            <p v-if="form.errors.paid_at" class="mt-1 text-sm text-red-500">
                                {{ form.errors.paid_at }}
                            </p>
                        </div>

                        <!-- Description (optional) -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Notes <span class="text-gray-400 text-xs">(optional)</span>
                            </label>
                            <input
                                v-model="form.description"
                                type="text"
                                :placeholder="isBankTransfer ? 'e.g. Transferred via BDO online banking' : 'e.g. Additional notes'"
                                maxlength="255"
                                class="w-full rounded-lg border px-4 py-2 shadow-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                            />
                        </div>

                        <!-- Validation error -->
                        <div
                            v-if="validationError"
                            class="rounded-lg bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-700"
                        >
                            {{ validationError }}
                        </div>

                        <!-- Checkout/server error -->
                        <div
                            v-if="checkoutError"
                            class="rounded-lg bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-700 flex items-start gap-2"
                        >
                            <AlertCircle :size="16" class="mt-0.5 flex-shrink-0" />
                            <span>{{ checkoutError }}</span>
                        </div>

                        <!-- Submit -->
                        <button
                            type="button"
                            @click="submit"
                            :disabled="!canSubmit"
                            class="w-full rounded-xl bg-indigo-600 px-5 py-3 font-semibold text-white shadow transition-colors hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            <span v-if="form.processing || isCheckingOut">
                                <span v-if="isBankTransfer && isCheckingOut">Submitting bank transfer…</span>
                                <span v-else-if="isCheckingOut">Redirecting to payment…</span>
                                <span v-else>Submitting…</span>
                            </span>
                            <span v-else>
                                <span v-if="isBankTransfer">Submit Bank Transfer & Upload Proof</span>
                                <span v-else>Pay with {{ allPaymentMethods.find(m => m.value === form.payment_method)?.label }}</span>
                            </span>
                        </button>

                        <p class="text-center text-xs text-gray-400">
                            <span v-if="isBankTransfer">
                                You will be asked to upload proof of payment after submitting.
                            </span>
                            <span v-else>
                                Payments are subject to accounting verification before being marked as paid.
                            </span>
                        </p>
                    </div>
                </div>

                <!-- ── Right: Summary Panel ──────────────────────────────── -->
                <div class="space-y-4">

                    <!-- Assessment Info -->
                    <div v-if="assessment" class="ccdi-card p-5">
                        <h3 class="text-xs font-semibold uppercase tracking-widest text-muted-foreground mb-3">
                            Assessment
                        </h3>
                        <div class="space-y-2 text-sm">
                            <div class="flex justify-between">
                                <span class="text-gray-500">Number</span>
                                <span class="font-mono font-medium">{{ assessment.assessment_number }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-500">Term</span>
                                <span class="font-medium">{{ assessment.semester }} · {{ assessment.school_year }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-500">Year Level</span>
                                <span class="font-medium">{{ assessment.year_level }}</span>
                            </div>
                            <div class="border-t pt-2 flex justify-between">
                                <span class="text-gray-500">Total Assessment</span>
                                <span class="font-bold text-gray-900">{{ formatCurrency(assessment.total_assessment) }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-500">Outstanding</span>
                                <span class="font-bold text-red-600">{{ formatCurrency(totalOutstandingBalance) }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- Payment Terms Summary -->
                    <div v-if="paymentTerms.length" class="ccdi-card p-5">
                        <h3 class="text-xs font-semibold uppercase tracking-widest text-muted-foreground mb-3">
                            Payment Schedule
                        </h3>
                        <div class="space-y-2">
                            <div
                                v-for="term in paymentTerms"
                                :key="term.id"
                                class="flex items-center justify-between rounded-lg px-3 py-2 text-sm"
                                :class="term.balance <= 0
                                    ? 'bg-green-50'
                                    : term.id === (availableTerms[0]?.id)
                                        ? 'bg-indigo-50 ring-1 ring-indigo-200'
                                        : 'bg-gray-50'"
                            >
                                <div class="flex items-center gap-2">
                                    <CheckCircle
                                        v-if="term.balance <= 0"
                                        :size="14"
                                        class="text-green-500 flex-shrink-0"
                                    />
                                    <Clock
                                        v-else
                                        :size="14"
                                        class="text-gray-400 flex-shrink-0"
                                    />
                                    <span :class="term.balance <= 0 ? 'text-green-700' : 'text-gray-700'">
                                        {{ term.term_name }}
                                    </span>
                                </div>
                                <div class="text-right">
                                    <p
                                        class="font-semibold text-xs"
                                        :class="term.balance <= 0 ? 'text-green-600' : 'text-gray-800'"
                                    >
                                        {{ term.balance <= 0 ? '✓ Paid' : formatCurrency(term.balance) }}
                                    </p>
                                    <p
                                        v-if="term.due_date && term.balance > 0"
                                        class="text-xs"
                                        :class="isOverdue(term.due_date) ? 'text-red-500' : 'text-gray-400'"
                                    >
                                        {{ formatDate(term.due_date) }}
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Student Info -->
                    <div class="ccdi-card p-5">
                        <h3 class="text-xs font-semibold uppercase tracking-widest text-muted-foreground mb-3">
                            Student
                        </h3>
                        <div class="space-y-1 text-sm">
                            <p class="font-semibold text-gray-900">{{ student.name }}</p>
                            <p class="text-gray-500 font-mono">{{ student.account_id }}</p>
                            <p class="text-gray-500">{{ student.course }}</p>
                            <p class="text-gray-500">{{ student.year_level }}</p>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </AppLayout>
</template>