<script setup lang="ts">
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { ArrowLeft } from 'lucide-vue-next';

defineProps<{
    courses: string[];
    yearLevels: string[];
}>();

const page = usePage();

const breadcrumbs = [
    { title: 'Dashboard', href: route('admin.dashboard') },
    { title: 'Student Fee Management', href: route('student-fees.index') },
    { title: 'Add Student' },
];

const form = useForm({
    last_name: '',
    first_name: '',
    middle_initial: '',
    email: '',
    birthday: '',
    year_level: '',
    course: '',
    address: '',
    phone: '',
});

const submit = () => {
    form.post(route('student-fees.store-student'), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
        },
    });
};
</script>

<template>
    <Head title="Add Student" />

    <AppLayout>
        <div class="mx-auto max-w-4xl space-y-6 p-6">
            <Breadcrumbs :items="breadcrumbs" />

            <!-- Header -->
            <div class="flex items-center gap-4">
                <Link :href="route('student-fees.index')">
                    <Button variant="outline" size="sm" class="flex items-center gap-2">
                        <ArrowLeft class="h-4 w-4" />
                        Back
                    </Button>
                </Link>
                <div>
                    <h1 class="text-3xl font-bold">Add New Student</h1>
                    <p class="mt-2 text-gray-600">Register a new student in the system</p>
                </div>
            </div>

            <form @submit.prevent="submit" class="space-y-6">
                <!-- Personal Information -->
                <div class="rounded-lg border bg-white p-6 shadow-sm">
                    <h2 class="mb-4 text-lg font-semibold">Personal Information</h2>
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                        <div class="space-y-2">
                            <Label for="last_name">Last Name *</Label>
                            <Input id="last_name" v-model="form.last_name" required placeholder="Dela Cruz" />
                            <p v-if="form.errors.last_name" class="text-sm text-red-500">
                                {{ form.errors.last_name }}
                            </p>
                        </div>

                        <div class="space-y-2">
                            <Label for="first_name">First Name *</Label>
                            <Input id="first_name" v-model="form.first_name" required placeholder="Juan" />
                            <p v-if="form.errors.first_name" class="text-sm text-red-500">
                                {{ form.errors.first_name }}
                            </p>
                        </div>

                        <div class="space-y-2">
                            <Label for="middle_initial">Middle Initial</Label>
                            <Input id="middle_initial" v-model="form.middle_initial" maxlength="10" placeholder="P" />
                            <p v-if="form.errors.middle_initial" class="text-sm text-red-500">
                                {{ form.errors.middle_initial }}
                            </p>
                        </div>
                    </div>

                    <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
                        <div class="space-y-2">
                            <Label for="email">Email *</Label>
                            <Input id="email" v-model="form.email" type="email" required placeholder="student@ccdi.edu.ph" />
                            <p v-if="form.errors.email" class="text-sm text-red-500">
                                {{ form.errors.email }}
                            </p>
                        </div>

                        <div class="space-y-2">
                            <Label for="birthday">Birthday *</Label>
                            <Input id="birthday" v-model="form.birthday" type="date" required />
                            <p v-if="form.errors.birthday" class="text-sm text-red-500">
                                {{ form.errors.birthday }}
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Contact Information -->
                <div class="rounded-lg border bg-white p-6 shadow-sm">
                    <h2 class="mb-4 text-lg font-semibold">Contact Information</h2>
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <div class="space-y-2">
                            <Label for="phone">Phone Number *</Label>
                            <Input id="phone" v-model="form.phone" required placeholder="09171234567" />
                            <p v-if="form.errors.phone" class="text-sm text-red-500">
                                {{ form.errors.phone }}
                            </p>
                        </div>

                        <div class="space-y-2">
                            <Label for="address">Address *</Label>
                            <Input id="address" v-model="form.address" required placeholder="Sorsogon City" />
                            <p v-if="form.errors.address" class="text-sm text-red-500">
                                {{ form.errors.address }}
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Academic Information -->
                <div class="rounded-lg border bg-white p-6 shadow-sm">
                    <h2 class="mb-4 text-lg font-semibold">Academic Information</h2>
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                        <div class="space-y-2">
                            <Label>Account ID</Label>
                            <div class="flex items-center rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-gray-600">
                                <span class="text-sm">Auto-generated upon registration</span>
                            </div>
                            <p class="text-xs text-gray-500">Account ID will be automatically generated when the student is created</p>
                        </div>

                        <div class="space-y-2">
                            <Label for="course">Course *</Label>
                            <select
                                id="course"
                                v-model="form.course"
                                required
                                class="w-full rounded-lg border border-gray-300 px-3 py-2 focus:border-transparent focus:ring-2 focus:ring-blue-500"
                            >
                                <option value="">Select course</option>
                                <option v-for="course in courses" :key="course" :value="course">
                                    {{ course }}
                                </option>
                            </select>
                            <p v-if="form.errors.course" class="text-sm text-red-500">
                                {{ form.errors.course }}
                            </p>
                        </div>

                        <div class="space-y-2">
                            <Label for="year_level">Year Level *</Label>
                            <select
                                id="year_level"
                                v-model="form.year_level"
                                required
                                class="w-full rounded-lg border border-gray-300 px-3 py-2 focus:border-transparent focus:ring-2 focus:ring-blue-500"
                            >
                                <option value="">Select year level</option>
                                <option v-for="year in yearLevels" :key="year" :value="year">
                                    {{ year }}
                                </option>
                            </select>
                            <p v-if="form.errors.year_level" class="text-sm text-red-500">
                                {{ form.errors.year_level }}
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Password Information -->
                <div class="rounded-lg border border-blue-200 bg-blue-50 p-4">
                    <p class="text-sm text-blue-800">
                        <strong>Note:</strong> Default password will be set to "password". Student can change it after first login.
                    </p>
                </div>

                <!-- Actions -->
                <div class="flex items-center justify-end gap-4">
                    <Link :href="route('student-fees.index')">
                        <Button type="button" variant="outline"> Cancel </Button>
                    </Link>
                    <Button type="submit" :disabled="form.processing">
                        {{ form.processing ? 'Adding Student...' : 'Add Student' }}
                    </Button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
