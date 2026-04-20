<script setup lang="ts">
import Breadcrumbs from '@/components/Breadcrumbs.vue'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { useDataFormatting } from '@/composables/useDataFormatting'
import AppLayout from '@/layouts/AppLayout.vue'
import { Head, router } from '@inertiajs/vue3'
import { BarChart3, Download, TrendingUp } from 'lucide-vue-next'
import { computed, ref } from 'vue'

interface PaymentMethod {
    method: string
    count: number
    total: number
}

interface OutstandingStudent {
    accountId: string
    latestRef: string
    studentName: string
    course: string
    total: number
    balance: number
    status: string
}

interface Props {
    summary: {
        totalAssessments: number
        totalAssessmentAmount: number
        totalPaid: number
        totalOutstanding: number
    }
    charts: {
        byCourse: Array<{ course: string; student_count: number; total: number }>
        byMonth: Array<{ month: string; total: number }>
    }
    paymentMethods: PaymentMethod[]
    outstandingStudents: OutstandingStudent[]
    filters: {
        schoolYear: string
        semester: string
    }
    schoolYears: string[]
    semesters: string[]
}

const props = defineProps<Props>()
const { formatCurrency } = useDataFormatting()

const selectedSchoolYear = ref(props.filters.schoolYear)
const selectedSemester = ref(props.filters.semester)

// Client-side search within the already-loaded outstanding students list
const searchQuery = ref('')

const breadcrumbs = [
    { title: 'Dashboard', href: route('dashboard') },
    { title: 'Accounting', href: route('accounting.dashboard') },
    { title: 'Financial Reports' },
]

const collectionRate = computed(() => {
    const total = props.summary.totalAssessmentAmount
    if (total === 0) return 0
    return Math.round((props.summary.totalPaid / total) * 100)
})

const filteredPaymentMethods = computed(() => {
    return props.paymentMethods.filter(
        (m) =>
            m.method.toLowerCase() !== 'credit card' &&
            m.method.toLowerCase() !== 'credit_card' &&
            m.method.toLowerCase() !== 'debit card' &&
            m.method.toLowerCase() !== 'debit_card',
    )
})

// Filter the full outstanding student list by search query
const filteredOutstandingStudents = computed(() => {
    if (!searchQuery.value.trim()) return props.outstandingStudents

    const q = searchQuery.value.toLowerCase()
    return props.outstandingStudents.filter(
        (s) =>
            s.studentName.toLowerCase().includes(q) ||
            s.accountId.toLowerCase().includes(q) ||
            s.course.toLowerCase().includes(q) ||
            s.latestRef.toLowerCase().includes(q),
    )
})

const applyFilters = () => {
    router.get(
        route('accounting.financial-reports'),
        {
            school_year: selectedSchoolYear.value,
            semester: selectedSemester.value,
        },
        { preserveState: false },
    )
}

const exportPDF = () => {
    window.location.href = route('accounting.financial-reports.export', {
        school_year: selectedSchoolYear.value,
        semester: selectedSemester.value,
    })
}
</script>

<template>
    <AppLayout>
        <Head title="Financial Reports" />

        <div class="w-full space-y-6 p-6">
            <Breadcrumbs :items="breadcrumbs" />

            <!-- Page Header -->
            <div class="flex flex-col items-start justify-between gap-4 sm:flex-row sm:items-center">
                <div>
                    <h1 class="text-3xl font-bold text-foreground">Financial Reports</h1>
                    <p class="mt-1 text-sm text-muted-foreground">Monitor assessments, payments, and financial health</p>
                </div>
                <Button @click="exportPDF" class="gap-2">
                    <Download class="h-4 w-4" />
                    Export PDF
                </Button>
            </div>

            <!-- Filters -->
            <Card>
                <CardHeader>
                    <CardTitle class="text-base">Filters</CardTitle>
                </CardHeader>
                <CardContent>
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-end">
                        <div class="flex-1">
                            <label for="school-year" class="block text-sm font-medium text-foreground mb-1">School Year</label>
                            <select
                                id="school-year"
                                name="school_year"
                                v-model="selectedSchoolYear"
                                class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100"
                            >
                                <option v-for="year in schoolYears" :key="year" :value="year">{{ year }}</option>
                            </select>
                        </div>
                        <div class="flex-1">
                            <label for="semester" class="block text-sm font-medium text-foreground mb-1">Semester</label>
                            <select
                                id="semester"
                                name="semester"
                                v-model="selectedSemester"
                                class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100"
                            >
                                <option v-for="sem in semesters" :key="sem" :value="sem">{{ sem }}</option>
                            </select>
                        </div>
                        <Button @click="applyFilters" class="bg-blue-600 hover:bg-blue-700">Apply Filters</Button>
                    </div>
                </CardContent>
            </Card>

            <!-- Summary Cards -->
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <Card>
                    <CardHeader class="pb-3">
                        <CardTitle class="text-sm font-medium text-muted-foreground">Total Assessments</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div class="text-3xl font-bold">{{ summary.totalAssessments }}</div>
                        <p class="mt-1 text-xs text-muted-foreground">Students assessed</p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader class="pb-3">
                        <CardTitle class="text-sm font-medium text-muted-foreground">Total Assessment</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div class="text-2xl font-bold">{{ formatCurrency(summary.totalAssessmentAmount) }}</div>
                        <p class="mt-1 text-xs text-muted-foreground">Total billed</p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader class="pb-3">
                        <CardTitle class="text-sm font-medium text-muted-foreground">Total Paid</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div class="text-2xl font-bold text-green-600">{{ formatCurrency(summary.totalPaid) }}</div>
                        <p class="mt-1 text-xs text-muted-foreground">{{ collectionRate }}% collection rate</p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader class="pb-3">
                        <CardTitle class="text-sm font-medium text-muted-foreground">Outstanding</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div class="text-2xl font-bold text-red-600">{{ formatCurrency(summary.totalOutstanding) }}</div>
                        <p class="mt-1 text-xs text-muted-foreground">Pending payments</p>
                    </CardContent>
                </Card>
            </div>

            <!-- Charts Section -->
            <div class="grid gap-6 lg:grid-cols-2">
                <!-- By Course -->
                <Card>
                    <CardHeader>
                        <CardTitle class="flex items-center gap-2">
                            <BarChart3 class="h-5 w-5" />
                            Assessments by Course
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div class="space-y-4">
                            <div v-if="charts.byCourse.length === 0" class="py-6 text-center text-sm text-muted-foreground">
                                No assessment data for this period.
                            </div>
                            <div v-for="course in charts.byCourse" :key="course.course" class="flex items-end gap-3">
                                <div class="flex-1 min-w-0">
                                    <div class="text-sm font-medium text-foreground truncate">{{ course.course }}</div>
                                    <div class="h-2 mt-1 w-full rounded-full bg-muted overflow-hidden">
                                        <div
                                            class="h-full bg-blue-500"
                                            :style="{
                                                width: (course.total / Math.max(...charts.byCourse.map((c) => c.total))) * 100 + '%',
                                            }"
                                        ></div>
                                    </div>
                                </div>
                                <div class="text-right whitespace-nowrap">
                                    <div class="text-sm font-semibold">{{ course.student_count }}</div>
                                    <div class="text-xs text-muted-foreground">{{ formatCurrency(course.total) }}</div>
                                </div>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <!-- By Month -->
                <Card>
                    <CardHeader>
                        <CardTitle class="flex items-center gap-2">
                            <TrendingUp class="h-5 w-5" />
                            Payments by Month
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div class="space-y-4">
                            <div v-if="charts.byMonth.length === 0" class="py-6 text-center text-sm text-muted-foreground">
                                No payment data for this period.
                            </div>
                            <div v-for="month in charts.byMonth" :key="month.month" class="flex items-end gap-3">
                                <div class="flex-1 min-w-0">
                                    <div class="text-sm font-medium text-foreground">{{ month.month }}</div>
                                    <div class="h-2 mt-1 w-full rounded-full bg-muted overflow-hidden">
                                        <div
                                            class="h-full bg-green-500"
                                            :style="{
                                                width: (month.total / Math.max(...charts.byMonth.map((m) => m.total), 1)) * 100 + '%',
                                            }"
                                        ></div>
                                    </div>
                                </div>
                                <div class="text-right whitespace-nowrap">
                                    <div class="text-sm font-semibold">{{ formatCurrency(month.total) }}</div>
                                </div>
                            </div>
                        </div>
                    </CardContent>
                </Card>
            </div>

            <!-- Payment Methods -->
            <Card>
                <CardHeader>
                    <CardTitle>Payment Method Breakdown</CardTitle>
                </CardHeader>
                <CardContent>
                    <div v-if="filteredPaymentMethods.length === 0" class="py-6 text-center text-sm text-muted-foreground">
                        No payment data for this period.
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2 md:grid-cols-3">
                        <div v-for="method in filteredPaymentMethods" :key="method.method" class="rounded-lg border border-border p-4">
                            <div class="text-sm font-medium text-muted-foreground capitalize">{{ method.method }}</div>
                            <div class="mt-2 text-2xl font-bold">{{ method.count }}</div>
                            <div class="mt-1 text-xs text-muted-foreground">{{ formatCurrency(method.total) }}</div>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <!-- Outstanding Balances -->
            <Card>
                <CardHeader>
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <CardTitle>
                            Outstanding Balances
                            <span class="ml-2 text-sm font-normal text-muted-foreground">
                                <!-- Show filtered count vs total -->
                                <template v-if="searchQuery.trim()">
                                    {{ filteredOutstandingStudents.length }} of {{ outstandingStudents.length }} students
                                </template>
                                <template v-else>
                                    {{ outstandingStudents.length }} student{{ outstandingStudents.length !== 1 ? 's' : '' }}
                                </template>
                            </span>
                        </CardTitle>
                        <!-- Search box — purely client-side, no round-trip -->
                        <input
                            v-model="searchQuery"
                            type="text"
                            placeholder="Search by name, ID, course..."
                            class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100 sm:w-64"
                        />
                    </div>
                </CardHeader>
                <CardContent>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-border">
                            <thead class="bg-muted/50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                                        Account ID
                                    </th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                                        Latest Reference
                                    </th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                                        Student Name
                                    </th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                                        Course
                                    </th>
                                    <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                                        Total Assessment
                                    </th>
                                    <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                                        Outstanding Balance
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border">
                                <tr
                                    v-for="(student, index) in filteredOutstandingStudents"
                                    :key="index"
                                    class="hover:bg-muted/30"
                                >
                                    <td class="px-4 py-3 text-sm font-mono text-muted-foreground">{{ student.accountId }}</td>
                                    <td class="px-4 py-3 text-sm font-mono text-indigo-600">{{ student.latestRef }}</td>
                                    <td class="px-4 py-3 text-sm font-medium">{{ student.studentName }}</td>
                                    <td class="px-4 py-3 text-sm text-muted-foreground">{{ student.course }}</td>
                                    <td class="px-4 py-3 text-right text-sm">{{ formatCurrency(student.total) }}</td>
                                    <td class="px-4 py-3 text-right text-sm font-semibold text-red-600">
                                        {{ formatCurrency(student.balance) }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>

                        <!-- Empty state: no results at all for this period -->
                        <div v-if="outstandingStudents.length === 0" class="py-8 text-center">
                            <p class="text-sm text-muted-foreground">No outstanding balances for this period.</p>
                        </div>

                        <!-- Empty state: search returned nothing -->
                        <div v-else-if="filteredOutstandingStudents.length === 0" class="py-8 text-center">
                            <p class="text-sm text-muted-foreground">No students match your search.</p>
                        </div>
                    </div>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>