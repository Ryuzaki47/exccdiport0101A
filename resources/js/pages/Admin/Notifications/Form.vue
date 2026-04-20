<script setup lang="ts">
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import NotificationPreview from '@/components/NotificationPreview.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, ToggleLeft, ToggleRight } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

interface Student {
    id: number;
    name: string;
    email: string;
}

interface PaymentTerm {
    id: number;
    term_name: string;
    term_order: number;
}

interface Props {
    notification?: {
        id: number;
        title: string;
        message: string;
        type?: string;
        target_role: string;
        start_date: string;
        end_date: string;
        due_date?: string | null;
        payment_term_id?: number | null;
        user_id?: number | null;
        is_active: boolean;
        term_ids?: number[] | null;
        target_term_name?: string | null;
        trigger_days_before_due?: number | null;
    };
    students?: Student[];
    paymentTerms?: PaymentTerm[];
}

const props = withDefaults(defineProps<Props>(), {
    notification: undefined,
    students: () => [],
    paymentTerms: () => [],
});

const isEditing = computed(() => !!props.notification?.id);
const searchQuery = ref('');
const termSelectionMode = ref<'none' | 'by_name' | 'by_id'>(
    props.notification?.target_term_name ? 'by_name' : props.notification?.term_ids?.length ? 'by_id' : 'none',
);

const formatDateForInput = (dateString: string | undefined | null): string => {
    if (!dateString) return '';
    return dateString.split('T')[0];
};

const form = useForm({
    title: props.notification?.title || '',
    message: props.notification?.message || '',
    type: props.notification?.type || 'general',
    target_role: props.notification?.target_role || 'student',
    start_date: formatDateForInput(props.notification?.start_date),
    end_date: formatDateForInput(props.notification?.end_date),
    due_date: formatDateForInput(props.notification?.due_date), // ← NEW
    payment_term_id: props.notification?.payment_term_id || null, // ← NEW
    user_id: props.notification?.user_id || null,
    is_active: props.notification?.is_active !== false,
    term_ids: props.notification?.term_ids || [],
    target_term_name: props.notification?.target_term_name || '',
    trigger_days_before_due: props.notification?.trigger_days_before_due || null,
});

// When type changes away from payment_due, clear due_date
watch(
    () => form.type,
    (newType) => {
        if (newType !== 'payment_due') {
            form.due_date = '';
            form.payment_term_id = null;
        }
    },
);

const submit = () => {
    if (termSelectionMode.value === 'none') {
        form.term_ids = [];
        form.target_term_name = '';
        form.trigger_days_before_due = null;
    } else if (termSelectionMode.value === 'by_name') {
        form.term_ids = [];
    } else if (termSelectionMode.value === 'by_id') {
        form.target_term_name = '';
    }

    if (isEditing.value && props.notification?.id) {
        form.put(route('admin.notifications.update', props.notification.id));
    } else {
        form.post(route('admin.notifications.store'));
    }
};

const roleOptions = [
    { value: 'student', label: 'All Students' },
    { value: 'accounting', label: 'Accounting Staff' },
    { value: 'admin', label: 'Admins' },
    { value: 'all', label: 'Everyone' },
];

const typeOptions = [
    { value: 'general', label: '📢 General Notification' },
    { value: 'payment_due', label: '💳 Payment Due Reminder' },
    { value: 'payment_approved', label: '✅ Payment Approved' },
    { value: 'payment_rejected', label: '❌ Payment Rejected' },
];

const messages: Record<string, string> = {
    student: 'This notification will be sent to all students.',
    accounting: 'This notification will be sent to accounting staff.',
    admin: 'This notification will be sent to admin users.',
    all: 'This notification will be sent to all users in the system.',
};

const filteredStudents = computed(() => {
    if (!searchQuery.value.trim()) return props.students;
    const query = searchQuery.value.toLowerCase();
    return props.students.filter((s) => s.name.toLowerCase().includes(query) || s.email.toLowerCase().includes(query));
});

const selectedStudent = computed(() => {
    if (!form || form.user_id === null || form.user_id === undefined) return undefined;
    return props.students.find((s) => s.id === form.user_id);
});

const breadcrumbs = [
    { title: 'Admin', href: route('admin.dashboard') },
    { title: 'Notifications', href: route('admin.notifications.index') },
    {
        title: isEditing.value ? `Edit: ${props.notification?.title ?? 'Notification'}` : 'Create Notification',
        href: isEditing.value ? route('admin.notifications.edit', props.notification?.id) : route('admin.notifications.create'),
    },
];
</script>

<template>
    <Head :title="isEditing ? 'Edit Notification' : 'Create Notification'" />

    <AppLayout>
        <div class="w-full p-6">
            <Breadcrumbs :items="breadcrumbs" />

            <div class="max-w-7xl space-y-6">
                <!-- Header -->
                <div class="mb-8 flex items-start justify-between">
                    <div class="flex items-center gap-4">
                        <Link :href="route('admin.notifications.index')">
                            <Button variant="ghost" size="icon" class="h-10 w-10">
                                <ArrowLeft class="h-5 w-5" />
                            </Button>
                        </Link>
                        <div>
                            <h1 class="text-3xl font-bold text-gray-900">
                                {{ isEditing ? 'Edit Notification' : 'Create Notification' }}
                            </h1>
                            <p class="mt-1 text-sm text-gray-600">
                                {{ isEditing ? 'Update notification details' : 'Set up a new notification for students to see on their dashboard' }}
                            </p>
                        </div>
                    </div>
                    <div v-if="isEditing" class="text-right">
                        <div
                            class="inline-flex items-center gap-2 rounded-lg px-4 py-2"
                            :class="form.is_active ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'"
                        >
                            <span class="text-sm font-medium">{{ form.is_active ? '✓ Active' : '○ Inactive' }}</span>
                        </div>
                    </div>
                </div>

                <!-- Main Form Grid -->
                <div class="grid grid-cols-3 gap-8">
                    <!-- Left Column: Form (2/3 width) -->
                    <div class="col-span-2 space-y-6">
                        <Card>
                            <CardHeader>
                                <CardTitle>📝 Notification Content</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <form class="space-y-6">
                                    <!-- Title -->
                                    <div>
                                        <label class="mb-2 block text-sm font-semibold text-gray-900">Notification Title *</label>
                                        <input
                                            v-model="form.title"
                                            type="text"
                                            placeholder="e.g., Midterm Payment Due Reminder"
                                            class="w-full rounded-lg border border-gray-300 px-4 py-3 focus:border-transparent focus:ring-2 focus:ring-blue-500"
                                            required
                                        />
                                        <p v-if="form.errors.title" class="mt-1 text-sm text-red-600">{{ form.errors.title }}</p>
                                    </div>

                                    <!-- Message -->
                                    <div>
                                        <label class="mb-2 block text-sm font-semibold text-gray-900">Message Content</label>
                                        <textarea
                                            v-model="form.message"
                                            placeholder="Enter your notification message. Include payment amount, deadline, and payment instructions."
                                            class="h-40 w-full resize-none rounded-lg border border-gray-300 px-4 py-3 focus:border-transparent focus:ring-2 focus:ring-blue-500"
                                        >
                                        </textarea>
                                        <p class="mt-1 text-xs text-gray-500">{{ form.message.length }} characters</p>
                                        <p v-if="form.errors.message" class="mt-1 text-sm text-red-600">{{ form.errors.message }}</p>
                                    </div>

                                    <!-- Notification Type -->
                                    <div>
                                        <label class="mb-2 block text-sm font-semibold text-gray-900">Notification Type</label>
                                        <select
                                            v-model="form.type"
                                            class="w-full rounded-lg border border-gray-300 px-4 py-3 focus:border-transparent focus:ring-2 focus:ring-blue-500"
                                        >
                                            <option v-for="option in typeOptions" :key="option.value" :value="option.value">
                                                {{ option.label }}
                                            </option>
                                        </select>
                                        <p v-if="form.errors.type" class="mt-1 text-sm text-red-600">{{ form.errors.type }}</p>
                                    </div>

                                    <!-- ── Payment Due Date (shown only for payment_due type) ───── -->
                                    <div v-if="form.type === 'payment_due'" class="rounded-lg border border-amber-200 bg-amber-50 p-4">
                                        <h4 class="mb-3 text-sm font-semibold text-amber-900">💳 Payment Due Date</h4>
                                        <p class="mb-3 text-xs text-amber-700">
                                            Set the actual payment deadline. This is displayed as a colour-coded chip on the student dashboard (red =
                                            urgent, amber = soon, green = plenty of time). It is separate from the notification's visibility end date
                                            below.
                                        </p>
                                        <div>
                                            <label class="mb-1 block text-sm font-medium text-gray-700">Payment Due Date</label>
                                            <input
                                                v-model="form.due_date"
                                                type="date"
                                                class="w-full rounded-lg border border-gray-300 px-4 py-2 focus:border-transparent focus:ring-2 focus:ring-amber-500"
                                            />
                                            <p class="mt-1 text-xs text-gray-500">
                                                Leave blank if no specific payment deadline applies to this notification.
                                            </p>
                                            <p v-if="form.errors.due_date" class="mt-1 text-sm text-red-600">{{ form.errors.due_date }}</p>
                                        </div>
                                    </div>

                                    <!-- Date Range (Visibility Window) -->
                                    <div class="grid grid-cols-2 gap-4">
                                        <div>
                                            <label class="mb-2 block text-sm font-semibold text-gray-900">Start Date *</label>
                                            <input
                                                v-model="form.start_date"
                                                type="date"
                                                class="w-full rounded-lg border border-gray-300 px-4 py-3 focus:border-transparent focus:ring-2 focus:ring-blue-500"
                                                required
                                            />
                                            <p class="mt-1 text-xs text-gray-500">When this notification becomes visible</p>
                                            <p v-if="form.errors.start_date" class="mt-1 text-sm text-red-600">{{ form.errors.start_date }}</p>
                                        </div>
                                        <div>
                                            <label class="mb-2 block text-sm font-semibold text-gray-900">End Date (Optional)</label>
                                            <input
                                                v-model="form.end_date"
                                                type="date"
                                                class="w-full rounded-lg border border-gray-300 px-4 py-3 focus:border-transparent focus:ring-2 focus:ring-blue-500"
                                            />
                                            <p class="mt-1 text-xs text-gray-500">Leave empty for ongoing notifications</p>
                                            <p v-if="form.errors.end_date" class="mt-1 text-sm text-red-600">{{ form.errors.end_date }}</p>
                                        </div>
                                    </div>
                                </form>
                            </CardContent>
                        </Card>

                        <!-- Target & Audience -->
                        <Card>
                            <CardHeader>
                                <CardTitle>👥 Target Audience</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div class="space-y-6">
                                    <!-- Target Role -->
                                    <div>
                                        <label class="mb-2 block text-sm font-semibold text-gray-900">Who should see this? *</label>
                                        <select
                                            v-model="form.target_role"
                                            class="w-full rounded-lg border border-gray-300 px-4 py-3 focus:border-transparent focus:ring-2 focus:ring-blue-500"
                                            required
                                        >
                                            <option value="">-- Select Audience --</option>
                                            <option v-for="option in roleOptions" :key="option.value" :value="option.value">
                                                {{ option.label }}
                                            </option>
                                        </select>
                                        <p class="mt-2 rounded border border-blue-200 bg-blue-50 p-3 text-xs text-gray-500">
                                            {{ messages[form.target_role] || 'Select an audience' }}
                                        </p>
                                        <p v-if="form.errors.target_role" class="mt-1 text-sm text-red-600">{{ form.errors.target_role }}</p>
                                    </div>

                                    <!-- Specific Student Selector -->
                                    <div v-if="form.target_role === 'student'">
                                        <label class="mb-2 block text-sm font-semibold text-gray-900"> Send to Specific Student (Optional) </label>
                                        <p class="mb-2 text-xs text-gray-600">
                                            Leave empty to send to <strong>all matching students</strong>. Select a student for a
                                            <strong>personal notification</strong> only that student will see.
                                        </p>

                                        <div class="mb-3">
                                            <input
                                                v-model="searchQuery"
                                                type="text"
                                                placeholder="Search by name or email"
                                                class="w-full rounded-lg border border-gray-300 px-4 py-3 focus:border-transparent focus:ring-2 focus:ring-blue-500"
                                            />
                                        </div>

                                        <div v-if="selectedStudent" class="mb-3 rounded-lg border border-blue-200 bg-blue-50 p-4">
                                            <div class="flex items-center justify-between">
                                                <div>
                                                    <p class="font-medium text-gray-900">{{ selectedStudent.name }}</p>
                                                    <p class="text-sm text-gray-600">{{ selectedStudent.email }}</p>
                                                </div>
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="sm"
                                                    @click="form.user_id = null"
                                                    class="text-red-600 hover:text-red-700"
                                                >
                                                    Clear
                                                </Button>
                                            </div>
                                        </div>

                                        <div
                                            v-if="!selectedStudent && filteredStudents.length > 0"
                                            class="max-h-64 overflow-y-auto rounded-lg border border-gray-300"
                                        >
                                            <div
                                                v-for="student in filteredStudents"
                                                :key="student.id"
                                                @click="
                                                    form.user_id = student.id;
                                                    searchQuery = '';
                                                "
                                                class="cursor-pointer border-b border-gray-200 p-4 last:border-b-0 hover:bg-blue-50"
                                            >
                                                <p class="font-medium text-gray-900">{{ student.name }}</p>
                                                <p class="text-sm text-gray-600">{{ student.email }}</p>
                                            </div>
                                        </div>

                                        <p v-if="form.errors.user_id" class="mt-1 text-sm text-red-600">{{ form.errors.user_id }}</p>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>

                        <!-- Term-Based Scheduling -->
                        <Card v-if="form.target_role === 'student'">
                            <CardHeader>
                                <CardTitle>📅 Term-Based Filtering (Optional)</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div class="space-y-5">
                                    <p class="text-xs text-gray-600">
                                        Limit this notification to students who have a specific payment term.
                                        <strong>Example:</strong> Setting "Midterm" means only students with a Midterm payment term on their
                                        assessment will see this notification — perfect for "Midterm payment is due" announcements.
                                    </p>

                                    <!-- Selection Mode -->
                                    <div>
                                        <label class="mb-2 block text-sm font-semibold text-gray-900">Term filter type</label>
                                        <div class="space-y-2">
                                            <label class="flex cursor-pointer items-center gap-3">
                                                <input
                                                    v-model="termSelectionMode"
                                                    type="radio"
                                                    value="none"
                                                    class="h-4 w-4 border-gray-300 focus:ring-2 focus:ring-blue-500"
                                                />
                                                <span class="text-sm text-gray-700">No filter — show to all matching students</span>
                                            </label>
                                            <label class="flex cursor-pointer items-center gap-3">
                                                <input
                                                    v-model="termSelectionMode"
                                                    type="radio"
                                                    value="by_name"
                                                    class="h-4 w-4 border-gray-300 focus:ring-2 focus:ring-blue-500"
                                                />
                                                <span class="text-sm text-gray-700">By term name (e.g., "Midterm", "Prelim")</span>
                                            </label>
                                            <label class="flex cursor-pointer items-center gap-3">
                                                <input
                                                    v-model="termSelectionMode"
                                                    type="radio"
                                                    value="by_id"
                                                    class="h-4 w-4 border-gray-300 focus:ring-2 focus:ring-blue-500"
                                                />
                                                <span class="text-sm text-gray-700">By specific payment term IDs</span>
                                            </label>
                                        </div>
                                    </div>

                                    <!-- By Term Name -->
                                    <div v-if="termSelectionMode === 'by_name'">
                                        <label class="mb-2 block text-sm font-semibold text-gray-900">Which term? *</label>
                                        <select
                                            v-model="form.target_term_name"
                                            class="w-full rounded-lg border border-gray-300 px-4 py-3 focus:border-transparent focus:ring-2 focus:ring-blue-500"
                                        >
                                            <option value="">-- Select a Term --</option>
                                            <option value="Upon Registration">Upon Registration</option>
                                            <option value="Prelim">Prelim</option>
                                            <option value="Midterm">Midterm</option>
                                            <option value="Semi-Final">Semi-Final</option>
                                            <option value="Final">Final</option>
                                        </select>
                                        <p class="mt-1 text-xs text-gray-500">
                                            Only students who have this term in their assessment will see the notification.
                                        </p>
                                        <p v-if="form.errors.target_term_name" class="mt-1 text-sm text-red-600">
                                            {{ form.errors.target_term_name }}
                                        </p>
                                    </div>

                                    <!-- By Specific IDs -->
                                    <div v-if="termSelectionMode === 'by_id'">
                                        <label class="mb-2 block text-sm font-semibold text-gray-900">Select Payment Terms *</label>
                                        <div class="max-h-48 space-y-2 overflow-y-auto rounded-lg border border-gray-300 p-4">
                                            <div v-if="paymentTerms.length === 0" class="text-sm text-gray-500">No payment terms available</div>
                                            <label v-for="term in paymentTerms" :key="term.id" class="flex cursor-pointer items-center gap-3">
                                                <input
                                                    type="checkbox"
                                                    :value="term.id"
                                                    v-model="form.term_ids"
                                                    class="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-2 focus:ring-blue-500"
                                                />
                                                <span class="text-sm text-gray-700">{{ term.term_name }}</span>
                                            </label>
                                        </div>
                                        <p v-if="form.errors['term_ids.*']" class="mt-1 text-sm text-red-600">{{ form.errors['term_ids.*'] }}</p>
                                    </div>

                                    <!-- Trigger Days Before Due -->
                                    <div v-if="termSelectionMode !== 'none'">
                                        <label class="mb-2 block text-sm font-semibold text-gray-900">
                                            Show only N days before due date (Optional)
                                        </label>
                                        <input
                                            v-model.number="form.trigger_days_before_due"
                                            type="number"
                                            placeholder="e.g., 7 — show 7 days before due date"
                                            min="0"
                                            max="90"
                                            class="w-full rounded-lg border border-gray-300 px-4 py-3 focus:border-transparent focus:ring-2 focus:ring-blue-500"
                                        />
                                        <p class="mt-1 text-xs text-gray-500">
                                            Leave blank to always show this notification regardless of due date proximity.
                                        </p>
                                        <p v-if="form.errors.trigger_days_before_due" class="mt-1 text-sm text-red-600">
                                            {{ form.errors.trigger_days_before_due }}
                                        </p>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                    </div>

                    <!-- Right Column: Sidebar -->
                    <div class="col-span-1 space-y-6">
                        <!-- Activation Toggle -->
                        <Card class="border-2" :class="form.is_active ? 'border-green-200 bg-green-50' : 'border-gray-200'">
                            <CardHeader>
                                <CardTitle class="text-sm">Activation Status</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div class="space-y-4">
                                    <button
                                        type="button"
                                        @click="form.is_active = !form.is_active"
                                        class="flex w-full items-center justify-center gap-3 rounded-lg px-4 py-4 transition"
                                        :class="
                                            form.is_active ? 'bg-green-500 text-white hover:bg-green-600' : 'bg-gray-300 text-white hover:bg-gray-400'
                                        "
                                    >
                                        <component :is="form.is_active ? ToggleRight : ToggleLeft" class="h-6 w-6" />
                                        <span class="font-semibold">
                                            {{ form.is_active ? 'Notification Active' : 'Notification Inactive' }}
                                        </span>
                                    </button>
                                    <div class="rounded-lg p-3" :class="form.is_active ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'">
                                        <p class="text-xs font-medium">
                                            <span v-if="form.is_active">✓ Students will see this notification</span>
                                            <span v-else>○ Students will NOT see this notification</span>
                                        </p>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>

                        <!-- Preview -->
                        <NotificationPreview
                            :title="form.title"
                            :message="form.message"
                            :start-date="form.start_date"
                            :end-date="form.end_date"
                            :target-role="form.target_role"
                            :selected-student-email="selectedStudent?.email"
                        />

                        <!-- Summary of what students will see -->
                        <Card v-if="form.type === 'payment_due' && form.due_date" class="border border-amber-200 bg-amber-50">
                            <CardHeader>
                                <CardTitle class="text-sm text-amber-900">📋 What students will see</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div class="space-y-2 text-xs text-amber-800">
                                    <p><strong>Title:</strong> {{ form.title || '(no title)' }}</p>
                                    <p><strong>Due date chip:</strong> {{ form.due_date }}</p>
                                    <p v-if="form.target_term_name">
                                        <strong>Visible to:</strong> Students with {{ form.target_term_name }} term only
                                    </p>
                                    <p v-else-if="form.user_id">
                                        <strong>Visible to:</strong> {{ selectedStudent?.name ?? 'Selected student' }} only
                                    </p>
                                    <p v-else><strong>Visible to:</strong> All {{ form.target_role }} users</p>
                                </div>
                            </CardContent>
                        </Card>

                        <!-- Tips -->
                        <Card>
                            <CardHeader>
                                <CardTitle class="text-sm">💡 Tips</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <ul class="space-y-2 text-xs text-gray-700">
                                    <li>
                                        ✓ For "Midterm payment due" — set type to <strong>Payment Due</strong>, select term filter
                                        <strong>Midterm</strong>, and set a <strong>Payment Due Date</strong>
                                    </li>
                                    <li>✓ The due date chip appears as red (≤7 days), amber (≤14 days), or green on the student's dashboard</li>
                                    <li>✓ General announcements like "Enrollment open" — leave term filter as None</li>
                                    <li>✓ Remember to <strong>Activate</strong> the notification before saving</li>
                                </ul>
                            </CardContent>
                        </Card>
                    </div>
                </div>

                <!-- Actions -->
                <div class="mt-8 flex justify-end gap-3 border-t border-gray-300 pt-6">
                    <Link :href="route('admin.notifications.index')">
                        <Button type="button" variant="outline" class="px-6">Cancel</Button>
                    </Link>
                    <Button type="submit" :disabled="form.processing" @click="submit" class="bg-blue-600 px-8 text-white hover:bg-blue-700">
                        <span v-if="form.processing">Saving...</span>
                        <span v-else>{{ isEditing ? 'Update Notification' : 'Create Notification' }}</span>
                    </Button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
input,
textarea,
select,
button {
    transition: all 0.2s ease;
}
</style>
