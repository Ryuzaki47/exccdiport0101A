<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import { useDataFormatting } from '@/composables/useDataFormatting';
import { Head, useForm } from '@inertiajs/vue3';
import { UploadCloud, File, CheckCircle, AlertCircle } from 'lucide-vue-next';
import { ref, computed } from 'vue';

const { formatCurrency, formatDate } = useDataFormatting();

const props = defineProps<{
    transaction: {
        id: number;
        amount: number;
        payment_method: string;
        term_name: string;
        description: string | null;
        created_at: string;
    };
}>();

const breadcrumbs = [
    { title: 'My Account', href: route('student.account') },
    { title: 'Upload Proof of Payment' },
];

const form = useForm({
    proof_of_payment: null as File | null,
});

const fileInput = ref<HTMLInputElement | null>(null);
const fileName = ref<string | null>(null);
const fileSize = ref<number | null>(null);

const handleFileSelect = (e: Event) => {
    const target = e.target as HTMLInputElement;
    const file = target.files?.[0];

    if (file) {
        form.proof_of_payment = file;
        fileName.value = file.name;
        fileSize.value = file.size;
        form.errors.proof_of_payment = '';
    }
};

const handleDrop = (e: DragEvent) => {
    e.preventDefault();
    e.stopPropagation();

    const file = e.dataTransfer?.files?.[0];
    if (file) {
        const input = fileInput.value;
        if (input) {
            const dataTransfer = new DataTransfer();
            dataTransfer.items.add(file);
            input.files = dataTransfer.files;
            handleFileSelect({ target: input } as any);
        }
    }
};

const submit = () => {
    if (!form.proof_of_payment) {
        form.errors.proof_of_payment = ['Please select a file to upload.'];
        return;
    }

    form.post(route('payment.proof.upload', props.transaction.id), {
        preserveScroll: true,
        forceFormData: true,
    });
};

const isValidFile = computed(() => {
    if (!form.proof_of_payment) return false;
    const validTypes = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];
    const maxSize = 5120 * 1024; // 5MB
    return validTypes.includes(form.proof_of_payment.type) && form.proof_of_payment.size <= maxSize;
});

const canSubmit = computed(() =>
    isValidFile.value && !form.processing
);
</script>

<template>
    <AppLayout>
        <Head title="Upload Proof of Payment" />

        <div class="w-full p-6">
            <Breadcrumbs :items="breadcrumbs" />

            <div class="mb-6">
                <h1 class="text-2xl font-bold text-gray-900">Upload Proof of Payment</h1>
                <p class="text-sm text-muted-foreground mt-1">
                    Upload a receipt or proof of your payment to complete the submission.
                </p>
            </div>

            <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <!-- Left: Upload Form -->
                <div class="lg:col-span-2 space-y-6">
                    <!-- Payment Summary Card -->
                    <div class="ccdi-card p-6 space-y-4">
                        <h2 class="text-base font-semibold text-gray-900 border-b pb-3">
                            Payment Summary
                        </h2>
                        <div class="space-y-3 text-sm">
                            <div class="flex justify-between">
                                <span class="text-gray-600">Amount</span>
                                <span class="font-semibold">{{ formatCurrency(transaction.amount) }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-600">Payment Method</span>
                                <span class="font-semibold capitalize">{{ transaction.payment_method }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-600">Payment Term</span>
                                <span class="font-semibold">{{ transaction.term_name }}</span>
                            </div>
                            <div v-if="transaction.description" class="flex justify-between">
                                <span class="text-gray-600">Notes</span>
                                <span class="text-right">{{ transaction.description }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- File Upload Section -->
                    <div class="ccdi-card p-6 space-y-5">
                        <h2 class="text-base font-semibold text-gray-900 border-b pb-3">
                            Upload Receipt
                        </h2>

                        <!-- Drag and Drop Area -->
                        <div
                            @drop="handleDrop"
                            @dragover.prevent
                            class="rounded-xl border-2 border-dashed border-gray-300 hover:border-indigo-400 p-8 text-center transition-colors cursor-pointer"
                            :class="{
                                'border-indigo-400 bg-indigo-50': fileName,
                            }"
                        >
                            <input
                                ref="fileInput"
                                type="file"
                                accept=".pdf,.jpg,.jpeg,.png,.webp"
                                @change="handleFileSelect"
                                class="hidden"
                            />

                            <div class="space-y-3">
                                <div class="flex justify-center">
                                    <div
                                        class="p-3 rounded-full"
                                        :class="fileName ? 'bg-green-100' : 'bg-gray-100'"
                                    >
                                        <UploadCloud
                                            :size="32"
                                            :class="fileName ? 'text-green-600' : 'text-gray-400'"
                                        />
                                    </div>
                                </div>

                                <div>
                                    <p class="font-semibold text-gray-900">
                                        {{ fileName ? 'File Selected' : 'Drag and drop your receipt here' }}
                                    </p>
                                    <p class="text-xs text-gray-500 mt-1">
                                        or
                                        <button
                                            type="button"
                                            @click="fileInput?.click()"
                                            class="text-indigo-600 hover:underline font-medium"
                                        >
                                            browse your files
                                        </button>
                                    </p>
                                </div>

                                <p class="text-xs text-gray-400">
                                    PDF, JPG, PNG, or WebP • Max 5 MB
                                </p>
                            </div>
                        </div>

                        <!-- Selected File Details -->
                        <div v-if="fileName" class="flex items-center gap-3 p-4 bg-green-50 rounded-lg border border-green-200">
                            <File :size="20" class="text-green-600 flex-shrink-0" />
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-medium text-green-900 truncate">{{ fileName }}</p>
                                <p class="text-xs text-green-700">
                                    {{ fileSize ? (fileSize / 1024).toFixed(1) : 0 }} KB
                                </p>
                            </div>
                            <button
                                type="button"
                                @click="() => { fileName = null; fileSize = null; form.proof_of_payment = null; }"
                                class="text-green-600 hover:text-green-700 font-medium"
                            >
                                Remove
                            </button>
                        </div>

                        <!-- Validation Error -->
                        <div v-if="form.errors.proof_of_payment" class="rounded-lg bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-700">
                            {{ form.errors.proof_of_payment }}
                        </div>

                        <!-- Info Message -->
                        <div class="flex items-start gap-3 p-4 bg-blue-50 rounded-lg border border-blue-200">
                            <AlertCircle :size="18" class="text-blue-600 flex-shrink-0 mt-0.5" />
                            <p class="text-sm text-blue-700">
                                <strong>Make sure your receipt shows:</strong>
                                <br />
                                • Date and time of payment
                                <br />
                                • Amount (₱{{ formatCurrency(transaction.amount) }})
                                <br />
                                • Your name (if visible)
                                <br />
                                • Reference or transaction number
                            </p>
                        </div>

                        <!-- Submit Button -->
                        <button
                            type="button"
                            @click="submit"
                            :disabled="!canSubmit"
                            class="w-full rounded-xl bg-indigo-600 px-5 py-3 font-semibold text-white shadow transition-colors hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            <span v-if="form.processing">Uploading…</span>
                            <span v-else>Submit for Verification</span>
                        </button>
                    </div>
                </div>

                <!-- Right: Info Panel -->
                <div class="space-y-4">
                    <!-- What Happens Next -->
                    <div class="ccdi-card p-5">
                        <h3 class="text-xs font-semibold uppercase tracking-widest text-muted-foreground mb-4">
                            What's Next
                        </h3>
                        <div class="space-y-4">
                            <div class="flex gap-3">
                                <div class="flex-shrink-0">
                                    <div class="flex items-center justify-center h-8 w-8 rounded-full bg-green-100">
                                        <CheckCircle :size="18" class="text-green-600" />
                                    </div>
                                </div>
                                <div>
                                    <p class="font-medium text-sm text-gray-900">Upload Receipt</p>
                                    <p class="text-xs text-gray-500 mt-0.5">Done! You're on this step now.</p>
                                </div>
                            </div>
                            <div class="flex gap-3">
                                <div class="flex-shrink-0">
                                    <div class="flex items-center justify-center h-8 w-8 rounded-full bg-blue-100">
                                        <span class="text-xs font-semibold text-blue-600">2</span>
                                    </div>
                                </div>
                                <div>
                                    <p class="font-medium text-sm text-gray-900">Awaiting Verification</p>
                                    <p class="text-xs text-gray-500 mt-0.5">Accounting staff will review your receipt.</p>
                                </div>
                            </div>
                            <div class="flex gap-3">
                                <div class="flex-shrink-0">
                                    <div class="flex items-center justify-center h-8 w-8 rounded-full bg-gray-100">
                                        <span class="text-xs font-semibold text-gray-600">3</span>
                                    </div>
                                </div>
                                <div>
                                    <p class="font-medium text-sm text-gray-900">Payment Approved</p>
                                    <p class="text-xs text-gray-500 mt-0.5">Balance updated once verified.</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Tips Card -->
                    <div class="ccdi-card p-5">
                        <h3 class="text-xs font-semibold uppercase tracking-widest text-muted-foreground mb-3">
                            Tips
                        </h3>
                        <ul class="space-y-2 text-xs text-gray-600">
                            <li>✓ Take a clear, well-lit photo</li>
                            <li>✓ Make sure all text is readable</li>
                            <li>✓ Include the full receipt/proof</li>
                            <li>✓ File must be under 5 MB</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
