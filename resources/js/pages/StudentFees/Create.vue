<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/layouts/AppLayout.vue'
import Breadcrumbs from '@/components/Breadcrumbs.vue'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { useDataFormatting } from '@/composables/useDataFormatting'
import {
  Search, User, BookOpen, FlaskConical, Calculator,
  CheckCircle2, Loader2, AlertTriangle, Info, BookMarked,
} from 'lucide-vue-next'

// ─── Types ───────────────────────────────────────────────────────────────────

interface FeeRates {
  tuition_per_unit: number
  lab_fee_per_subject: number
  misc_total: number
  misc_items: Array<{ id: number; key: string; label: string; amount: number; category: string }>
  payment_terms: Array<{ term_name: string; term_order: number; percentage: number }>
  nstp_min_tuition: number
}

interface PreselectedStudent {
  id: number
  name: string
  account_id: string
  course: string
  year_level: string
  is_irregular: boolean
}

interface CurriculumSubject {
  id: number
  code: string
  name: string
  lec_units: number
  lab_units: number
  total_units: number
  is_nstp: boolean
  is_pathfit: boolean
  is_billable: boolean
}

// ─── Props ───────────────────────────────────────────────────────────────────

const props = defineProps<{
  preselectedStudent: PreselectedStudent | null
  feeRates: FeeRates
}>()

// ─── Composables ─────────────────────────────────────────────────────────────

const { formatCurrency } = useDataFormatting()

// ─── Breadcrumbs ─────────────────────────────────────────────────────────────

const breadcrumbs = [
  { title: 'Dashboard', href: route('accounting.dashboard') },
  { title: 'Student Fees', href: route('student-fees.index') },
  { title: 'New Assessment', href: route('student-fees.create') },
]

// ─── Student Search ───────────────────────────────────────────────────────────

const studentSearch   = ref('')
const searchResults   = ref<PreselectedStudent[]>([])
const searchLoading   = ref(false)
const selectedStudent = ref<PreselectedStudent | null>(props.preselectedStudent ?? null)

let searchTimeout: ReturnType<typeof setTimeout>

async function searchStudents() {
  if (studentSearch.value.length < 2) {
    searchResults.value = []
    return
  }

  searchLoading.value = true
  clearTimeout(searchTimeout)

  searchTimeout = setTimeout(async () => {
    try {
      const res  = await fetch(route('student-fees.search') + '?q=' + encodeURIComponent(studentSearch.value))
      const data = await res.json()
      searchResults.value = data.students ?? []
    } catch {
      searchResults.value = []
    } finally {
      searchLoading.value = false
    }
  }, 300)
}

function selectStudent(student: PreselectedStudent) {
  selectedStudent.value = student
  searchResults.value   = []
  studentSearch.value   = ''
  form.user_id          = student.id

  // Reset curriculum when a new student is selected
  curriculumSubjects.value   = []
  curriculumMessage.value    = ''
  curriculumLoading.value    = false
}

function clearStudent() {
  selectedStudent.value      = null
  form.user_id               = 0
  form.lec_units             = 0
  form.lab_subjects          = 0
  curriculumSubjects.value   = []
  curriculumMessage.value    = ''
}

// ─── Curriculum Auto-Populate ────────────────────────────────────────────────

const curriculumLoading  = ref(false)
const curriculumSubjects = ref<CurriculumSubject[]>([])
const curriculumMessage  = ref('')

async function loadCurriculum() {
  const student = selectedStudent.value
  if (! student || student.is_irregular) {
    curriculumSubjects.value = []
    curriculumMessage.value  = student?.is_irregular
      ? 'Irregular student — enter units manually.'
      : ''
    return
  }

  if (! form.semester) return

  curriculumLoading.value  = true
  curriculumSubjects.value = []
  curriculumMessage.value  = ''

  try {
    const res  = await fetch(
      route('student-fees.curriculum-units') +
      '?student_id=' + student.id +
      '&semester='   + encodeURIComponent(form.semester)
    )
    const data = await res.json()

    if (data.found) {
      curriculumSubjects.value = data.subjects
      form.lec_units    = data.billable_lec_units
      form.lab_subjects = data.lab_subject_count
      curriculumMessage.value  = ''
    } else {
      curriculumMessage.value = data.message ?? 'No curriculum data found for this student.'
      // Don't reset units — let accounting input manually
    }
  } catch {
    curriculumMessage.value = 'Could not load curriculum — enter units manually.'
  } finally {
    curriculumLoading.value = false
  }
}

// ─── Form ─────────────────────────────────────────────────────────────────────

const form = useForm({
  user_id:        props.preselectedStudent?.id ?? 0,
  semester:       '1st' as '1st' | '2nd' | 'Summer',
  school_year:    '',
  lec_units:      0,
  lab_subjects:   0,   // number of subjects with lab (not lab units)
  discount_type:  'none' as 'none' | 'full' | 'nstp',
  is_taking_nstp: false,
})

// Pre-fill school year
const currentYear   = new Date().getFullYear()
form.school_year    = `${currentYear}-${currentYear + 1}`

// Trigger curriculum load when student or semester changes
watch([selectedStudent, () => form.semester], () => {
  if (selectedStudent.value && ! selectedStudent.value.is_irregular) {
    loadCurriculum()
  }
})

// ─── Live Fee Computation (mirrors AssessmentService::compute) ────────────────

const baseTuition = computed(() =>
  Number(form.lec_units) * props.feeRates.tuition_per_unit
)

const baseLabFee = computed(() =>
  Number(form.lab_subjects) * props.feeRates.lab_fee_per_subject
)

const nstpMinTuition = computed(() => props.feeRates.nstp_min_tuition)

const discountedFees = computed(() => {
  const dt          = form.discount_type
  const nstp        = form.is_taking_nstp
  const rawTuition  = baseTuition.value
  const lab         = baseLabFee.value
  const misc        = props.feeRates.misc_total

  if (dt === 'full') {
    if (nstp) {
      return { tuition: nstpMinTuition.value, lab, misc }
    }
    // Full scholarship: tuition = 0. Lab and misc STILL charged (never waived).
    return { tuition: 0, lab, misc }
  }

  if (dt === 'nstp' || nstp) {
    return { tuition: nstpMinTuition.value, lab, misc }
  }

  return { tuition: rawTuition, lab, misc }
})

const tuitionFee       = computed(() => discountedFees.value.tuition)
const labFee           = computed(() => discountedFees.value.lab)
const miscFee          = computed(() => discountedFees.value.misc)
const totalAssessment  = computed(() => tuitionFee.value + labFee.value + miscFee.value)

const paymentTermBreakdown = computed(() =>
  props.feeRates.payment_terms.map((t) => ({
    term_name:  t.term_name,
    term_order: t.term_order,
    percentage: t.percentage,
    amount:     Math.round(totalAssessment.value * (t.percentage / 100) * 100) / 100,
  }))
)

// ─── Curriculum summary helpers ───────────────────────────────────────────────

const billableSubjects  = computed(() => curriculumSubjects.value.filter(s => s.is_billable))
const nstpSubjects      = computed(() => curriculumSubjects.value.filter(s => s.is_nstp))
const pathfitSubjects   = computed(() => curriculumSubjects.value.filter(s => s.is_pathfit))
const labSubjectsList   = computed(() => billableSubjects.value.filter(s => s.lab_units > 0))

// ─── Submit ───────────────────────────────────────────────────────────────────

function submit() {
  if (! selectedStudent.value) return
  form.user_id = selectedStudent.value.id
  form.post(route('student-fees.store'))
}
</script>

<template>
  <AppLayout>
    <div class="w-full p-6 space-y-6">
      <Breadcrumbs :items="breadcrumbs" />

      <div class="flex items-center gap-3">
        <Calculator class="h-6 w-6 text-blue-600" />
        <h1 class="text-2xl font-bold">New Student Assessment</h1>
      </div>

      <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">

        <!-- ── LEFT: Form ─────────────────────────────────────────── -->
        <div class="xl:col-span-2 space-y-5">

          <!-- Student Selector -->
          <Card>
            <CardHeader>
              <CardTitle class="flex items-center gap-2 text-base">
                <User class="h-4 w-4" /> Student
              </CardTitle>
            </CardHeader>
            <CardContent class="space-y-3">
              <!-- Already selected -->
              <div v-if="selectedStudent"
                class="flex items-center justify-between rounded-lg border bg-blue-50 dark:bg-blue-950 p-4">
                <div>
                  <p class="font-semibold text-blue-900 dark:text-blue-100">{{ selectedStudent.name }}</p>
                  <p class="text-sm text-blue-700 dark:text-blue-300">
                    {{ selectedStudent.account_id }} · {{ selectedStudent.course }} · {{ selectedStudent.year_level }}
                    <span v-if="selectedStudent.is_irregular"
                      class="ml-2 inline-flex items-center gap-1 text-xs font-medium text-amber-700 bg-amber-100 px-2 py-0.5 rounded-full">
                      <AlertTriangle class="h-3 w-3" /> Irregular
                    </span>
                    <span v-else
                      class="ml-2 inline-flex items-center gap-1 text-xs font-medium text-green-700 bg-green-100 px-2 py-0.5 rounded-full">
                      ✓ Regular
                    </span>
                  </p>
                </div>
                <Button variant="outline" size="sm" @click="clearStudent">Change</Button>
              </div>

              <!-- Search box -->
              <div v-else class="relative">
                <div class="relative">
                  <Search class="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
                  <Input
                    v-model="studentSearch"
                    class="pl-9"
                    placeholder="Search student name or account ID…"
                    @input="searchStudents"
                  />
                  <Loader2 v-if="searchLoading"
                    class="absolute right-3 top-1/2 -translate-y-1/2 h-4 w-4 animate-spin text-muted-foreground" />
                </div>
                <!-- Dropdown results -->
                <div v-if="searchResults.length > 0"
                  class="absolute z-20 mt-1 w-full rounded-md border bg-white dark:bg-zinc-900 shadow-lg">
                  <button
                    v-for="s in searchResults"
                    :key="s.id"
                    class="w-full text-left px-4 py-3 hover:bg-accent transition-colors border-b last:border-0"
                    @click="selectStudent(s)"
                  >
                    <p class="font-medium text-sm flex items-center gap-2">
                      {{ s.name }}
                      <span v-if="s.is_irregular"
                        class="text-xs text-amber-700 bg-amber-100 px-1.5 py-0.5 rounded">Irregular</span>
                    </p>
                    <p class="text-xs text-muted-foreground">{{ s.account_id }} · {{ s.course }} · {{ s.year_level }}</p>
                  </button>
                </div>
                <p v-if="form.errors.user_id" class="text-sm text-destructive mt-1">
                  {{ form.errors.user_id }}
                </p>
              </div>
            </CardContent>
          </Card>

          <!-- Semester / School Year -->
          <Card>
            <CardHeader>
              <CardTitle class="text-base">Enrollment Period</CardTitle>
            </CardHeader>
            <CardContent class="grid grid-cols-2 gap-4">
              <div class="space-y-1.5">
                <Label for="semester">Semester</Label>
                <select
                  id="semester"
                  v-model="form.semester"
                  class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs focus:outline-none focus:ring-1 focus:ring-ring"
                >
                  <option value="1st">1st Semester</option>
                  <option value="2nd">2nd Semester</option>
                  <option value="Summer">Summer</option>
                </select>
                <p v-if="form.errors.semester" class="text-sm text-destructive">{{ form.errors.semester }}</p>
              </div>
              <div class="space-y-1.5">
                <Label for="school_year">School Year</Label>
                <Input id="school_year" v-model="form.school_year" placeholder="e.g. 2025-2026" />
                <p v-if="form.errors.school_year" class="text-sm text-destructive">{{ form.errors.school_year }}</p>
              </div>
            </CardContent>
          </Card>

          <!-- Curriculum Auto-Populate (Regular Students) -->
          <Card v-if="selectedStudent && !selectedStudent.is_irregular">
            <CardHeader>
              <CardTitle class="flex items-center gap-2 text-base">
                <BookMarked class="h-4 w-4 text-green-600" />
                Curriculum — {{ selectedStudent.course }}, {{ selectedStudent.year_level }}
                <span v-if="curriculumLoading">
                  <Loader2 class="h-4 w-4 animate-spin text-muted-foreground" />
                </span>
              </CardTitle>
            </CardHeader>
            <CardContent class="space-y-3">
              <!-- No subjects found -->
              <div v-if="curriculumMessage && !curriculumLoading"
                class="flex items-start gap-2 rounded-md bg-amber-50 border border-amber-200 px-4 py-3 text-sm text-amber-800">
                <AlertTriangle class="h-4 w-4 mt-0.5 shrink-0" />
                {{ curriculumMessage }}
              </div>

              <!-- Subjects table -->
              <div v-if="curriculumSubjects.length > 0 && !curriculumLoading">
                <div class="rounded-md border overflow-hidden">
                  <table class="w-full text-xs">
                    <thead class="bg-muted text-muted-foreground">
                      <tr>
                        <th class="text-left px-3 py-2">Code</th>
                        <th class="text-left px-3 py-2">Subject</th>
                        <th class="text-center px-3 py-2">LEC</th>
                        <th class="text-center px-3 py-2">LAB</th>
                        <th class="text-center px-3 py-2">Billed?</th>
                      </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                      <tr v-for="s in curriculumSubjects" :key="s.id"
                        :class="[
                          s.is_nstp    ? 'bg-amber-50 text-amber-800' :
                          s.is_pathfit ? 'bg-purple-50 text-purple-800' :
                          'hover:bg-muted/50'
                        ]">
                        <td class="px-3 py-2 font-mono">{{ s.code }}</td>
                        <td class="px-3 py-2">
                          {{ s.name }}
                          <span v-if="s.is_nstp" class="ml-1 text-xs bg-amber-200 text-amber-800 px-1 rounded">NSTP</span>
                          <span v-if="s.is_pathfit" class="ml-1 text-xs bg-purple-200 text-purple-800 px-1 rounded">PATHFIT/PE</span>
                        </td>
                        <td class="text-center px-3 py-2">{{ s.lec_units }}</td>
                        <td class="text-center px-3 py-2">{{ s.lab_units }}</td>
                        <td class="text-center px-3 py-2">
                          <span v-if="s.is_billable" class="text-green-600 font-medium">✓</span>
                          <span v-else class="text-muted-foreground">—</span>
                        </td>
                      </tr>
                    </tbody>
                  </table>
                </div>

                <!-- Summary row -->
                <div class="mt-2 flex flex-wrap gap-3 text-xs text-muted-foreground">
                  <span class="text-green-700">
                    ✓ {{ billableSubjects.length }} billable subjects
                    · {{ form.lec_units }} LEC units
                    · {{ form.lab_subjects }} with lab
                  </span>
                  <span v-if="nstpSubjects.length > 0" class="text-amber-700">
                    ⚠ {{ nstpSubjects.length }} NSTP (excluded from billing)
                  </span>
                  <span v-if="pathfitSubjects.length > 0" class="text-purple-700">
                    ⚠ {{ pathfitSubjects.length }} PATHFIT/PE (excluded from billing)
                  </span>
                </div>
              </div>

              <p class="text-xs text-muted-foreground">
                Units are auto-filled from the curriculum. You can override them below if the student
                has subject adjustments approved by the registrar.
              </p>
            </CardContent>
          </Card>

          <!-- Irregular student notice -->
          <div v-if="selectedStudent?.is_irregular"
            class="flex items-start gap-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            <AlertTriangle class="h-5 w-5 shrink-0 text-amber-600 mt-0.5" />
            <div>
              <p class="font-semibold">Irregular Student</p>
              <p class="text-amber-800 text-xs mt-0.5">
                Curriculum auto-populate is disabled. Enter lecture units and lab subjects manually
                based on the student's approved load.
              </p>
            </div>
          </div>

          <!-- Units Input -->
          <Card>
            <CardHeader>
              <CardTitle class="flex items-center gap-2 text-base">
                <BookOpen class="h-4 w-4" />
                Units Enrolled
                <span class="ml-auto text-xs font-normal text-muted-foreground">
                  Override if needed — otherwise auto-filled from curriculum
                </span>
              </CardTitle>
            </CardHeader>
            <CardContent class="grid grid-cols-2 gap-6">
              <!-- LEC Units -->
              <div class="space-y-1.5">
                <Label for="lec_units" class="flex items-center gap-1.5">
                  <span class="w-2 h-2 rounded-full bg-blue-500 inline-block"></span>
                  Lecture Units
                  <span class="text-xs text-muted-foreground">(billable only)</span>
                </Label>
                <Input id="lec_units" type="number" v-model.number="form.lec_units"
                  min="0" max="50" class="text-center text-lg font-semibold" />
                <p class="text-xs text-muted-foreground text-center">
                  × {{ formatCurrency(feeRates.tuition_per_unit) }} / unit
                </p>
                <p v-if="form.errors.lec_units" class="text-sm text-destructive">
                  {{ form.errors.lec_units }}
                </p>
              </div>

              <!-- Lab Subjects -->
              <div class="space-y-1.5">
                <Label for="lab_subjects" class="flex items-center gap-1.5">
                  <span class="w-2 h-2 rounded-full bg-orange-500 inline-block"></span>
                  Lab Subjects
                  <span class="text-xs text-muted-foreground">(subjects with lab)</span>
                </Label>
                <Input id="lab_subjects" type="number" v-model.number="form.lab_subjects"
                  min="0" max="20" class="text-center text-lg font-semibold" />
                <p class="text-xs text-muted-foreground text-center">
                  × {{ formatCurrency(feeRates.lab_fee_per_subject) }} / subject
                </p>
                <p v-if="form.errors.lab_subjects" class="text-sm text-destructive">
                  {{ form.errors.lab_subjects }}
                </p>
              </div>
            </CardContent>

            <!-- Billing note -->
            <div class="px-6 pb-4">
              <div class="flex items-start gap-2 rounded-md bg-blue-50 p-3 text-xs text-blue-800">
                <Info class="h-3.5 w-3.5 mt-0.5 shrink-0" />
                <span>
                  <strong>NSTP and PATHFIT/PE</strong> subjects are <strong>excluded</strong>
                  from lecture units per CHED rules.
                  Lab fee is charged once per <em>subject</em> that has a lab component,
                  not per lab unit.
                </span>
              </div>
            </div>
          </Card>

          <!-- Discount Policy -->
          <Card>
            <CardHeader>
              <CardTitle class="text-base flex items-center gap-2">
                <span class="text-amber-600">🎓</span>
                Discount / Scholarship Policy
              </CardTitle>
            </CardHeader>
            <CardContent class="space-y-4">
              <div class="space-y-1.5">
                <Label for="discount_type">Discount Type</Label>
                <select
                  id="discount_type"
                  v-model="form.discount_type"
                  class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs focus:outline-none focus:ring-1 focus:ring-ring"
                >
                  <option value="none">None (Standard billing)</option>
                  <option value="full">Full Scholarship (100% tuition waived)</option>
                  <option value="nstp">NSTP Waiver (tuition fixed at {{ formatCurrency(feeRates.nstp_min_tuition) }})</option>
                </select>
                <p v-if="form.errors.discount_type" class="text-sm text-destructive">
                  {{ form.errors.discount_type }}
                </p>
              </div>

              <!-- NSTP checkbox (only show when discount is active) -->
              <div v-if="form.discount_type !== 'none'"
                class="flex items-center gap-2 p-3 rounded-md bg-amber-50 border border-amber-200">
                <input
                  id="is_taking_nstp"
                  type="checkbox"
                  v-model="form.is_taking_nstp"
                  class="rounded border border-input"
                />
                <Label for="is_taking_nstp" class="cursor-pointer flex-1 text-amber-900">
                  Student is enrolled in NSTP this semester
                  <p class="text-xs text-amber-700 mt-0.5 font-normal">
                    If Full Scholarship + NSTP: tuition floors at {{ formatCurrency(feeRates.nstp_min_tuition) }}
                    (1.5 units × rate). Lab and misc still charged.
                  </p>
                </Label>
              </div>

              <!-- Discount preview -->
              <div v-if="form.discount_type !== 'none'" class="rounded-md bg-green-50 border border-green-200 p-3 space-y-1 text-sm text-green-900">
                <p class="font-semibold text-xs uppercase tracking-wide text-green-700 mb-1">Effective Fees After Discount</p>
                <div class="flex justify-between">
                  <span>Tuition:</span>
                  <span class="font-semibold">{{ formatCurrency(tuitionFee) }}</span>
                </div>
                <div class="flex justify-between">
                  <span>Lab:</span>
                  <span class="font-semibold">{{ formatCurrency(labFee) }}</span>
                </div>
                <div class="flex justify-between">
                  <span>Misc:</span>
                  <span class="font-semibold">{{ formatCurrency(miscFee) }}</span>
                </div>
              </div>
            </CardContent>
          </Card>

          <!-- Submit -->
          <div class="flex gap-3 justify-end">
            <Button variant="outline" @click="router.visit(route('student-fees.index'))">
              Cancel
            </Button>
            <Button
              :disabled="form.processing || !selectedStudent || totalAssessment === 0"
              @click="submit"
            >
              <CheckCircle2 class="mr-2 h-4 w-4" />
              {{ form.processing ? 'Saving…' : 'Create Assessment' }}
            </Button>
          </div>

        </div>

        <!-- ── RIGHT: Live Fee Preview ────────────────────────────── -->
        <div class="space-y-4">

          <!-- Fee Breakdown Card -->
          <Card class="sticky top-6">
            <CardHeader>
              <CardTitle class="text-base flex items-center gap-2">
                <Calculator class="h-4 w-4" />
                Fee Breakdown
              </CardTitle>
            </CardHeader>
            <CardContent class="space-y-3">
              <div class="space-y-2 text-sm">
                <div class="flex justify-between">
                  <span class="text-muted-foreground">
                    Tuition ({{ form.lec_units }} lec units × {{ formatCurrency(feeRates.tuition_per_unit) }})
                  </span>
                  <span class="font-medium">{{ formatCurrency(tuitionFee) }}</span>
                </div>
                <div class="flex justify-between">
                  <span class="text-muted-foreground">
                    Lab Fee ({{ form.lab_subjects }} subjects × {{ formatCurrency(feeRates.lab_fee_per_subject) }})
                  </span>
                  <span class="font-medium">{{ formatCurrency(labFee) }}</span>
                </div>
                <div class="flex justify-between">
                  <span class="text-muted-foreground">Miscellaneous (fixed)</span>
                  <span class="font-medium">{{ formatCurrency(miscFee) }}</span>
                </div>
                <div class="border-t pt-2 flex justify-between font-bold text-base">
                  <span>Total Assessment</span>
                  <span class="text-blue-600">{{ formatCurrency(totalAssessment) }}</span>
                </div>
              </div>

              <!-- Payment Terms Preview -->
              <div v-if="totalAssessment > 0" class="mt-4 border-t pt-3">
                <p class="text-xs font-semibold uppercase text-muted-foreground mb-2">
                  Payment Schedule ({{ feeRates.payment_terms.length }} terms)
                </p>
                <div class="space-y-1.5">
                  <div
                    v-for="term in paymentTermBreakdown"
                    :key="term.term_order"
                    class="flex justify-between text-xs"
                  >
                    <span class="text-muted-foreground">{{ term.term_name }} ({{ term.percentage }}%)</span>
                    <span class="font-medium">{{ formatCurrency(term.amount) }}</span>
                  </div>
                </div>
              </div>

              <!-- Empty state -->
              <div v-else class="text-center py-6 text-muted-foreground text-sm">
                Select a student and semester to compute fees.
              </div>
            </CardContent>
          </Card>

          <!-- Misc Breakdown -->
          <Card v-if="feeRates.misc_items.length > 0" class="bg-muted/50">
            <CardContent class="pt-4 space-y-1 text-xs">
              <p class="font-semibold text-foreground text-sm mb-2">Miscellaneous Breakdown</p>
              <div v-for="item in feeRates.misc_items" :key="item.id" class="flex justify-between text-muted-foreground">
                <span>{{ item.label }}</span>
                <span>{{ formatCurrency(item.amount) }}</span>
              </div>
              <div class="flex justify-between font-semibold text-foreground border-t pt-1 mt-1">
                <span>Total Misc</span>
                <span>{{ formatCurrency(feeRates.misc_total) }}</span>
              </div>
            </CardContent>
          </Card>

          <!-- Rate Info -->
          <Card class="bg-muted/50">
            <CardContent class="pt-4 space-y-1 text-xs text-muted-foreground">
              <p class="font-semibold text-foreground text-sm mb-2">Current Rates (AY 2025-2026)</p>
              <div class="flex justify-between">
                <span>Per lecture unit:</span>
                <span>{{ formatCurrency(feeRates.tuition_per_unit) }}</span>
              </div>
              <div class="flex justify-between">
                <span>Per lab subject:</span>
                <span>{{ formatCurrency(feeRates.lab_fee_per_subject) }}</span>
              </div>
              <div class="flex justify-between">
                <span>NSTP min tuition:</span>
                <span>{{ formatCurrency(feeRates.nstp_min_tuition) }}</span>
              </div>
              <div class="flex justify-between font-medium text-foreground">
                <span>Misc (fixed):</span>
                <span>{{ formatCurrency(feeRates.misc_total) }}</span>
              </div>
              <p class="pt-2 opacity-70">Rates are live from Fee Settings.</p>
            </CardContent>
          </Card>

        </div>
      </div>
    </div>
  </AppLayout>
</template>