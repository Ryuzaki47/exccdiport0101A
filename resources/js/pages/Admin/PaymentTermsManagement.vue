<script setup lang="ts">
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { useDataFormatting } from '@/composables/useDataFormatting';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { AlertCircle, Check, Edit2, Layers } from 'lucide-vue-next';
import { computed, ref } from 'vue';
const { formatCurrency } = useDataFormatting();

// ─── Types ──────────────────────────────────────────────────────────────────

interface PaymentTerm {
    id: number;
    term_name: string;
    term_order: number;
    amount: number;
    balance: number;
    due_date: string | null;
    status: string;
    student_id: string;
    student_name: string;
    assessment_id: number;
    user_id: number | null;
}

interface DistinctTerm {
    term_name: string;
    term_order: number;
}

interface Props {
    payment_terms: PaymentTerm[];
    unsetDueDatesCount: number;
    distinctTermNames: DistinctTerm[];
}

// ─── Props / Setup ───────────────────────────────────────────────────────────

const props = defineProps<Props>();

const breadcrumbs = [
    { title: 'Admin', href: route('admin.dashboard') },
    { title: 'Payment Terms Management', href: route('admin.payment-terms.index') },
];

// ─── Filter State ────────────────────────────────────────────────────────────

const filterStudentId = ref('');
const filterStatus = ref('all');
const filterTermName = ref('all');

const filteredTerms = computed(() => {
    let result = props.payment_terms;

    if (filterStudentId.value.trim()) {
        const q = filterStudentId.value.toLowerCase();
        result = result.filter((t) => t.student_id.toLowerCase().includes(q) || t.student_name.toLowerCase().includes(q));
    }

    if (filterStatus.value === 'unset') {
        result = result.filter((t) => !t.due_date);
    } else if (filterStatus.value === 'set') {
        result = result.filter((t) => !!t.due_date);
    }

    if (filterTermName.value !== 'all') {
        result = result.filter((t) => t.term_name === filterTermName.value);
    }

    return result;
});

const unsetTermsCount = computed(() => props.payment_terms.filter((t) => !t.due_date).length);

// ─── Single Edit Dialog ──────────────────────────────────────────────────────

const showEditDialog = ref(false);
const selectedTerm = ref<PaymentTerm | null>(null);

// Using useForm for proper Inertia error handling
const editForm = useForm({ due_date: '' });

const openEditDialog = (term: PaymentTerm) => {
    selectedTerm.value = term;
    editForm.due_date = term.due_date ? term.due_date.split('T')[0] : '';
    // Clear any previous errors
    editForm.clearErrors();
    showEditDialog.value = true;
};

const closeEditDialog = () => {
    showEditDialog.value = false;
    editForm.reset();
    editForm.clearErrors();
    selectedTerm.value = null;
};

const submitSingleDueDate = () => {
    if (!selectedTerm.value || !editForm.due_date) return;

    editForm.post(route('admin.payment-terms.update-due-date', selectedTerm.value.id), {
        onSuccess: () => {
            closeEditDialog();
        },
    });
};

// ─── Bulk Update Dialog ──────────────────────────────────────────────────────

const showBulkDialog = ref(false);

const bulkForm = useForm({
    term_name: '',
    due_date: '',
});

const bulkTargetCount = computed(() => {
    if (!bulkForm.term_name) return 0;
    return props.payment_terms.filter((t) => t.term_name === bulkForm.term_name).length;
});

const openBulkDialog = () => {
    bulkForm.reset();
    bulkForm.clearErrors();
    showBulkDialog.value = true;
};

const closeBulkDialog = () => {
    showBulkDialog.value = false;
    bulkForm.reset();
    bulkForm.clearErrors();
};

const submitBulkDueDate = () => {
    if (!bulkForm.term_name || !bulkForm.due_date) return;

    bulkForm.post(route('admin.payment-terms.bulk-due-date'), {
        onSuccess: () => {
            closeBulkDialog();
        },
    });
};

// ─── Formatting Helpers ──────────────────────────────────────────────────────

const formatDate = (date: string | null) => {
    if (!date) return '—';
    return new Date(date).toLocaleDateString('en-PH', { dateStyle: 'medium' });
};

// Returns a Tailwind class string for the due date badge
const dueDateClass = (term: PaymentTerm) => {
    if (!term.due_date) return 'bg-amber-100 text-amber-800';

    const today = new Date();
    const due = new Date(term.due_date);
    const diffDays = Math.ceil((due.getTime() - today.getTime()) / 86_400_000);

    if (diffDays < 0) return 'bg-red-100 text-red-800'; // overdue
    if (diffDays <= 7) return 'bg-orange-100 text-orange-800'; // within a week
    return 'bg-green-100 text-green-800'; // plenty of time
};

const dueDateLabel = (term: PaymentTerm) => {
    if (!term.due_date) return 'Missing';

    const today = new Date();
    const due = new Date(term.due_date);
    const diffDays = Math.ceil((due.getTime() - today.getTime()) / 86_400_000);

    if (diffDays < 0) return `${Math.abs(diffDays)}d overdue`;
    if (diffDays === 0) return 'Due today';
    if (diffDays <= 7) return `Due in ${diffDays}d`;
    return 'Set';
};
</script>

<template>
    <Head title="Payment Terms Management" />

    <AppLayout>
        <div class="w-full space-y-6 p-6">
            <Breadcrumbs :items="breadcrumbs" />

            <!-- Page Header -->
            <div class="flex items-start justify-between">
                <div>
                    <h1 class="text-3xl font-bold text-gray-900">Payment Terms Due Date Management</h1>
                    <p class="mt-1 text-gray-600">
                        Set due dates for individual or all same-named payment terms. Students are notified automatically.
                    </p>
                </div>
                <!-- Bulk Update Button -->
                <Button @click="openBulkDialog" class="flex items-center gap-2 bg-blue-600 text-white hover:bg-blue-700">
                    <Layers :size="16" />
                    Bulk Update by Term
                </Button>
            </div>

            <!-- Alert: missing due dates -->
            <div v-if="unsetTermsCount > 0" class="flex gap-4 rounded-lg border border-amber-200 bg-amber-50 p-4">
                <AlertCircle class="flex-shrink-0 text-amber-600" :size="24" />
                <div>
                    <h3 class="mb-1 font-semibold text-amber-900">{{ unsetTermsCount }} Payment Terms Missing Due Dates</h3>
                    <p class="text-sm text-amber-800">
                        Students cannot receive payment reminders until due dates are set. Use
                        <strong>Bulk Update by Term</strong> to set all at once.
                    </p>
                </div>
            </div>

            <!-- Summary Cards -->
            <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                <Card>
                    <CardContent class="pt-6">
                        <p class="text-sm text-gray-600">Total Payment Terms</p>
                        <p class="text-3xl font-bold text-blue-600">{{ props.payment_terms.length }}</p>
                    </CardContent>
                </Card>
                <Card>
                    <CardContent class="pt-6">
                        <p class="text-sm text-gray-600">Missing Due Dates</p>
                        <p class="text-3xl font-bold text-amber-600">{{ unsetTermsCount }}</p>
                    </CardContent>
                </Card>
                <Card>
                    <CardContent class="pt-6">
                        <p class="text-sm text-gray-600">Showing (filtered)</p>
                        <p class="text-3xl font-bold text-gray-700">{{ filteredTerms.length }}</p>
                    </CardContent>
                </Card>
            </div>

            <!-- Filters -->
            <div class="flex flex-col gap-3 md:flex-row">
                <input
                    v-model="filterStudentId"
                    type="text"
                    placeholder="Search by student name or ID..."
                    class="flex-1 rounded-lg border border-gray-300 px-4 py-2 text-sm focus:border-transparent focus:ring-2 focus:ring-blue-500"
                />

                <select
                    v-model="filterTermName"
                    class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm focus:border-transparent focus:ring-2 focus:ring-blue-500"
                >
                    <option value="all">All Term Names</option>
                    <option v-for="dt in props.distinctTermNames" :key="dt.term_name" :value="dt.term_name">
                        {{ dt.term_order }}. {{ dt.term_name }}
                    </option>
                </select>

                <select
                    v-model="filterStatus"
                    class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm focus:border-transparent focus:ring-2 focus:ring-blue-500"
                >
                    <option value="all">All Statuses</option>
                    <option value="unset">Missing Due Dates</option>
                    <option value="set">Has Due Dates</option>
                </select>
            </div>

            <!-- Payment Terms List -->
            <div class="space-y-3">
                <div v-if="filteredTerms.length === 0" class="py-12 text-center text-gray-400">No payment terms found matching your filters.</div>

                <Card v-for="term in filteredTerms" :key="term.id">
                    <CardContent class="pt-6">
                        <div class="flex items-center justify-between gap-4">
                            <div class="flex-1 space-y-2">
                                <!-- Student name + badge -->
                                <div class="flex items-center gap-3">
                                    <h3 class="font-semibold text-gray-900">
                                        {{ term.student_name }}
                                        <span class="font-normal text-gray-500">({{ term.student_id }})</span>
                                    </h3>
                                    <span class="rounded-full px-2 py-1 text-xs font-medium" :class="dueDateClass(term)">
                                        {{ dueDateLabel(term) }}
                                    </span>
                                </div>

                                <!-- Term name + amount -->
                                <p class="text-sm text-gray-600">
                                    <span class="font-medium">{{ term.term_order }}. {{ term.term_name }}</span>
                                    — {{ formatCurrency(term.amount) }} total
                                    <span v-if="term.balance < term.amount" class="ml-2 text-green-700">
                                        (Balance: {{ formatCurrency(term.balance) }})
                                    </span>
                                </p>

                                <!-- Due date display -->
                                <div class="flex items-center gap-2 text-sm">
                                    <span class="text-gray-500">Due Date:</span>
                                    <span class="font-mono font-semibold" :class="term.due_date ? 'text-gray-900' : 'text-amber-600'">
                                        {{ formatDate(term.due_date) }}
                                    </span>
                                </div>
                            </div>

                            <Button @click="openEditDialog(term)" variant="outline" size="sm" class="flex-shrink-0">
                                <Edit2 :size="14" class="mr-1.5" />
                                {{ term.due_date ? 'Change' : 'Set Date' }}
                            </Button>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </div>

        <!-- ────────────────────────────────────────────────────────────────── -->
        <!-- Single Term Due Date Dialog                                        -->
        <!-- ────────────────────────────────────────────────────────────────── -->
        <Dialog
            :open="showEditDialog"
            @update:open="
                (v) => {
                    if (!v) closeEditDialog();
                }
            "
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Set Payment Term Due Date</DialogTitle>
                    <DialogDescription v-if="selectedTerm"> {{ selectedTerm.student_name }} — {{ selectedTerm.term_name }} </DialogDescription>
                </DialogHeader>

                <div class="mt-4 space-y-4">
                    <div>
                        <label class="mb-2 block text-sm font-medium text-gray-700">Due Date *</label>
                        <input
                            v-model="editForm.due_date"
                            type="date"
                            class="w-full rounded-lg border border-gray-300 px-4 py-2 focus:border-transparent focus:ring-2 focus:ring-blue-500"
                        />
                        <p v-if="editForm.errors.due_date" class="mt-1.5 text-sm text-red-600">
                            {{ editForm.errors.due_date }}
                        </p>
                    </div>

                    <div v-if="selectedTerm" class="space-y-1 rounded-lg border border-blue-100 bg-blue-50 p-3 text-sm text-blue-900">
                        <p><strong>Amount:</strong> {{ formatCurrency(selectedTerm.amount) }}</p>
                        <p><strong>Balance:</strong> {{ formatCurrency(selectedTerm.balance) }}</p>
                        <p class="mt-2 text-xs text-blue-700">
                            💡 A payment due notification will be sent to this student automatically. It will appear on their dashboard 7 days before
                            the due date.
                        </p>
                    </div>

                    <div class="flex justify-end gap-3 pt-2">
                        <Button variant="outline" @click="closeEditDialog" :disabled="editForm.processing"> Cancel </Button>
                        <Button
                            @click="submitSingleDueDate"
                            :disabled="editForm.processing || !editForm.due_date"
                            class="bg-blue-600 text-white hover:bg-blue-700"
                        >
                            <Check :size="16" class="mr-2" />
                            {{ editForm.processing ? 'Saving…' : 'Set Due Date' }}
                        </Button>
                    </div>
                </div>
            </DialogContent>
        </Dialog>

        <!-- ────────────────────────────────────────────────────────────────── -->
        <!-- Bulk Update Dialog                                                 -->
        <!-- ────────────────────────────────────────────────────────────────── -->
        <Dialog
            :open="showBulkDialog"
            @update:open="
                (v) => {
                    if (!v) closeBulkDialog();
                }
            "
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Bulk Update Due Dates by Term Name</DialogTitle>
                    <DialogDescription> Apply a single due date to all students that share the same payment term name. </DialogDescription>
                </DialogHeader>

                <div class="mt-4 space-y-4">
                    <!-- Term Name Selector -->
                    <div>
                        <label class="mb-2 block text-sm font-medium text-gray-700">Term Name *</label>
                        <select
                            v-model="bulkForm.term_name"
                            class="w-full rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm focus:border-transparent focus:ring-2 focus:ring-blue-500"
                        >
                            <option value="">— Select a term —</option>
                            <option v-for="dt in props.distinctTermNames" :key="dt.term_name" :value="dt.term_name">
                                {{ dt.term_order }}. {{ dt.term_name }}
                            </option>
                        </select>
                        <p v-if="bulkForm.errors.term_name" class="mt-1.5 text-sm text-red-600">
                            {{ bulkForm.errors.term_name }}
                        </p>
                    </div>

                    <!-- Due Date -->
                    <div>
                        <label class="mb-2 block text-sm font-medium text-gray-700">Due Date *</label>
                        <input
                            v-model="bulkForm.due_date"
                            type="date"
                            class="w-full rounded-lg border border-gray-300 px-4 py-2 focus:border-transparent focus:ring-2 focus:ring-blue-500"
                        />
                        <p v-if="bulkForm.errors.due_date" class="mt-1.5 text-sm text-red-600">
                            {{ bulkForm.errors.due_date }}
                        </p>
                    </div>

                    <!-- Preview count -->
                    <div
                        v-if="bulkForm.term_name"
                        class="space-y-1 rounded-lg border p-3 text-sm"
                        :class="bulkTargetCount > 0 ? 'border-blue-100 bg-blue-50 text-blue-900' : 'border-amber-100 bg-amber-50 text-amber-900'"
                    >
                        <p>
                            <strong>{{ bulkTargetCount }}</strong>
                            {{ bulkTargetCount === 1 ? 'student' : 'students' }} will be affected.
                        </p>
                        <p class="text-xs opacity-80">Each student with a "{{ bulkForm.term_name }}" term will receive a payment due notification.</p>
                    </div>

                    <div class="flex justify-end gap-3 pt-2">
                        <Button variant="outline" @click="closeBulkDialog" :disabled="bulkForm.processing"> Cancel </Button>
                        <Button
                            @click="submitBulkDueDate"
                            :disabled="bulkForm.processing || !bulkForm.term_name || !bulkForm.due_date"
                            class="bg-blue-600 text-white hover:bg-blue-700"
                        >
                            <Check :size="16" class="mr-2" />
                            {{ bulkForm.processing ? 'Applying…' : `Apply to ${bulkTargetCount} Terms` }}
                        </Button>
                    </div>
                </div>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
