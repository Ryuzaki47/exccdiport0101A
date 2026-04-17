<template>
    <Head title="Workflow Details" />

    <AppLayout>
        <div class="w-full space-y-6 p-6">
            <Breadcrumbs :items="breadcrumbs" />

            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-3xl font-bold">Workflow Details</h1>
                    <p class="text-gray-500">View workflow information and approval status</p>
                </div>
            </div>

            <!-- Workflow Card -->
            <div v-if="workflow" class="space-y-6 rounded-xl border bg-white p-6 shadow-sm">
                <!-- Status -->
                <div class="flex items-center gap-4">
                    <span
                        :class="{
                            'bg-yellow-100 text-yellow-800': workflow.status === 'pending',
                            'bg-green-100 text-green-800': workflow.status === 'approved',
                            'bg-red-100 text-red-800': workflow.status === 'rejected',
                            'bg-blue-100 text-blue-800': workflow.status === 'in_progress',
                        }"
                        class="rounded-full px-4 py-2 text-sm font-semibold"
                    >
                        {{ capitalize(workflow.status) }}
                    </span>
                </div>

                <!-- Workflow Information -->
                <div class="grid grid-cols-3 gap-4 border-b pb-4">
                    <div>
                        <p class="text-sm text-gray-600">Workflow Type</p>
                        <p class="font-semibold">{{ workflow.type }}</p>
                    </div>
                    <div>
                        <p class="text-sm text-gray-600">Created</p>
                        <p class="font-semibold">{{ formatDate(workflow.created_at) }}</p>
                    </div>
                    <div>
                        <p class="text-sm text-gray-600">Updated</p>
                        <p class="font-semibold">{{ formatDate(workflow.updated_at) }}</p>
                    </div>
                </div>

                <!-- Related Entity -->
                <div class="border-b pb-4">
                    <p class="mb-2 text-sm text-gray-600">Related Entity</p>
                    <div class="rounded-lg bg-gray-50 p-3">
                        <p class="text-sm"><strong>Type:</strong> {{ workflow.workflowable_type }}</p>
                        <p class="mt-1 text-sm"><strong>ID:</strong> {{ workflow.workflowable_id }}</p>
                    </div>
                </div>

                <!-- Approvals -->
                <div v-if="workflow.approvals && workflow.approvals.length > 0" class="border-b pb-4">
                    <p class="mb-4 text-sm font-semibold">Approvals ({{ workflow.approvals.length }})</p>
                    <div class="space-y-3">
                        <div
                            v-for="approval in workflow.approvals"
                            :key="approval.id"
                            class="rounded-lg border p-3"
                            :class="{
                                'bg-green-50': approval.status === 'approved',
                                'bg-red-50': approval.status === 'rejected',
                                'bg-yellow-50': approval.status === 'pending',
                            }"
                        >
                            <div class="flex items-start justify-between">
                                <div>
                                    <p class="font-semibold">{{ approval.approver_name }}</p>
                                    <p class="text-sm text-gray-600">{{ formatDate(approval.created_at) }}</p>
                                </div>
                                <span
                                    :class="{
                                        'bg-green-100 text-green-800': approval.status === 'approved',
                                        'bg-red-100 text-red-800': approval.status === 'rejected',
                                        'bg-yellow-100 text-yellow-800': approval.status === 'pending',
                                    }"
                                    class="rounded px-2 py-1 text-xs font-semibold"
                                >
                                    {{ capitalize(approval.status) }}
                                </span>
                            </div>
                            <p v-if="approval.comment" class="mt-2 text-sm text-gray-700">{{ approval.comment }}</p>
                        </div>
                    </div>
                </div>

                <!-- Description -->
                <div v-if="workflow.description" class="pt-4">
                    <p class="mb-2 text-sm text-gray-600">Description</p>
                    <p class="text-gray-800">{{ workflow.description }}</p>
                </div>
            </div>

            <!-- Loading State -->
            <div v-else class="py-12 text-center">
                <p class="text-gray-500">Loading workflow details...</p>
            </div>
        </div>
    </AppLayout>
</template>

<script setup lang="ts">
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head } from '@inertiajs/vue3';

interface Approval {
    id: number;
    status: string;
    approver_name: string;
    comment: string | null;
    created_at: string;
}

interface Workflow {
    id: number;
    type: string;
    status: string;
    workflowable_type: string;
    workflowable_id: number;
    description: string | null;
    approvals: Approval[];
    created_at: string;
    updated_at: string;
}

defineProps<{
    workflow: Workflow;
}>();

const breadcrumbs = [
    { title: 'Dashboard', href: route('admin.dashboard') },
    { title: 'Workflows', href: route('workflows.index') },
    { title: 'Details' },
];

const formatDate = (date: string) => {
    return new Date(date).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
};

const capitalize = (str: string) => str.charAt(0).toUpperCase() + str.slice(1);
</script>
