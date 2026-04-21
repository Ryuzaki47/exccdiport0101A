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
  Search, User, BookOpen, Calculator,
  CheckCircle2, Loader2, AlertTriangle, Info,
} from 'lucide-vue-next'

// ─── Types ───────────────────────────────────────────────────────────────────

interface FeeRates {
  tuition_per_unit: number
  lab_fee_per_subject: number
  entrepreneurship_fee: number
  misc_total: number
  misc_items: Array<{ id: number; key: string; label: string; amount: number; category: string }>
  payment_terms: Array<{ term_name: string; term_order: number; percentage: number }>
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

const { formatCurrency } = useDataFormatting()

// ─── Breadcrumbs ─────────────────────────────────────────────────────────────

const breadcrumbs = [
  { title: 'Dashboard',      href: route('accounting.dashboard') },
  { title: 'Student Fees',   href: route('student-fees.index') },
  { title: 'New Assessment', href: route('student-fees.create') },
]

// ─── Student Search ───────────────────────────────────────────────────────────

const studentSearch   = ref('')
const searchResults   = ref<PreselectedStudent[]>([])
const searchLoading   = ref(false)
const selectedStudent = ref<PreselectedStudent | null>(props.preselectedStudent ?? null)

let searchTimeout: ReturnType<typeof setTimeout>

async function searchStudents() {
  if (studentSearch.value.length < 2) { searchResults.value = []; return }
  searchLoading.value = true
  clearTimeout(searchTimeout)
  searchTimeout = setTimeout(async () => {
    try {
      const res  = await fetch(route('student-fees.search') + '?q=' + encodeURIComponent(studentSearch.value))
      const data = await res.json()
      searchResults.value = data.students ?? []
    } catch { searchResults.value = [] }
    finally   { searchLoading.value = false }
  }, 300)
}

function selectStudent(student: PreselectedStudent) {
  selectedStudent.value    = student
  searchResults.value      = []
  studentSearch.value      = ''
  form.user_id             = student.id
  curriculumSubjects.value = []
  curriculumMessage.value  = ''
  hasNstp.value            = false
  nstpLecUnits.value       = 0
}

function clearStudent() {
  selectedStudent.value    = null
  form.user_id             = 0
  form.lec_units           = 0
  form.lab_units           = 0   // ← was form.lab_subjects
  curriculumSubjects.value = []
  curriculumMessage.value  = ''
  hasNstp.value            = false
  nstpLecUnits.value       = 0
}

// ─── Curriculum Auto-Populate ─────────────────────────────────────────────────

const curriculumLoading  = ref(false)
const curriculumSubjects = ref<CurriculumSubject[]>([])
const curriculumMessage  = ref('')

const hasNstp      = ref(false)
const nstpLecUnits = ref(0)

async function loadCurriculum() {
  const student = selectedStudent.value
  if (! student || student.is_irregular) {
    curriculumSubjects.value = []
    curriculumMessage.value  = student?.is_irregular ? 'Irregular student — enter units manually.' : ''
    hasNstp.value = false
    nstpLecUnits.value = 0
    return
  }
  if (! form.semester) return

  curriculumLoading.value  = true
  curriculumSubjects.value = []
  curriculumMessage.value  = ''
  hasNstp.value            = false
  nstpLecUnits.value       = 0

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
      form.lab_units    = data.lab_subject_count   // ← was form.lab_subjects
      hasNstp.value     = data.has_nstp ?? false
      nstpLecUnits.value = data.nstp_lec_units ?? 0
    } else {
      curriculumMessage.value = data.message ?? 'No curriculum data found for this student.'
    }
  } catch {
    curriculumMessage.value = 'Could not load curriculum — enter units manually.'
  } finally {
    curriculumLoading.value = false
  }
}

// ─── Form ─────────────────────────────────────────────────────────────────────

const form = useForm({
  user_id:             props.preselectedStudent?.id ?? 0,
  semester:            '1st' as '1st' | '2nd' | 'Summer',
  school_year:         '',
  lec_units:           0,
  lab_units:           0,   // ← was lab_subjects; matches controller validation key
  nstp_lec_units:      0,
  discount_percentage: 0 as number,
})

const currentYear = new Date().getFullYear()
form.school_year  = `${currentYear}-${currentYear + 1}`

watch(nstpLecUnits, (val) => { form.nstp_lec_units = val })

watch([selectedStudent, () => form.semester], () => {
  if (selectedStudent.value && ! selectedStudent.value.is_irregular) loadCurriculum()
})

// ─── Live Fee Computation ──────────────────────────────────────────────────────

const rate = computed(() => props.feeRates.tuition_per_unit)

const rawBillableTuition = computed(() => Number(form.lec_units) * rate.value)
const nstpTuition        = computed(() => nstpLecUnits.value * rate.value)
const entrepreneurFee    = computed(() => Number(form.lab_units) > 0 ? (props.feeRates.entrepreneurship_fee ?? 600) : 0)
const baseLabFee         = computed(() => Number(form.lab_units) * props.feeRates.lab_fee_per_subject)
const miscFee            = computed(() => props.feeRates.misc_total)

const discountSaving = computed(() => {
  const pct = Number(form.discount_percentage) || 0
  return pct > 0 ? Math.round(rawBillableTuition.value * (pct / 100) * 100) / 100 : 0
})

const discountedBillable = computed(() => rawBillableTuition.value - discountSaving.value)
const tuitionFee         = computed(() => discountedBillable.value + nstpTuition.value)
const labFee             = computed(() => baseLabFee.value)
const totalAssessment    = computed(() => tuitionFee.value + labFee.value + entrepreneurFee.value + miscFee.value)

const paymentTermBreakdown = computed(() =>
  props.feeRates.payment_terms.map((t) => ({
    term_name:  t.term_name,
    term_order: t.term_order,
    percentage: t.percentage,
    amount:     Math.round(totalAssessment.value * (t.percentage / 100) * 100) / 100,
  }))
)

// ─── Submit ───────────────────────────────────────────────────────────────────

function submit() {
  if (! selectedStudent.value) return
  form.user_id        = selectedStudent.value.id
  form.nstp_lec_units = nstpLecUnits.value

  const url = route('student-fees.store')
  console.log('[submit] posting to:', url, form.data())

  form.post(url, {
    onError:  (errors)   => console.error('[submit] validation errors:', errors),
    onSuccess: ()        => console.log('[submit] success'),
    onFinish: ()         => console.log('[submit] finished'),
  })
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
                <div v-if="searchResults.length > 0"
                  class="absolute z-20 mt-1 w-full rounded-md border bg-white dark:bg-zinc-900 shadow-lg">
                  <button
                    v-for="s in searchResults" :key="s.id"
                    class="w-full text-left px-4 py-3 hover:bg-accent transition-colors border-b last:border-0"
                    @click="selectStudent(s)"
                  >
                    <p class="font-medium text-sm flex items-center gap-2">
                      {{ s.name }}
                      <span v-if="s.is_irregular" class="text-xs text-amber-700 bg-amber-100 px-1.5 py-0.5 rounded">Irregular</span>
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
                  id="semester" v-model="form.semester"
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

          <!-- Curriculum Auto-Fill (Regular Students) -->
          <div v-if="selectedStudent && !selectedStudent.is_irregular" class="space-y-2">
            <!-- Loading -->
            <div v-if="curriculumLoading"
              class="flex items-center gap-2 rounded-md border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-800">
              <Loader2 class="h-4 w-4 animate-spin shrink-0" />
              Loading curriculum…
            </div>

            <!-- Success — Curriculum Table -->
            <Card v-else-if="curriculumSubjects.length > 0" class="border-green-200">
              <CardHeader class="pb-2">
                <CardTitle class="text-sm flex items-center gap-2 text-green-800">
                  <BookOpen class="h-4 w-4 text-green-600" />
                  Curriculum — {{ selectedStudent.course }}, {{ selectedStudent.year_level }}
                </CardTitle>
              </CardHeader>
              <CardContent class="pt-0">
                <div class="overflow-x-auto rounded-md border border-green-100">
                  <table class="w-full text-sm">
                    <thead class="bg-green-50 text-green-800">
                      <tr>
                        <th class="text-center px-6 py-2 font-semibold text-xs uppercase tracking-wide">LEC</th>
                        <th class="text-center px-6 py-2 font-semibold text-xs uppercase tracking-wide">LAB</th>
                        <th class="text-center px-6 py-2 font-semibold text-xs uppercase tracking-wide">Billable</th>
                      </tr>
                    </thead>
                    <tbody class="divide-y divide-green-50">
                      <tr
                        v-for="subject in curriculumSubjects"
                        :key="subject.id"
                        class="hover:bg-green-50/50 transition-colors"
                      >
                        <td class="text-center px-6 py-2 tabular-nums">{{ subject.lec_units }}</td>
                        <td class="text-center px-6 py-2 tabular-nums">{{ subject.lab_units }}</td>
                        <td class="text-center px-6 py-2">
                          <CheckCircle2 v-if="subject.is_billable" class="h-4 w-4 text-green-500 mx-auto" />
                          <span v-else class="text-muted-foreground">—</span>
                        </td>
                      </tr>
                    </tbody>
                  </table>
                </div>
                <p class="mt-2 text-xs text-green-700 flex items-center gap-1.5">
                  <CheckCircle2 class="h-3.5 w-3.5 text-green-500 shrink-0" />
                  {{ curriculumSubjects.filter(s => s.is_billable).length }} billable subject{{ curriculumSubjects.filter(s => s.is_billable).length !== 1 ? 's' : '' }}
                  · {{ form.lec_units }} LEC unit{{ form.lec_units !== 1 ? 's' : '' }}
                  · {{ form.lab_units }} with lab
                  — <span class="italic">override in Units Enrolled below if needed</span>
                </p>
              </CardContent>
            </Card>

            <!-- Warning / not found -->
            <div v-else-if="curriculumMessage"
              class="flex items-start gap-2 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
              <AlertTriangle class="h-4 w-4 mt-0.5 shrink-0" />
              {{ curriculumMessage }}
            </div>
          </div>

          <!-- Irregular student notice -->
          <div v-if="selectedStudent?.is_irregular"
            class="flex items-start gap-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            <AlertTriangle class="h-5 w-5 shrink-0 text-amber-600 mt-0.5" />
            <div>
              <p class="font-semibold">Irregular Student</p>
              <p class="text-amber-800 text-xs mt-0.5">
                Curriculum auto-populate is disabled. Enter lecture units and lab subjects manually.
              </p>
            </div>
          </div>

          <!-- Units Input -->
          <Card>
            <CardHeader>
              <CardTitle class="flex items-center gap-2 text-base">
                <BookOpen class="h-4 w-4" />
                Units Enrolled
                <span class="ml-auto text-xs font-normal text-muted-foreground">Auto-filled from curriculum — override if needed</span>
              </CardTitle>
            </CardHeader>
            <CardContent class="grid grid-cols-2 gap-6">
              <div class="space-y-1.5">
                <Label for="lec_units" class="flex items-center gap-1.5">
                  <span class="w-2 h-2 rounded-full bg-blue-500 inline-block"></span>
                  Lecture Units
                  <span class="text-xs text-muted-foreground">(billable only)</span>
                </Label>
                <Input id="lec_units" type="number" v-model.number="form.lec_units"
                  min="0" max="50" class="text-center text-lg font-semibold" />
                <p class="text-xs text-muted-foreground text-center">× {{ formatCurrency(feeRates.tuition_per_unit) }} / unit</p>
                <p v-if="form.errors.lec_units" class="text-sm text-destructive">{{ form.errors.lec_units }}</p>
              </div>
              <div class="space-y-1.5">
                <Label for="lab_units" class="flex items-center gap-1.5">
                  <span class="w-2 h-2 rounded-full bg-orange-500 inline-block"></span>
                  Lab Subjects
                  <span class="text-xs text-muted-foreground">(subjects with lab)</span>
                </Label>
                <Input id="lab_units" type="number" v-model.number="form.lab_units"
                  min="0" max="20" class="text-center text-lg font-semibold" />
                <p class="text-xs text-muted-foreground text-center">× {{ formatCurrency(feeRates.lab_fee_per_subject) }} / subject</p>
                <p v-if="form.errors.lab_units" class="text-sm text-destructive">{{ form.errors.lab_units }}</p>
              </div>
            </CardContent>
            <div class="px-6 pb-4">
              <div class="flex items-start gap-2 rounded-md bg-blue-50 p-3 text-xs text-blue-800">
                <Info class="h-3.5 w-3.5 mt-0.5 shrink-0" />
                <span>
                  <strong>NSTP and PATHFIT/PE</strong> subjects are excluded from lecture units per CHED.
                  Lab fee is charged once per subject with a lab component, not per lab unit.
                </span>
              </div>
            </div>
          </Card>

          <!-- ── Discount / Scholarship ─────────────────────────────────────── -->
          <Card>
            <CardHeader>
              <CardTitle class="text-base flex items-center gap-2">
                <span class="text-amber-600">🎓</span>
                Scholarship / Discount
              </CardTitle>
            </CardHeader>
            <CardContent class="space-y-4">

              <div v-if="hasNstp"
                class="flex items-start gap-2 rounded-md bg-amber-50 border border-amber-300 p-3 text-sm text-amber-900">
                <AlertTriangle class="h-4 w-4 mt-0.5 shrink-0 text-amber-600" />
                <div>
                  <p class="font-semibold">NSTP Detected in Curriculum</p>
                  <p class="text-xs text-amber-800 mt-0.5">
                    This student is enrolled in NSTP ({{ nstpLecUnits }} unit{{ nstpLecUnits !== 1 ? 's' : '' }},
                    {{ formatCurrency(nstpLecUnits * feeRates.tuition_per_unit) }}).
                    The NSTP portion is <strong>always billed at full price</strong> regardless of any discount entered below.
                    Only the remaining billable tuition will be discounted.
                  </p>
                </div>
              </div>

              <div class="space-y-3">
                <Label for="discount_percentage">Discount Percentage (%)</Label>
                <p class="text-xs text-muted-foreground -mt-2">
                  Enter 0 for no discount. Applies to billable tuition only — lab and miscellaneous fees are never discounted.
                </p>

                <div class="flex gap-1.5 flex-wrap">
                  <button
                    v-for="preset in [0, 10, 20, 25, 50, 75, 100]"
                    :key="preset"
                    type="button"
                    @click="form.discount_percentage = preset"
                    :class="[
                      'px-3 py-1.5 rounded-md text-xs font-medium border transition-colors',
                      form.discount_percentage === preset
                        ? 'bg-amber-500 text-white border-amber-500 shadow-sm'
                        : 'bg-background border-input text-muted-foreground hover:bg-muted'
                    ]"
                  >
                    {{ preset === 0 ? 'No discount' : preset + '%' }}
                  </button>
                </div>

                <div class="flex items-center gap-3">
                  <Input
                    id="discount_percentage"
                    type="number"
                    v-model.number="form.discount_percentage"
                    min="0"
                    max="100"
                    step="0.01"
                    placeholder="0.00"
                    class="w-28 text-center text-lg font-semibold"
                  />
                  <span class="text-sm text-muted-foreground">% off tuition only</span>
                </div>
                <p v-if="form.errors.discount_percentage" class="text-sm text-destructive">
                  {{ form.errors.discount_percentage }}
                </p>
              </div>

              <div
                v-if="form.discount_percentage > 0"
                class="rounded-md bg-green-50 border border-green-200 p-3 space-y-1.5 text-sm"
              >
                <p class="font-semibold text-xs uppercase tracking-wide text-green-700 mb-2">Effective Fees After Discount</p>

                <template v-if="hasNstp">
                  <div class="flex justify-between text-green-800 text-xs">
                    <span>Billable tuition ({{ form.lec_units }} units, before discount)</span>
                    <span>{{ formatCurrency(rawBillableTuition) }}</span>
                  </div>
                  <div class="flex justify-between text-green-600 text-xs">
                    <span>− {{ form.discount_percentage }}% discount</span>
                    <span>− {{ formatCurrency(discountSaving) }}</span>
                  </div>
                  <div class="flex justify-between text-green-800 text-xs">
                    <span>NSTP tuition ({{ nstpLecUnits }} units — full price, not discounted)</span>
                    <span>{{ formatCurrency(nstpTuition) }}</span>
                  </div>
                  <div class="flex justify-between text-green-900 font-medium pt-1 border-t border-green-200">
                    <span>Total Tuition</span>
                    <span>{{ formatCurrency(tuitionFee) }}</span>
                  </div>
                </template>
                <template v-else>
                  <div class="flex justify-between text-green-900">
                    <span>Tuition (after {{ form.discount_percentage }}% discount)</span>
                    <span class="font-semibold">{{ formatCurrency(tuitionFee) }}</span>
                  </div>
                  <div class="flex justify-between text-green-600 text-xs">
                    <span>You save</span>
                    <span class="font-semibold">− {{ formatCurrency(discountSaving) }}</span>
                  </div>
                </template>

                <div class="flex justify-between text-green-900 pt-1">
                  <span>Lab Fee ({{ form.lab_units }} subjects)</span>
                  <span class="font-semibold">{{ formatCurrency(labFee) }}</span>
                </div>
                <div v-if="entrepreneurFee > 0" class="flex justify-between text-green-900">
                  <span>Entrepreneurship Fee</span>
                  <span class="font-semibold">{{ formatCurrency(entrepreneurFee) }}</span>
                </div>
                <div class="flex justify-between text-green-900">
                  <span>Miscellaneous Fee</span>
                  <span class="font-semibold">{{ formatCurrency(miscFee) }}</span>
                </div>
                <div class="border-t border-green-300 pt-2 flex justify-between font-bold text-green-900 text-base">
                  <span>Total Assessment</span>
                  <span>{{ formatCurrency(totalAssessment) }}</span>
                </div>
              </div>

            </CardContent>
          </Card>

          <!-- Submit -->
          <div class="flex gap-3 justify-end">
            <Button variant="outline" @click="router.visit(route('student-fees.index'))">Cancel</Button>
            <button
              type="button"
              :disabled="form.processing || !selectedStudent || totalAssessment === 0"
              class="inline-flex items-center justify-center gap-2 rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground shadow-xs transition-all hover:bg-primary/90 disabled:pointer-events-none disabled:opacity-50"
              @click.prevent="submit"
            >
              <Loader2 v-if="form.processing" class="h-4 w-4 animate-spin" />
              <CheckCircle2 v-else class="h-4 w-4" />
              {{ form.processing ? 'Saving…' : 'Create Assessment' }}
            </button>
          </div>

        </div>

        <!-- ── RIGHT: Live Fee Preview ─────────────────────────────── -->
        <div class="space-y-4">
          <Card class="sticky top-6">
            <CardHeader>
              <CardTitle class="text-base flex items-center gap-2">
                <Calculator class="h-4 w-4" /> Fee Breakdown
              </CardTitle>
            </CardHeader>
            <CardContent class="space-y-3 text-sm">

              <div class="space-y-2">
                <div class="flex justify-between">
                  <span class="text-muted-foreground">
                    Tuition ({{ form.lec_units }} lec × {{ formatCurrency(feeRates.tuition_per_unit) }})
                  </span>
                  <span class="font-medium">{{ formatCurrency(tuitionFee) }}</span>
                </div>
                <div v-if="hasNstp && nstpLecUnits > 0" class="flex justify-between text-xs text-amber-700 pl-2">
                  <span>incl. NSTP {{ nstpLecUnits }}u × {{ formatCurrency(feeRates.tuition_per_unit) }} (full)</span>
                  <span>{{ formatCurrency(nstpTuition) }}</span>
                </div>
                <div v-if="discountSaving > 0" class="flex justify-between text-xs text-green-600 pl-2">
                  <span>− {{ form.discount_percentage }}% discount saved</span>
                  <span>− {{ formatCurrency(discountSaving) }}</span>
                </div>
                <div class="flex justify-between">
                  <span class="text-muted-foreground">
                    Lab Fee ({{ form.lab_units }} subj × {{ formatCurrency(feeRates.lab_fee_per_subject) }})
                  </span>
                  <span class="font-medium">{{ formatCurrency(labFee) }}</span>
                </div>
                <div v-if="entrepreneurFee > 0" class="flex justify-between">
                  <span class="text-muted-foreground">Entrepreneurship Fee</span>
                  <span class="font-medium">{{ formatCurrency(entrepreneurFee) }}</span>
                </div>
                <div class="flex justify-between">
                  <span class="text-muted-foreground">Miscellaneous (fixed)</span>
                  <span class="font-medium">{{ formatCurrency(miscFee) }}</span>
                </div>
              </div>

              <div class="border-t pt-2 flex justify-between font-bold text-base">
                <span>Total Assessment</span>
                <span class="text-blue-600">{{ formatCurrency(totalAssessment) }}</span>
              </div>

              <div v-if="totalAssessment > 0" class="mt-3 border-t pt-3">
                <p class="text-xs font-semibold uppercase text-muted-foreground mb-2">
                  Payment Schedule ({{ feeRates.payment_terms.length }} terms)
                </p>
                <div class="space-y-1.5">
                  <div v-for="term in paymentTermBreakdown" :key="term.term_order" class="flex justify-between text-xs">
                    <span class="text-muted-foreground">{{ term.term_name }} ({{ term.percentage }}%)</span>
                    <span class="font-medium">{{ formatCurrency(term.amount) }}</span>
                  </div>
                </div>
              </div>

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