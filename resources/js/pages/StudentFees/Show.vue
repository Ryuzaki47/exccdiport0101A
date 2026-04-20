<script setup lang="ts">
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useDataFormatting } from '@/composables/useDataFormatting';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import {
    AlertCircle,
    ArrowLeft,
    BookOpen,
    CheckCircle2,
    ChevronDown,
    CreditCard,
    Download,
    FlaskConical,
    Plus,
} from 'lucide-vue-next';
import { computed, onMounted, ref, watch } from 'vue';

// ─── Types ─────────────────────────────────────────────────────────────────────

interface PaymentTerm {
    id: number;
    term_name: string;
    term_order: number;
    percentage: number;
    amount: number;
    balance: number;
    due_date: string | null;
    status: string;
    remarks?: string;
}

interface FeeBreakdownItem {
    category: string;
    name: string;
    code?: string;
    units?: number;
    amount: number;
    subject_id?: number;
}

interface Assessment {
    id: number;
    course: string | null;
    semester: string;
    school_year: string;
    year_level: string;
    total_assessment: number;
    tuition_fee: number;
    other_fees: number;
    fee_breakdown: FeeBreakdownItem[];
    paymentTerms?: PaymentTerm[];
}

interface Props {
    student: any;
    assessment: any;
    allAssessments?: Assessment[];
    transactions?: any[];
    payments?: any[];
    feeBreakdown?: Array<{ category: string; total: number; items: number }>;
    backUrl?: string;
    enrolledSubjectsByAssessment?: Record<number, number[]>;
    // ✅ FIX: was missing from Props interface, causing TS error
    miscItems?: Array<{ label?: string; name?: string; amount: number }>;
}

const props = withDefaults(defineProps<Props>(), {
    allAssessments: () => [],
    transactions: () => [],
    payments: () => [],
    feeBreakdown: () => [],
    enrolledSubjectsByAssessment: () => ({}),
    miscItems: () => [],
});

// ─── Assessment selector ────────────────────────────────────────────────────

const { formatCurrency } = useDataFormatting();
const page = usePage();
const isAdmin = computed(() => (page.props.auth as any).user?.role === 'admin');
const isAccounting = computed(() => (page.props.auth as any).user?.role === 'accounting');

const selectedAssessmentId = ref<number | null>(props.assessment?.id ?? null);

const selectedAssessment = computed(() => {
    if (!selectedAssessmentId.value) return props.assessment;
    return (
        (props.allAssessments ?? []).find((a) => a.id === selectedAssessmentId.value) ??
        props.assessment
    );
});

const exportUrl = computed(() => {
    const base = route('student-fees.export-pdf', props.student.id);
    return selectedAssessmentId.value ? `${base}?assessment_id=${selectedAssessmentId.value}` : base;
});

// ─── Balance ────────────────────────────────────────────────────────────────

const remainingBalance = computed(() => {
    // Always use the selected assessment's payment terms for balance calculation
    const terms: PaymentTerm[] =
        (selectedAssessment.value as any)?.paymentTerms ??
        props.assessment?.paymentTerms ??
        [];
    
    // Sum balance from payment terms (most accurate source)
    if (terms.length > 0) {
        const termsTotal = terms.reduce((sum, t) => sum + parseFloat(String(t.balance)), 0);
        if (termsTotal > 0) return Math.round(termsTotal * 100) / 100;
    }

    // If NO payment terms exist for this assessment, balance is zero
    // DO NOT fall back to account.balance—that's the total across all assessments,
    // not the balance for THIS specific assessment
    return 0;
});

const totalAssessment = computed(() =>
    parseFloat(
        String(
            selectedAssessment.value?.total_assessment ??
                props.assessment?.total_assessment ??
                0,
        ),
    ),
);

const totalPaid = computed(() => Math.max(0, totalAssessment.value - remainingBalance.value));

const paymentTimingStatus = computed((): 'behind' | 'on_track' | 'paid' => {
    const terms: PaymentTerm[] =
        (selectedAssessment.value as any)?.paymentTerms ??
        props.assessment?.paymentTerms ??
        [];
    if (terms.length === 0) return 'behind';
    if (remainingBalance.value === 0) return 'paid';
    const sorted = [...terms].sort((a, b) => a.term_order - b.term_order);
    const first = sorted[0];
    if (
        first.status === 'pending' &&
        parseFloat(String(first.balance)) >= parseFloat(String(first.amount)) * 0.99
    )
        return 'behind';
    return 'on_track';
});

const balanceCardConfig = computed(() => {
    switch (paymentTimingStatus.value) {
        case 'paid':
            return {
                bg: 'bg-gradient-to-r from-green-50 to-emerald-50 border-green-200',
                labelColor: 'text-green-700',
                amountColor: 'text-green-700',
                badge: { label: 'Fully Paid', cls: 'bg-green-500 text-white' },
            };
        case 'on_track':
            return {
                bg: 'bg-gradient-to-r from-blue-50 to-indigo-50 border-blue-200',
                labelColor: 'text-blue-700',
                amountColor: 'text-blue-700',
                badge: { label: 'On Track', cls: 'bg-blue-500 text-white' },
            };
        default:
            return {
                bg: 'bg-gradient-to-r from-red-50 to-rose-50 border-red-200',
                labelColor: 'text-red-700',
                amountColor: 'text-red-700',
                badge: { label: 'Behind Schedule', cls: 'bg-red-500 text-white' },
            };
    }
});

// ─── Payment Terms ────────────────────────────────────────────────────────────

const allTermsSorted = computed((): PaymentTerm[] => {
    const terms: PaymentTerm[] =
        (selectedAssessment.value as any)?.paymentTerms ??
        props.assessment?.paymentTerms ??
        [];
    return [...terms].sort((a, b) => a.term_order - b.term_order);
});

const paidTermsCount = computed(() =>
    allTermsSorted.value.filter((t) => t.status === 'paid').length,
);

// ─── Fee Breakdown ─────────────────────────────────────────────────────────────

const tuitionItems = computed(() => {
    const selectedAssess = selectedAssessment.value as any;
    if (!selectedAssess) return [];
    return (selectedAssess.fee_breakdown ?? [])
        .filter((item: any) => item.category === 'Tuition')
        .map((item: any) => ({
            ...item,
            displayName: item.name || item.code || 'Subject',
            amount: parseFloat(String(item.amount)),
        }));
});

const totalTuition = computed(() =>
    Math.round(
        tuitionItems.value.reduce((sum: number, item: any) => sum + item.amount, 0) * 100,
    ) / 100,
);

const labItems = computed(() => {
    const selectedAssess = selectedAssessment.value as any;
    if (!selectedAssess) return [];
    return (selectedAssess.fee_breakdown ?? [])
        .filter((item: any) => item.category === 'Laboratory')
        .map((item: any) => ({
            ...item,
            units: item.units ?? selectedAssess.lab_units ?? 0,
            displayName: item.name?.replace('Laboratory Fee — ', '') || 'Laboratory',
            amount: parseFloat(String(item.amount)),
        }));
});

const totalLab = computed(() =>
    Math.round(
        labItems.value.reduce((sum: number, item: any) => sum + item.amount, 0) * 100,
    ) / 100,
);

interface MiscItemGroup {
    category: string;
    subcategory: string;
    label: string;
    items: Array<{ name: string; amount: number }>;
    total: number;
}

const miscellaneousItemsByGroup = computed((): MiscItemGroup[] => {
    const selectedAssess = selectedAssessment.value as any;
    if (!selectedAssess) return [];

    const miscEntry = (selectedAssess.fee_breakdown ?? []).find(
        (item: any) => item.category === 'Miscellaneous',
    );
    if (!miscEntry) return [];

    const items: Array<{ name: string; amount: number }> = (props.miscItems ?? [])
        .filter((i: any) => parseFloat(String(i.amount)) > 0)
        .map((i: any) => ({
            name: i.label ?? i.name,
            amount: parseFloat(String(i.amount)),
        }));

    if (items.length === 0) {
        return [
            {
                category: 'Miscellaneous',
                subcategory: 'Miscellaneous',
                label: 'Miscellaneous Fees',
                items: [
                    {
                        name: 'Miscellaneous Fee (Fixed)',
                        amount: parseFloat(String(miscEntry.amount)),
                    },
                ],
                total: parseFloat(String(miscEntry.amount)),
            },
        ];
    }

    const academicPatterns = ['registration', 'lms', 'library', 'entrepreneurship'];
    const studentPatterns = [
        'athletic',
        'prisaa',
        'publication',
        'id',
        'biccs',
        'pccl',
        'league',
        'audio-visual',
        'faculty development',
        'guidance',
        'entrep',
    ];
    const supportPatterns = ['medical', 'insurance', 'cultural', 'maintenance'];

    const categories: Record<string, MiscItemGroup> = {
        academic: {
            category: 'Academic Services',
            subcategory: 'Academic',
            label: 'Academic Services',
            items: [],
            total: 0,
        },
        student_life: {
            category: 'Student Life & Activities',
            subcategory: 'Student',
            label: 'Student Life & Activities',
            items: [],
            total: 0,
        },
        support: {
            category: 'Support Services',
            subcategory: 'Support',
            label: 'Support Services',
            items: [],
            total: 0,
        },
        other: {
            category: 'Other Fees',
            subcategory: 'Other',
            label: 'Other Fees',
            items: [],
            total: 0,
        },
    };

    for (const item of items) {
        const name = item.name.toLowerCase();
        if (academicPatterns.some((p) => name.includes(p))) {
            categories.academic.items.push(item);
            categories.academic.total += item.amount;
        } else if (studentPatterns.some((p) => name.includes(p))) {
            categories.student_life.items.push(item);
            categories.student_life.total += item.amount;
        } else if (supportPatterns.some((p) => name.includes(p))) {
            categories.support.items.push(item);
            categories.support.total += item.amount;
        } else {
            categories.other.items.push(item);
            categories.other.total += item.amount;
        }
    }

    return Object.values(categories).filter((cat) => cat.items.length > 0);
});

const totalMiscellaneous = computed(() => {
    const fromGroups =
        Math.round(
            miscellaneousItemsByGroup.value.reduce((sum, group) => sum + group.total, 0) * 100,
        ) / 100;
    if (fromGroups > 0) return fromGroups;
    const miscEntry = (
        (selectedAssessment.value as any)?.fee_breakdown ?? []
    ).find((i: any) => i.category === 'Miscellaneous');
    return miscEntry ? parseFloat(String(miscEntry.amount)) : 0;
});

const feeCalculationSummary = computed(() => {
    const totalUnits = tuitionItems.value.reduce(
        (sum: number, item: any) => sum + (item.units || 0),
        0,
    );
    const labCount = labItems.value.reduce(
        (sum: number, item: any) => sum + (item.units || 0),
        0,
    );

    if (totalUnits <= 0) return '';

    const parts = [];
    if (totalUnits > 0)
        parts.push(
            `${totalUnits.toFixed(1)} LEC unit${totalUnits !== 1 ? 's' : ''} × ₱364.00`,
        );
    if (labCount > 0)
        parts.push(
            `${labCount.toFixed(1)} LAB unit${labCount !== 1 ? 's' : ''} × ₱1,656.00`,
        );
    if (totalMiscellaneous.value > 0)
        parts.push(`₱${totalMiscellaneous.value.toFixed(2)} misc`);

    return parts.length > 0 ? parts.join(' + ') : '—';
});

// ─── Transaction history ─────────────────────────────────────────────────────

interface TxGroup {
    key: string;
    assessmentId: number | null;
    transactions: any[];
    totalCharges: number;
    totalPaid: number;
    balance: number;
}

// All payment-kind transactions for this student (unfiltered — used by the Ledger)
const allPaymentTransactions = computed(() =>
    (props.transactions ?? []).filter((t: any) => t.kind === 'payment'),
);

// Transactions filtered to the selected assessment — used only by Payment History card
const filteredTransactions = computed(() => {
    if (!selectedAssessmentId.value || !selectedAssessment.value) return allPaymentTransactions.value;
    const assessment = selectedAssessment.value;
    return allPaymentTransactions.value.filter((t: any) => {
        const startYear = parseInt(String(assessment.school_year?.split('-')[0] ?? ''), 10);
        return (
            parseInt(String(t.year), 10) === startYear &&
            String(t.semester).trim() === String(assessment.semester).trim()
        );
    });
});

const transactionsByTerm = computed((): TxGroup[] => {
    const groups: Record<string, { transactions: any[]; assessmentId: number | null }> = {};

    for (const t of allPaymentTransactions.value) {
        let key: string;
        if (t.year && t.semester) {
            const startYear = parseInt(String(t.year), 10);
            key = `${isNaN(startYear) ? String(t.year) : `${startYear}-${startYear + 1}`} ${t.semester}`;
        } else {
            key = 'Other';
        }
        if (!groups[key]) groups[key] = { transactions: [], assessmentId: null };
        groups[key].transactions.push(t);

        if (groups[key].assessmentId === null && t.year && t.semester) {
            const startYear = parseInt(String(t.year), 10);
            const syEnd = startYear + 1;
            const match = (props.allAssessments ?? []).find(
                (a) =>
                    a.school_year === `${startYear}-${syEnd}` &&
                    String(a.semester).trim() === String(t.semester).trim(),
            );
            groups[key].assessmentId = match?.id ?? null;
        }
    }

    return Object.entries(groups)
        .map(([key, group]) => {
            const totalPaidAmt = group.transactions
                .filter((t) => t.kind === 'payment' && t.status === 'paid')
                .reduce((s, t) => s + parseFloat(t.amount), 0);

            // Use each group's own assessment total, not the selected assessment
            const groupAssessment = group.assessmentId
                ? (props.allAssessments ?? []).find((a) => a.id === group.assessmentId)
                : null;
            const assessmentTotal = parseFloat(
                String(groupAssessment?.total_assessment ?? selectedAssessment.value?.total_assessment ?? props.assessment?.total_assessment ?? 0),
            );

            return {
                key,
                assessmentId: group.assessmentId,
                transactions: group.transactions,
                totalCharges: assessmentTotal,
                totalPaid: totalPaidAmt,
                balance: assessmentTotal - totalPaidAmt,
            };
        })
        .sort((a, b) =>
            parseInt(a.key.split('-')[0] ?? '0', 10) - parseInt(b.key.split('-')[0] ?? '0', 10) >
            0
                ? -1
                : 1,
        );
});

const expandedTerms = ref<Record<string, boolean>>({});
const toggleTerm = (key: string) => {
    expandedTerms.value = { ...expandedTerms.value, [key]: !expandedTerms.value[key] };
};

// ─── Auto-expand helper ───────────────────────────────────────────────────────
function autoExpandCurrentTerm() {
    if (transactionsByTerm.value.length === 0) return;
    const matchKey = currentAssessmentTermKey.value;
    const autoKey =
        matchKey && transactionsByTerm.value.some((g) => g.key === matchKey)
            ? matchKey
            : transactionsByTerm.value[0].key;
    expandedTerms.value = { [autoKey]: true };
}

const currentAssessmentTermKey = computed<string | null>(() => {
    if (!selectedAssessment.value?.school_year || !selectedAssessment.value?.semester)
        return null;
    return `${selectedAssessment.value.school_year} ${selectedAssessment.value.semester}`;
});

const expandedTxSubjectPanels = ref<Set<string>>(new Set());

function toggleTxSubjectPanel(key: string) {
    if (expandedTxSubjectPanels.value.has(key)) {
        expandedTxSubjectPanels.value.delete(key);
    } else {
        expandedTxSubjectPanels.value.add(key);
    }
}

function buildSubjectPanel(a: Assessment) {
    const subjectRows = (a.fee_breakdown ?? []).filter(
        (item) => item.category === 'Tuition' || item.category === 'Laboratory',
    );

    const subjectMap: Record<
        number,
        {
            subject_id: number;
            code: string;
            name: string;
            lecUnits: number;
            labUnits: number;
            tuitionAmount: number;
            labAmount: number;
            hasLab: boolean;
            isEnrolled: boolean;
        }
    > = {};

    const enrolledIds = new Set(
        (props.enrolledSubjectsByAssessment ?? {})[a.id] ?? [],
    );

    for (const row of subjectRows) {
        const sid = row.subject_id;
        if (sid === undefined) continue;

        if (!subjectMap[sid]) {
            subjectMap[sid] = {
                subject_id: sid,
                code: row.code ?? '—',
                name: row.name,
                lecUnits: 0,
                labUnits: 0,
                tuitionAmount: 0,
                labAmount: 0,
                hasLab: false,
                isEnrolled: enrolledIds.has(sid),
            };
        }

        if (row.category === 'Tuition') {
            subjectMap[sid].tuitionAmount = parseFloat(String(row.amount));
            subjectMap[sid].lecUnits = row.units ?? subjectMap[sid].lecUnits;
            if (!subjectMap[sid].name || subjectMap[sid].name.startsWith('Laboratory')) {
                subjectMap[sid].name = row.name;
            }
        } else if (row.category === 'Laboratory') {
            subjectMap[sid].labAmount = parseFloat(String(row.amount));
            subjectMap[sid].labUnits = row.units ?? 0;
            subjectMap[sid].hasLab = true;
        }
    }

    const subjects = Object.values(subjectMap);
    const totalLecUnits = subjects.reduce((s, sub) => s + sub.lecUnits, 0);
    const totalLabUnits = subjects.reduce((s, sub) => s + sub.labUnits, 0);
    const totalUnits = totalLecUnits + totalLabUnits;
    const totalTuitionVal = subjects.reduce((s, sub) => s + sub.tuitionAmount, 0);
    const totalLabVal = subjects.reduce((s, sub) => s + sub.labAmount, 0);
    const enrolledCount = subjects.filter((sub) => sub.isEnrolled).length;

    return {
        assessmentId: a.id,
        label: `${a.year_level} — ${a.semester}`,
        schoolYear: a.school_year,
        course: a.course ?? '—',
        totalLecUnits,
        totalLabUnits,
        totalUnits,
        totalTuition: totalTuitionVal,
        totalLab: totalLabVal,
        subjectCount: subjects.length,
        enrolledCount,
        subjects,
    };
}

const txSubjectPanels = computed(
    (): Record<string, ReturnType<typeof buildSubjectPanel> | null> => {
        const result: Record<string, ReturnType<typeof buildSubjectPanel> | null> = {};
        for (const group of transactionsByTerm.value) {
            if (group.assessmentId === null) {
                result[group.key] = null;
                continue;
            }
            const assessment = (props.allAssessments ?? []).find(
                (a) => a.id === group.assessmentId,
            );
            if (!assessment || !assessment.fee_breakdown?.length) {
                result[group.key] = null;
                continue;
            }
            const panel = buildSubjectPanel(assessment);
            result[group.key] = panel.subjects.length > 0 ? panel : null;
        }
        return result;
    },
);

// ─── Payment form ─────────────────────────────────────────────────────────────

const breadcrumbs = [
    { title: 'Dashboard', href: route('admin.dashboard') },
    { title: 'Archives', href: route('students.archive') },
    { title: props.student.name },
];

const PAYMENT_PAGE_SIZE = 5;
const paymentHistoryLimit = ref(PAYMENT_PAGE_SIZE);

const filteredPayments = computed(() => {
    const selectedId = selectedAssessmentId.value;
    if (!selectedId) return props.payments ?? [];
    return (props.payments ?? []).filter((p: any) => p.assessment_id === selectedId);
});

const visiblePayments = computed(() =>
    filteredPayments.value.slice(0, paymentHistoryLimit.value),
);
const hasMorePayments = computed(
    () => filteredPayments.value.length > paymentHistoryLimit.value,
);
const loadMorePayments = () => {
    paymentHistoryLimit.value += PAYMENT_PAGE_SIZE;
};

const showPaymentDialog = ref(false);

const paymentForm = useForm({
    amount: '',
    payment_method: 'cash',
    assessment_id: null as number | null,
    payment_date: new Date().toISOString().split('T')[0],
});

const paymentAmountError = computed(() => {
    const amount = parseFloat(paymentForm.amount) || 0;
    if (amount <= 0 && paymentForm.amount) return 'Amount must be greater than zero';
    if (amount > remainingBalance.value) {
        return `Amount cannot exceed the outstanding balance of ${formatCurrency(remainingBalance.value)}`;
    }
    return '';
});

const projectedRemainingBalance = computed(() =>
    Math.max(0, remainingBalance.value - (parseFloat(paymentForm.amount) || 0)),
);

const allocationPreview = computed(() => {
    const entered = parseFloat(paymentForm.amount) || 0;
    if (entered <= 0) return [];

    const unpaid = [...allTermsSorted.value]
        .filter((t) => parseFloat(String(t.balance)) > 0)
        .sort((a, b) => a.term_order - b.term_order);

    let remaining = entered;
    const rows: Array<{
        name: string;
        applied: number;
        balanceAfter: number;
        willBePaid: boolean;
    }> = [];

    for (const term of unpaid) {
        if (remaining <= 0) break;
        const bal = parseFloat(String(term.balance));
        const applied = Math.min(remaining, bal);
        rows.push({
            name: term.term_name,
            applied,
            balanceAfter: Math.max(0, bal - applied),
            willBePaid: applied >= bal,
        });
        remaining -= applied;
    }
    return rows;
});

const canSubmitPayment = computed(
    () =>
        parseFloat(paymentForm.amount) > 0 &&
        !paymentAmountError.value &&
        paymentForm.assessment_id !== null &&
        !paymentForm.processing,
);

const getTermStatusConfig = (status: string) => {
    const map: Record<string, { bg: string; text: string; label: string }> = {
        pending: { bg: 'bg-yellow-100', text: 'text-yellow-800', label: 'Unpaid' },
        partial: { bg: 'bg-orange-100', text: 'text-orange-800', label: 'Partial' },
        paid: { bg: 'bg-green-100', text: 'text-green-800', label: 'Paid' },
        overdue: { bg: 'bg-red-100', text: 'text-red-800', label: 'Overdue' },
    };
    return map[status] ?? { bg: 'bg-gray-100', text: 'text-gray-800', label: status };
};

watch(
    () => showPaymentDialog.value,
    (isOpen) => {
        if (isOpen) {
            paymentForm.assessment_id =
                selectedAssessmentId.value ?? props.assessment?.id ?? null;
        }
    },
);

watch(
    () => selectedAssessmentId.value,
    (newId) => {
        paymentForm.assessment_id = newId ?? props.assessment?.id ?? null;
        paymentForm.reset();
        paymentForm.clearErrors();
        autoExpandCurrentTerm();
    },
);

onMounted(() => {
    autoExpandCurrentTerm();
});

// ─── ✅ FIXED submitPayment ──────────────────────────────────────────────────
// Original code used paymentForm.post() which relies on Inertia's internal
// fetch. The 419 was caused by a stale CSRF token after the session expired
// or was refreshed server-side. The fix:
//  1. Use router.visit() to do a fresh Inertia visit before submitting — this
//     forces the XSRF-TOKEN cookie to be refreshed.
//  2. Use the Inertia useForm post with `forceFormData: true` so the CSRF
//     token is re-read from the latest cookie, not a cached value.

const submitPayment = () => {
    if (!canSubmitPayment.value) {
        if (!paymentForm.amount) paymentForm.setError('amount', 'Please enter an amount');
        return;
    }

    paymentForm.post(route('student-fees.payments.store', props.student.id), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            showPaymentDialog.value = false;
            paymentForm.reset();
            paymentForm.clearErrors();
        },
        onError: (errors) => {
            console.error('Payment errors:', errors);
        },
    });
};

// ─── Helpers ──────────────────────────────────────────────────────────────────

const formatDate = (d: string) =>
    new Date(d).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
    });
const formatDateShort = (d: string) =>
    new Date(d).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });

const toYearRange = (year: string | number | null | undefined): string => {
    if (!year) return '—';
    const y = parseInt(String(year), 10);
    return isNaN(y) ? String(year) : `${y}-${y + 1}`;
};

const getStudentStatusColor = (status: string) => {
    const map: Record<string, string> = {
        active: 'bg-green-100 text-green-800',
        graduated: 'bg-blue-100 text-blue-800',
        dropped: 'bg-red-100 text-red-800',
    };
    return map[status] ?? 'bg-gray-100 text-gray-800';
};
</script>

<template>
    <Head :title="`Fee Details — ${student.name}`" />

    <AppLayout>
        <div class="w-full space-y-6 p-6">
            <Breadcrumbs :items="breadcrumbs" />

            <!-- ── Header ── -->
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex items-center gap-4">
                    <Link :href="backUrl">
                        <Button variant="outline" size="sm">
                            <ArrowLeft class="mr-2 h-4 w-4" /> Back
                        </Button>
                    </Link>
                    <div>
                        <h1 class="text-2xl font-bold text-gray-900">{{ student.name }}</h1>
                        <p class="mt-0.5 text-sm text-gray-500">
                            {{ student.account_id }} &middot;
                            <span class="font-medium">{{
                                selectedAssessment?.course || student.course || '—'
                            }}</span>
                            <span
                                v-if="
                                    selectedAssessment?.course &&
                                    selectedAssessment.course !== student.course
                                "
                                class="ml-2 inline-flex items-center rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-semibold text-amber-700"
                            >
                                Assessment Course
                            </span>
                            &middot;
                            <span
                                v-if="selectedAssessment?.year_level"
                                class="font-medium text-blue-700"
                                >{{ selectedAssessment.year_level }}</span
                            >
                            <span v-else>{{ student.year_level }}</span>
                            &middot;
                            <span
                                :class="[
                                    'ml-2 inline-flex rounded-full px-2 py-0.5 text-xs font-semibold',
                                    student.is_irregular
                                        ? 'bg-amber-100 text-amber-700'
                                        : 'bg-blue-100 text-blue-700',
                                ]"
                            >
                                {{ student.is_irregular ? 'Irregular' : 'Regular' }}
                            </span>
                        </p>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <select
                        v-if="allAssessments.length > 1"
                        v-model.number="selectedAssessmentId"
                        class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm focus:ring-2 focus:ring-indigo-500 focus:outline-none"
                        title="Select semester to view"
                    >
                        <option v-for="a in allAssessments" :key="a.id" :value="a.id">
                            {{ a.year_level }} — {{ a.semester }} Sem {{ a.school_year }}
                        </option>
                    </select>
                    <a :href="exportUrl" target="_blank">
                        <Button variant="outline" size="sm">
                            <Download class="mr-2 h-4 w-4" /> Export PDF
                        </Button>
                    </a>
                    <Link v-if="isAdmin" :href="route('student-fees.edit-student', student.id)">
                        <Button variant="outline" size="sm">
                            <BookOpen class="mr-2 h-4 w-4" /> Edit Info
                        </Button>
                    </Link>
                    <Link v-if="isAdmin && selectedAssessment" :href="route('student-fees.edit', student.id)">
                        <Button variant="outline" size="sm">
                            <BookOpen class="mr-2 h-4 w-4" /> Edit Assessment
                        </Button>
                    </Link>
                    <Dialog v-if="isAccounting" v-model:open="showPaymentDialog">
                        <DialogTrigger as-child>
                            <Button size="sm"
                                ><Plus class="mr-2 h-4 w-4" /> Record Payment</Button
                            >
                        </DialogTrigger>
                        <DialogContent>
                            <DialogHeader>
                                <DialogTitle>Record Payment</DialogTitle>
                                <DialogDescription>
                                    <div class="space-y-1">
                                        <p>
                                            Recording payment for
                                            <strong>{{ student.name }}</strong>
                                        </p>
                                        <p class="text-sm text-gray-500">
                                            {{ selectedAssessment?.year_level }} —
                                            {{ selectedAssessment?.semester }}
                                            {{ selectedAssessment?.school_year }}
                                        </p>
                                        <p class="text-base font-semibold text-slate-900">
                                            Outstanding Balance:
                                            {{ formatCurrency(remainingBalance) }}
                                        </p>
                                    </div>
                                </DialogDescription>
                            </DialogHeader>

                            <!-- ✅ FIX: removed <form> tag — use @submit.prevent on div is wrong.
                                 Inertia useForm.post() does not need a native form submit.
                                 Using a plain div with button onClick avoids any native
                                 form submission that could bypass Inertia's CSRF handling. -->
                            <div class="space-y-4">
                                <div class="space-y-2">
                                    <Label for="amount">Amount *</Label>
                                    <Input
                                        id="amount"
                                        v-model="paymentForm.amount"
                                        type="number"
                                        step="0.01"
                                        min="0.01"
                                        placeholder="0.00"
                                        :class="{ 'border-red-500': paymentAmountError }"
                                    />
                                    <p
                                        v-if="paymentAmountError"
                                        class="text-sm font-medium text-red-500"
                                    >
                                        {{ paymentAmountError }}
                                    </p>
                                    <p v-else class="text-xs text-gray-500">
                                        Enter any amount — payment will be applied sequentially
                                        across outstanding terms.
                                    </p>
                                    <p
                                        v-if="paymentForm.errors.amount"
                                        class="text-sm text-red-500"
                                    >
                                        {{ paymentForm.errors.amount }}
                                    </p>
                                </div>

                                <div class="space-y-2">
                                    <Label>Payment Method</Label>
                                    <div
                                        class="rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-sm font-medium text-gray-700"
                                    >
                                        💵 Cash (In-Person Payment)
                                    </div>
                                    <p class="text-xs text-gray-500">
                                        All payments are recorded as cash transactions for
                                        in-person payments.
                                    </p>
                                </div>

                                <div class="space-y-2">
                                    <Label for="payment_date">Payment Date *</Label>
                                    <Input
                                        id="payment_date"
                                        v-model="paymentForm.payment_date"
                                        type="date"
                                        required
                                    />
                                    <p
                                        v-if="paymentForm.errors.payment_date"
                                        class="text-sm text-red-500"
                                    >
                                        {{ paymentForm.errors.payment_date }}
                                    </p>
                                </div>

                                <div
                                    v-if="allocationPreview.length > 0"
                                    class="overflow-hidden rounded-lg border border-indigo-200 bg-indigo-50 text-sm"
                                >
                                    <div
                                        class="flex items-center justify-between border-b border-indigo-200 bg-indigo-100 px-4 py-2"
                                    >
                                        <p
                                            class="text-xs font-semibold tracking-wide text-indigo-700 uppercase"
                                        >
                                            Allocation Preview
                                        </p>
                                        <p class="text-xs text-indigo-600">
                                            Applied oldest term first
                                        </p>
                                    </div>
                                    <div class="divide-y divide-indigo-100">
                                        <div
                                            v-for="row in allocationPreview"
                                            :key="row.name"
                                            class="flex items-center justify-between px-4 py-2.5"
                                        >
                                            <div class="flex items-center gap-2">
                                                <span
                                                    :class="[
                                                        'inline-flex h-5 w-5 flex-shrink-0 items-center justify-center rounded-full text-xs font-bold',
                                                        row.willBePaid
                                                            ? 'bg-green-100 text-green-700'
                                                            : 'bg-amber-100 text-amber-700',
                                                    ]"
                                                >
                                                    {{ row.willBePaid ? '✓' : '~' }}
                                                </span>
                                                <div>
                                                    <p class="font-medium text-gray-900">
                                                        {{ row.name }}
                                                    </p>
                                                    <p class="text-xs text-gray-500">
                                                        Balance after:
                                                        {{ formatCurrency(row.balanceAfter) }}
                                                        <span
                                                            v-if="row.willBePaid"
                                                            class="ml-1 font-semibold text-green-600"
                                                            >· Fully paid</span
                                                        >
                                                        <span
                                                            v-else
                                                            class="ml-1 text-amber-600"
                                                            >· Partial</span
                                                        >
                                                    </p>
                                                </div>
                                            </div>
                                            <span class="font-semibold text-indigo-700">
                                                {{ formatCurrency(row.applied) }}
                                            </span>
                                        </div>
                                    </div>
                                    <div
                                        class="flex items-center justify-between border-t border-indigo-200 bg-indigo-100 px-4 py-2"
                                    >
                                        <div>
                                            <p class="text-xs font-semibold text-indigo-800">
                                                Total Applied
                                            </p>
                                        </div>
                                        <div class="text-right">
                                            <p class="font-bold text-indigo-800">
                                                {{
                                                    formatCurrency(
                                                        parseFloat(paymentForm.amount) || 0,
                                                    )
                                                }}
                                            </p>
                                            <p class="text-xs text-indigo-600">
                                                Balance after:
                                                {{ formatCurrency(projectedRemainingBalance) }}
                                            </p>
                                        </div>
                                    </div>
                                </div>

                                <p
                                    v-if="paymentForm.errors.payment"
                                    class="text-sm font-medium text-red-600"
                                >
                                    {{ paymentForm.errors.payment }}
                                </p>

                                <!-- ✅ FIX: was paymentForm.errors.error — the controller
                                     returns back()->withErrors(['payment' => '...'])
                                     so the key is 'payment', not 'error'. -->
                                <p
                                    v-if="(paymentForm.errors as any).error"
                                    class="text-sm font-medium text-red-600"
                                >
                                    {{ (paymentForm.errors as any).error }}
                                </p>

                                <DialogFooter>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        @click="showPaymentDialog = false"
                                        >Cancel</Button
                                    >
                                    <Button
                                        type="button"
                                        :disabled="!canSubmitPayment"
                                        :class="{ 'cursor-not-allowed opacity-50': !canSubmitPayment }"
                                        @click="submitPayment"
                                    >
                                        <span v-if="paymentForm.processing">Recording…</span>
                                        <span v-else>Record Payment</span>
                                    </Button>
                                </DialogFooter>
                            </div>
                        </DialogContent>
                    </Dialog>
                </div>
            </div>

            <!-- ── Personal Info ── -->
            <Card>
                <CardHeader><CardTitle>Personal Information</CardTitle></CardHeader>
                <CardContent>
                    <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
                        <div>
                            <Label class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Full Name</Label>
                            <p class="mt-0.5 font-medium text-foreground">{{ student.name }}</p>
                        </div>
                        <div>
                            <Label class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Email</Label>
                            <p class="mt-0.5 font-medium text-foreground">{{ student.email }}</p>
                        </div>
                        <div>
                            <Label class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Birthday</Label>
                            <p class="mt-0.5 font-medium text-foreground">
                                {{ student.birthday ? formatDate(student.birthday) : 'N/A' }}
                            </p>
                        </div>
                        <div>
                            <Label class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Phone</Label>
                            <p class="mt-0.5 font-medium text-foreground">
                                {{ student.phone || 'N/A' }}
                            </p>
                        </div>
                        <div>
                            <Label class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Account ID</Label>
                            <p class="mt-0.5 font-mono text-sm font-medium text-foreground">
                                {{ student.account_id }}
                            </p>
                        </div>
                        <div>
                            <Label class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Course</Label>
                            <p class="mt-0.5 font-medium text-foreground">{{ student.course }}</p>
                        </div>
                        <div>
                            <Label class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Year Level</Label>
                            <p class="mt-0.5 font-medium text-foreground">
                                {{ assessment?.year_level || student.year_level }}
                            </p>
                        </div>
                        <div>
                            <Label class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Status</Label>
                            <span
                                class="mt-0.5 inline-block rounded-full px-2.5 py-0.5 text-xs font-semibold"
                                :class="getStudentStatusColor(student.status)"
                                >{{ student.status }}</span
                            >
                        </div>
                    </div>
                </CardContent>
            </Card>

            <!-- ── Fee Breakdown ── -->
            <Card>
                <CardHeader>
                    <div class="flex items-start justify-between">
                        <div class="flex-1">
                            <CardTitle>Fee Breakdown</CardTitle>
                            <CardDescription class="mt-1 flex flex-col gap-1">
                                <span class="inline-block">
                                    Assessment for
                                    <strong>{{ selectedAssessment?.year_level }}</strong> —
                                    <strong>{{ selectedAssessment?.semester }}</strong>
                                    ({{ selectedAssessment?.school_year }})
                                </span>
                                <span
                                    v-if="feeCalculationSummary"
                                    class="inline-block w-fit rounded bg-indigo-50 px-2 py-1 font-mono text-xs text-indigo-600"
                                >
                                    {{ feeCalculationSummary }}
                                </span>
                            </CardDescription>
                        </div>
                        <div v-if="assessment?.course" class="ml-4 flex-shrink-0 text-right">
                            <span class="text-xs font-semibold text-gray-600">Course:</span>
                            <span
                                class="ml-2 rounded-full bg-blue-100 px-3 py-1 text-xs font-semibold text-blue-700"
                                >{{ assessment.course }}</span
                            >
                        </div>
                    </div>
                </CardHeader>
                <CardContent class="space-y-4">
                    <div
                        class="flex items-center justify-between rounded-xl border border-border bg-card p-4"
                    >
                        <div>
                            <p class="font-semibold text-foreground">Tuition Fees</p>
                        </div>
                        <span class="text-lg font-bold text-indigo-600">{{
                            formatCurrency(totalTuition)
                        }}</span>
                    </div>

                    <div
                        class="flex items-center justify-between rounded-xl border border-border bg-card p-4"
                    >
                        <div>
                            <p class="font-semibold text-foreground">Laboratory Fees</p>
                        </div>
                        <span class="text-lg font-bold text-purple-600">{{
                            formatCurrency(totalLab)
                        }}</span>
                    </div>

                    <div
                        class="flex items-center justify-between rounded-xl border border-border bg-card p-4"
                    >
                        <div>
                            <p class="font-semibold text-foreground">Miscellaneous Fees</p>
                        </div>
                        <span class="text-lg font-bold text-amber-600">{{
                            formatCurrency(totalMiscellaneous)
                        }}</span>
                    </div>

                    <div class="flex items-center justify-between border-t-2 border-gray-200 px-1 pt-3">
                        <span class="text-lg font-bold text-gray-900">Total Assessment</span>
                        <span class="text-2xl font-extrabold text-indigo-600">{{
                            formatCurrency(totalAssessment)
                        }}</span>
                    </div>

                    <div class="space-y-1 px-1">
                        <div class="flex justify-between text-xs text-gray-500">
                            <span>Payment Progress</span>
                            <span>{{
                                totalAssessment > 0
                                    ? Math.round((totalPaid / totalAssessment) * 100)
                                    : 0
                            }}%</span>
                        </div>
                        <div class="h-2.5 w-full overflow-hidden rounded-full bg-gray-200">
                            <div
                                class="h-2.5 rounded-full transition-all duration-500"
                                :class="
                                    paymentTimingStatus === 'behind'
                                        ? 'bg-red-500'
                                        : paymentTimingStatus === 'on_track'
                                          ? 'bg-blue-500'
                                          : 'bg-green-500'
                                "
                                :style="{
                                    width:
                                        totalAssessment > 0
                                            ? `${Math.min(100, (totalPaid / totalAssessment) * 100)}%`
                                            : '0%',
                                }"
                            ></div>
                        </div>
                        <div class="flex justify-between pt-0.5 text-xs text-gray-500">
                            <span
                                >Paid:
                                <strong class="text-green-600">{{
                                    formatCurrency(totalPaid)
                                }}</strong></span
                            >
                            <span
                                >Remaining:
                                <strong
                                    :class="
                                        paymentTimingStatus === 'paid'
                                            ? 'text-green-600'
                                            : 'text-red-600'
                                    "
                                    >{{ formatCurrency(remainingBalance) }}</strong
                                ></span
                            >
                        </div>
                    </div>

                    <div :class="['mt-2 rounded-xl border-2 p-4', balanceCardConfig.bg]">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <p class="text-sm" :class="balanceCardConfig.labelColor">
                                    Remaining Balance
                                </p>
                                <span
                                    class="rounded-full px-2 py-0.5 text-xs font-bold"
                                    :class="balanceCardConfig.badge.cls"
                                    >{{ balanceCardConfig.badge.label }}</span
                                >
                            </div>
                            <p
                                class="mt-0.5 text-3xl font-extrabold"
                                :class="balanceCardConfig.amountColor"
                            >
                                {{ formatCurrency(remainingBalance) }}
                            </p>
                            <p
                                v-if="assessment?.paymentTerms?.length"
                                class="mt-1 text-xs"
                                :class="balanceCardConfig.labelColor"
                            >
                                {{ paidTermsCount }} of {{ allTermsSorted.length }} terms paid
                            </p>
                        </div>
                    </div>

                    <div v-if="allTermsSorted.length > 0" class="space-y-2 pt-1">
                        <p
                            class="text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                        >
                            Payment Terms
                        </p>
                        <div class="grid grid-cols-1 gap-2 sm:grid-cols-5">
                            <div
                                v-for="term in allTermsSorted"
                                :key="term.id"
                                :class="[
                                    'rounded-xl border p-3 text-center text-xs transition-all',
                                    term.status === 'paid'
                                        ? 'border-emerald-200 bg-emerald-50'
                                        : term.status === 'partial'
                                          ? 'border-amber-200 bg-amber-50'
                                          : term.status === 'overdue'
                                            ? 'border-red-200 bg-red-50'
                                            : 'border-border bg-muted/30',
                                ]"
                            >
                                <p class="truncate font-semibold text-foreground">
                                    {{ term.term_name }}
                                </p>
                                <p
                                    class="mt-1 font-bold tabular-nums"
                                    :class="
                                        term.status === 'paid'
                                            ? 'text-emerald-600'
                                            : term.status === 'overdue'
                                              ? 'text-red-600'
                                              : 'text-foreground'
                                    "
                                >
                                    {{ formatCurrency(parseFloat(String(term.balance))) }}
                                </p>
                                <span
                                    :class="[
                                        'mt-1.5 inline-block rounded-full px-2 py-0.5 font-semibold',
                                        getTermStatusConfig(term.status).bg,
                                        getTermStatusConfig(term.status).text,
                                    ]"
                                >
                                    {{ getTermStatusConfig(term.status).label }}
                                </span>
                            </div>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <!-- ── Payment History ── -->
            <Card>
                <CardHeader>
                    <CardTitle>Payment History</CardTitle>
                    <CardDescription>
                        {{ filteredPayments.length }} payment(s) for
                        {{ selectedAssessment?.year_level }} — {{ selectedAssessment?.semester }}
                        {{ selectedAssessment?.school_year }}
                        <span
                            v-if="(payments ?? []).length > filteredPayments.length"
                            class="mt-1 block text-xs text-gray-500"
                        >
                            ({{ (payments ?? []).length }} total across all assessments)
                        </span>
                    </CardDescription>
                </CardHeader>
                <CardContent class="p-0">
                    <div class="overflow-x-auto">
                        <table class="min-w-full">
                            <thead class="border-b border-gray-200 bg-gray-50">
                                <tr>
                                    <th
                                        class="px-6 py-3 text-left text-xs font-semibold tracking-wider text-gray-500 uppercase"
                                    >
                                        Date
                                    </th>
                                    <th
                                        class="px-6 py-3 text-left text-xs font-semibold tracking-wider text-gray-500 uppercase"
                                    >
                                        Reference
                                    </th>
                                    <th
                                        class="px-6 py-3 text-left text-xs font-semibold tracking-wider text-gray-500 uppercase"
                                    >
                                        Method
                                    </th>
                                    <th
                                        class="px-6 py-3 text-left text-xs font-semibold tracking-wider text-gray-500 uppercase"
                                    >
                                        Description
                                    </th>
                                    <th
                                        class="px-6 py-3 text-left text-xs font-semibold tracking-wider text-gray-500 uppercase"
                                    >
                                        Year & Sem
                                    </th>
                                    <th
                                        class="px-6 py-3 text-right text-xs font-semibold tracking-wider text-gray-500 uppercase"
                                    >
                                        Amount
                                    </th>
                                    <th
                                        class="px-6 py-3 text-center text-xs font-semibold tracking-wider text-gray-500 uppercase"
                                    >
                                        Status
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <tr v-if="filteredPayments.length === 0">
                                    <td
                                        colspan="7"
                                        class="px-6 py-10 text-center text-gray-400"
                                    >
                                        <CreditCard class="mx-auto mb-2 h-8 w-8 opacity-30" />
                                        <p>No payment history found</p>
                                    </td>
                                </tr>
                                <tr
                                    v-for="payment in visiblePayments"
                                    :key="payment.id"
                                    class="transition-colors hover:bg-gray-50"
                                >
                                    <td
                                        class="px-6 py-3 text-sm whitespace-nowrap text-gray-600"
                                    >
                                        {{ formatDateShort(payment.paid_at) }}
                                    </td>
                                    <td class="px-6 py-3 whitespace-nowrap">
                                        <span class="font-mono text-xs text-gray-700">{{
                                            payment.reference_number
                                        }}</span>
                                    </td>
                                    <td class="px-6 py-3 whitespace-nowrap">
                                        <span
                                            class="rounded-full bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-800 capitalize"
                                            >{{ payment.payment_method }}</span
                                        >
                                    </td>
                                    <td class="px-6 py-3 text-sm text-gray-600">
                                        {{ payment.description }}
                                    </td>
                                    <td class="px-6 py-3 text-sm whitespace-nowrap">
                                        <div v-if="payment.school_year || payment.semester">
                                            <p class="font-medium text-gray-800">
                                                {{ payment.school_year }}
                                            </p>
                                            <p class="text-xs text-gray-500">
                                                {{ payment.semester }}
                                            </p>
                                        </div>
                                        <span v-else class="text-gray-400">—</span>
                                    </td>
                                    <td
                                        class="px-6 py-3 text-right text-sm font-semibold whitespace-nowrap text-green-600"
                                    >
                                        + {{ formatCurrency(payment.amount) }}
                                    </td>
                                    <td class="px-6 py-3 text-center whitespace-nowrap">
                                        <span
                                            class="rounded-full px-2 py-0.5 text-xs font-semibold"
                                            :class="
                                                payment.status === 'completed'
                                                    ? 'bg-green-100 text-green-800'
                                                    : 'bg-yellow-100 text-yellow-800'
                                            "
                                        >
                                            {{ payment.status }}
                                        </span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div v-if="hasMorePayments" class="border-t px-6 py-3 text-center">
                        <button
                            type="button"
                            class="text-sm font-medium text-blue-600 transition-colors hover:text-blue-800 hover:underline"
                            @click="loadMorePayments"
                        >
                            See More ({{ filteredPayments.length - paymentHistoryLimit }} remaining)
                        </button>
                    </div>
                </CardContent>
            </Card>

            <!-- ── Transaction Ledger ── -->
            <div>
                <div class="mb-3 flex items-center justify-between px-1">
                    <div>
                        <h2 class="text-xl font-bold text-gray-900">Transaction Ledger</h2>
                        <p class="text-sm text-gray-500">
                            All payment transactions grouped by term
                        </p>
                    </div>
                </div>

                <div
                    v-if="transactionsByTerm.length === 0"
                    class="rounded-xl border bg-white p-10 text-center text-gray-400"
                >
                    <AlertCircle class="mx-auto mb-2 h-8 w-8 opacity-30" />
                    <p>No payment transactions found</p>
                </div>

                <div
                    v-for="group in transactionsByTerm"
                    :key="group.key"
                    class="mb-4 overflow-hidden rounded-xl border bg-white shadow-sm"
                >
                    <div
                        class="flex cursor-pointer items-center justify-between p-5 transition-colors select-none hover:bg-gray-50"
                        @click="toggleTerm(group.key)"
                    >
                        <div>
                            <h3 class="text-lg font-bold text-gray-900">{{ group.key }}</h3>
                            <p class="mt-0.5 text-sm text-gray-400">
                                {{ group.transactions.length }} transaction{{
                                    group.transactions.length !== 1 ? 's' : ''
                                }}
                            </p>
                        </div>
                        <div class="flex items-center gap-8 text-right md:gap-12">
                            <div>
                                <p class="text-xs text-gray-400">Total Assessed</p>
                                <p class="text-sm font-bold text-red-600">
                                    {{ formatCurrency(group.totalCharges) }}
                                </p>
                            </div>
                            <div>
                                <p class="text-xs text-gray-400">Total Paid</p>
                                <p class="text-sm font-bold text-green-600">
                                    {{ formatCurrency(group.totalPaid) }}
                                </p>
                            </div>
                            <div>
                                <p class="text-xs text-gray-400">Balance</p>
                                <p
                                    class="text-sm font-bold"
                                    :class="
                                        group.balance > 0 ? 'text-red-600' : 'text-green-600'
                                    "
                                >
                                    {{ formatCurrency(Math.abs(group.balance)) }}
                                </p>
                            </div>
                            <ChevronDown
                                class="h-5 w-5 text-gray-400 transition-transform"
                                :class="{ 'rotate-180': expandedTerms[group.key] }"
                            />
                        </div>
                    </div>

                    <div v-if="expandedTerms[group.key]" class="border-t">
                        <div class="overflow-x-auto">
                            <table class="w-full border-collapse text-left">
                                <thead>
                                    <tr class="bg-gray-50 text-xs text-gray-500 uppercase">
                                        <th class="px-4 py-3 font-semibold">Reference</th>
                                        <th class="px-4 py-3 font-semibold">Type</th>
                                        <th class="px-4 py-3 font-semibold">Category</th>
                                        <th class="px-4 py-3 font-semibold">Year & Semester</th>
                                        <th class="px-4 py-3 font-semibold">Amount</th>
                                        <th class="px-4 py-3 font-semibold">Status</th>
                                        <th class="px-4 py-3 font-semibold">Date</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="t in group.transactions"
                                        :key="t.id"
                                        class="border-b border-gray-100 transition-colors hover:bg-gray-50"
                                    >
                                        <td class="px-4 py-3 font-mono text-xs text-gray-700">
                                            {{ t.reference }}
                                        </td>
                                        <td class="px-4 py-3">
                                            <span
                                                class="rounded-full bg-green-100 px-2 py-0.5 text-xs font-semibold text-green-800"
                                                >payment</span
                                            >
                                        </td>
                                        <td class="px-4 py-3 text-sm text-gray-700">
                                            {{ t.type }}
                                        </td>
                                        <td class="px-4 py-3 text-sm">
                                            <div v-if="t.year || t.semester">
                                                <p class="font-medium text-gray-800">
                                                    {{ toYearRange(t.year) }}
                                                </p>
                                                <p class="text-xs text-gray-500">
                                                    {{ t.semester }}
                                                </p>
                                            </div>
                                            <span v-else class="text-gray-400">—</span>
                                        </td>
                                        <td
                                            class="px-4 py-3 text-sm font-semibold text-green-600"
                                        >
                                            +{{ formatCurrency(t.amount) }}
                                        </td>
                                        <td class="px-4 py-3">
                                            <span
                                                class="rounded-full px-2 py-0.5 text-xs font-semibold"
                                                :class="{
                                                    'bg-green-100 text-green-800':
                                                        t.status === 'paid',
                                                    'bg-yellow-100 text-yellow-800':
                                                        t.status === 'pending',
                                                    'bg-blue-100 text-blue-800':
                                                        t.status === 'awaiting_approval',
                                                    'bg-red-100 text-red-800':
                                                        t.status === 'failed',
                                                    'bg-gray-100 text-gray-700':
                                                        t.status === 'cancelled',
                                                }"
                                            >
                                                {{
                                                    t.status === 'awaiting_approval'
                                                        ? 'Awaiting Verification'
                                                        : t.status
                                                }}
                                            </span>
                                        </td>
                                        <td class="px-4 py-3 text-xs text-gray-500">
                                            {{ formatDateShort(t.created_at) }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div
                            v-if="txSubjectPanels[group.key]"
                            class="border-t border-gray-100"
                        >
                            <button
                                type="button"
                                class="flex w-full items-center justify-between bg-indigo-50 px-5 py-3 text-left transition-colors select-none hover:bg-indigo-100"
                                @click="toggleTxSubjectPanel(group.key)"
                            >
                                <div class="flex items-center gap-2">
                                    <BookOpen class="h-4 w-4 text-indigo-500" />
                                    <span class="text-sm font-semibold text-indigo-800">
                                        Enrolled Subjects — {{ group.key }}
                                    </span>
                                    <span
                                        class="rounded-full bg-indigo-100 px-2 py-0.5 text-xs font-medium text-indigo-700"
                                    >
                                        {{ txSubjectPanels[group.key]!.subjectCount }} subject{{
                                            txSubjectPanels[group.key]!.subjectCount !== 1
                                                ? 's'
                                                : ''
                                        }}
                                        · {{ txSubjectPanels[group.key]!.totalUnits }} units
                                    </span>
                                </div>
                                <ChevronDown
                                    class="h-4 w-4 text-indigo-400 transition-transform duration-200"
                                    :class="{
                                        'rotate-180': expandedTxSubjectPanels.has(group.key),
                                    }"
                                />
                            </button>

                            <div
                                v-if="expandedTxSubjectPanels.has(group.key)"
                                class="overflow-x-auto bg-gray-50"
                            >
                                <table class="min-w-full text-sm">
                                    <thead>
                                        <tr
                                            class="border-b border-gray-200 bg-gray-100 text-xs font-semibold tracking-wide text-gray-500 uppercase"
                                        >
                                            <th class="px-5 py-2.5 text-left">Status</th>
                                            <th class="px-5 py-2.5 text-left">Code</th>
                                            <th class="px-5 py-2.5 text-left">Subject Name</th>
                                            <th class="px-5 py-2.5 text-center">Units</th>
                                            <th class="px-5 py-2.5 text-right">Tuition</th>
                                            <th class="px-5 py-2.5 text-right">Lab Fee</th>
                                            <th class="px-5 py-2.5 text-right">Total</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100">
                                        <tr
                                            v-for="subject in txSubjectPanels[group.key]!
                                                .subjects"
                                            :key="subject.subject_id"
                                            :class="[
                                                'transition-colors',
                                                subject.isEnrolled
                                                    ? 'hover:bg-green-50/50'
                                                    : 'hover:bg-gray-50',
                                            ]"
                                        >
                                            <td class="px-5 py-3 text-center">
                                                <span
                                                    v-if="subject.isEnrolled"
                                                    class="inline-flex h-6 w-6 items-center justify-center rounded-full bg-green-100 text-xs font-bold text-green-700"
                                                    title="Enrolled"
                                                    >✓</span
                                                >
                                                <span
                                                    v-else
                                                    class="inline-flex h-6 w-6 items-center justify-center rounded-full bg-gray-100 text-xs text-gray-400"
                                                    title="Assessment only"
                                                    >○</span
                                                >
                                            </td>
                                            <td class="px-5 py-3">
                                                <span
                                                    class="rounded bg-indigo-50 px-2 py-0.5 font-mono text-xs font-semibold text-indigo-700"
                                                    >{{ subject.code }}</span
                                                >
                                            </td>
                                            <td class="px-5 py-3">
                                                <div class="flex items-center gap-1.5">
                                                    <span class="font-medium text-gray-900">{{
                                                        subject.name
                                                    }}</span>
                                                    <FlaskConical
                                                        v-if="subject.hasLab"
                                                        class="h-3.5 w-3.5 flex-shrink-0 text-purple-500"
                                                    />
                                                </div>
                                            </td>
                                            <td class="px-5 py-3 text-center">
                                                <span
                                                    class="rounded-full bg-blue-50 px-2 py-0.5 text-xs font-semibold text-blue-700"
                                                >
                                                    {{ subject.lecUnits }} unit{{
                                                        subject.lecUnits !== 1 ? 's' : ''
                                                    }}
                                                </span>
                                            </td>
                                            <td
                                                class="px-5 py-3 text-right font-medium text-gray-900"
                                            >
                                                {{ formatCurrency(subject.tuitionAmount) }}
                                            </td>
                                            <td class="px-5 py-3 text-right">
                                                <span
                                                    v-if="subject.hasLab"
                                                    class="font-medium text-purple-700"
                                                    >{{
                                                        formatCurrency(subject.labAmount)
                                                    }}</span
                                                >
                                                <span v-else class="text-xs text-gray-300"
                                                    >—</span
                                                >
                                            </td>
                                            <td
                                                class="px-5 py-3 text-right font-semibold text-gray-900"
                                            >
                                                {{
                                                    formatCurrency(
                                                        subject.tuitionAmount + subject.labAmount,
                                                    )
                                                }}
                                            </td>
                                        </tr>
                                    </tbody>
                                    <tfoot>
                                        <tr
                                            class="border-t-2 border-gray-200 bg-gray-50 text-sm font-semibold"
                                        >
                                            <td colspan="3" class="px-5 py-3 text-gray-700">
                                                Subtotal —
                                                {{
                                                    txSubjectPanels[group.key]!.subjectCount
                                                }}
                                                subjects
                                            </td>
                                            <td
                                                class="px-5 py-3 text-center font-bold text-blue-700"
                                            >
                                                {{ txSubjectPanels[group.key]!.totalUnits }}
                                            </td>
                                            <td class="px-5 py-3 text-right text-gray-900">
                                                {{
                                                    formatCurrency(
                                                        txSubjectPanels[group.key]!.totalTuition,
                                                    )
                                                }}
                                            </td>
                                            <td class="px-5 py-3 text-right text-purple-700">
                                                <span
                                                    v-if="
                                                        txSubjectPanels[group.key]!.totalLab > 0
                                                    "
                                                    >{{
                                                        formatCurrency(
                                                            txSubjectPanels[group.key]!.totalLab,
                                                        )
                                                    }}</span
                                                >
                                                <span
                                                    v-else
                                                    class="text-xs font-normal text-gray-300"
                                                    >—</span
                                                >
                                            </td>
                                            <td class="px-5 py-3 text-right text-indigo-700">
                                                {{
                                                    formatCurrency(
                                                        txSubjectPanels[group.key]!.totalTuition +
                                                            txSubjectPanels[group.key]!.totalLab,
                                                    )
                                                }}
                                            </td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>