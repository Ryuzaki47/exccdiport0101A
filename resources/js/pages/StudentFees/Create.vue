<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useForm, usePage } from '@inertiajs/vue3'
import AppLayout from '@/layouts/AppLayout.vue'
import Breadcrumbs from '@/components/Breadcrumbs.vue'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { useDataFormatting } from '@/composables/useDataFormatting'
import { Search, User, BookOpen, FlaskConical, Calculator, CheckCircle2 } from 'lucide-vue-next'
import axios from 'axios'

// ─── Props ───────────────────────────────────────────────────────────────────

interface FeeRates {
  tuition_per_lec_unit: number
  lab_fee_per_unit: number
  misc_fee_fixed: number
  payment_terms: Array<{ term_name: string; term_order: number; percentage: number }>
}

interface PreselectedStudent {
  id: number
  name: string
  account_id: string
  course: string
  year_level: string
}

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

const studentSearch     = ref('')
const searchResults     = ref<PreselectedStudent[]>([])
const searchLoading     = ref(false)
const selectedStudent   = ref<PreselectedStudent | null>(props.preselectedStudent ?? null)

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
      const res = await fetch(route('student-fees.search') + '?q=' + encodeURIComponent(studentSearch.value))
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
  selectedStudent.value  = student
  searchResults.value    = []
  studentSearch.value    = ''
  form.user_id           = student.id
}

function clearStudent() {
  selectedStudent.value = null
  form.user_id          = 0
  form.lec_units        = 0
  form.lab_units        = 0
}

// ─── Auto-fill units from previous assessment ─────────────────────────────
watch(selectedStudent, async (student) => {
  if (!student?.id) return

  try {
    const res = await axios.get(route('student-fees.latest-assessment'), {
      params: { student_id: student.id }
    })
    if (res.data.found) {
      form.lec_units = res.data.lec_units
      form.lab_units = res.data.lab_units
    }
  } catch {
    // silent fail — accounting can still input manually
  }
})

// ─── Form ─────────────────────────────────────────────────────────────────────

const form = useForm({
  user_id:     props.preselectedStudent?.id ?? 0,
  semester:    '1st' as '1st' | '2nd' | 'Summer',
  school_year: '',
  lec_units:   0,
  lab_units:   0,
})

// Pre-fill school year (current AY format e.g. "2025-2026")
const currentYear = new Date().getFullYear()
form.school_year  = `${currentYear}-${currentYear + 1}`

// ─── Live Fee Computation ─────────────────────────────────────────────────────

const tuitionFee = computed(() =>
  Number(form.lec_units) * props.feeRates.tuition_per_lec_unit
)

const labFee = computed(() =>
  Number(form.lab_units) * props.feeRates.lab_fee_per_unit
)

const miscFee = computed(() => props.feeRates.misc_fee_fixed)

const totalAssessment = computed(() =>
  tuitionFee.value + labFee.value + miscFee.value
)

// Total units = lec + lab (informational, matches what's on the matriculation form)
const totalUnits = computed(() =>
  Number(form.lec_units) + Number(form.lab_units)
)

// Compute per-term amounts from config percentages
const paymentTermBreakdown = computed(() =>
  props.feeRates.payment_terms.map((t) => ({
    term_name:  t.term_name,
    term_order: t.term_order,
    percentage: t.percentage,
    amount:     Math.round(totalAssessment.value * (t.percentage / 100) * 100) / 100,
  }))
)

// ─── Submit ───────────────────────────────────────────────────────────────────

const page = usePage()

function submit() {
  if (!selectedStudent.value) return
  form.user_id = selectedStudent.value.id
  form.post(route('student-fees.store'), {
    headers: {
      'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
    },
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
              <!-- Already selected -->
              <div v-if="selectedStudent" class="flex items-center justify-between rounded-lg border bg-blue-50 dark:bg-blue-950 p-4">
                <div>
                  <p class="font-semibold text-blue-900 dark:text-blue-100">{{ selectedStudent.name }}</p>
                  <p class="text-sm text-blue-700 dark:text-blue-300">
                    {{ selectedStudent.account_id }} · {{ selectedStudent.course }} · {{ selectedStudent.year_level }}
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
                </div>
                <!-- Dropdown results -->
                <div
                  v-if="searchResults.length > 0"
                  class="absolute z-20 mt-1 w-full rounded-md border bg-white dark:bg-zinc-900 shadow-lg"
                >
                  <button
                    v-for="s in searchResults"
                    :key="s.id"
                    class="w-full text-left px-4 py-3 hover:bg-accent transition-colors border-b last:border-0"
                    @click="selectStudent(s)"
                  >
                    <p class="font-medium text-sm">{{ s.name }}</p>
                    <p class="text-xs text-muted-foreground">{{ s.account_id }} · {{ s.course }}</p>
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

          <!-- Units Input — the core of this new design -->
          <Card>
            <CardHeader>
              <CardTitle class="flex items-center gap-2 text-base">
                <BookOpen class="h-4 w-4" />
                Units Enrolled
                <span class="ml-auto text-xs font-normal text-muted-foreground">
                  Based on student's matriculation form / white form
                </span>
              </CardTitle>
            </CardHeader>
            <CardContent class="grid grid-cols-2 gap-6">

                <!-- LEC Units -->
                <div class="space-y-1.5">
                    <Label for="lec_units" class="flex items-center gap-1.5">
                    <span class="w-2 h-2 rounded-full bg-blue-500 inline-block"></span>
                    Lecture Units
                    </Label>
                    <Input id="lec_units" type="number" v-model.number="form.lec_units"
                    min="0" max="30" class="text-center text-lg font-semibold" />
                    <p class="text-xs text-muted-foreground text-center">
                    × {{ formatCurrency(feeRates.tuition_per_lec_unit) }} / unit
                    </p>
                    <p v-if="form.errors.lec_units" class="text-sm text-destructive">
                    {{ form.errors.lec_units }}
                    </p>
                </div>

                <!-- LAB Units -->
                <div class="space-y-1.5">
                    <Label for="lab_units" class="flex items-center gap-1.5">
                    <span class="w-2 h-2 rounded-full bg-orange-500 inline-block"></span>
                    Lab Units
                    </Label>
                    <Input id="lab_units" type="number" v-model.number="form.lab_units"
                    min="0" max="10" class="text-center text-lg font-semibold" />
                    <p class="text-xs text-muted-foreground text-center">
                    × {{ formatCurrency(feeRates.lab_fee_per_unit) }} / unit
                    </p>
                    <p v-if="form.errors.lab_units" class="text-sm text-destructive">
                    {{ form.errors.lab_units }}
                    </p>
                </div>

                </CardContent>

            <!-- Total units badge -->
            <div class="px-6 pb-4">
              <div class="flex items-center justify-center gap-2 rounded-md bg-muted py-2 text-sm">
                <span class="text-muted-foreground">Total units:</span>
                <span class="font-bold text-base">{{ totalUnits }}</span>
                <span class="text-muted-foreground text-xs">({{ form.lec_units }} LEC + {{ form.lab_units }} LAB)</span>
              </div>
            </div>
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
                    Tuition ({{ form.lec_units }} units × {{ formatCurrency(feeRates.tuition_per_lec_unit) }})
                  </span>
                  <span class="font-medium">{{ formatCurrency(tuitionFee) }}</span>
                </div>
                <div class="flex justify-between">
                  <span class="text-muted-foreground">
                    Lab Fee ({{ form.lab_units }} units × {{ formatCurrency(feeRates.lab_fee_per_unit) }})
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
                Enter units above to see the fee computation.
              </div>

            </CardContent>
          </Card>

          <!-- Rate Info -->
          <Card class="bg-muted/50">
            <CardContent class="pt-4 space-y-1 text-xs text-muted-foreground">
              <p class="font-semibold text-foreground text-sm mb-2">Current Rates (AY 2025-2026)</p>
              <div class="flex justify-between">
                <span>Per lecture unit:</span>
                <span>{{ formatCurrency(feeRates.tuition_per_lec_unit) }}</span>
              </div>
              <div class="flex justify-between">
                <span>Per lab unit:</span>
                <span>{{ formatCurrency(feeRates.lab_fee_per_unit) }}</span>
              </div>
              <div class="flex justify-between">
                <span>Misc fee (fixed):</span>
                <span>{{ formatCurrency(feeRates.misc_fee_fixed) }}</span>
              </div>
              <p class="pt-2 text-xs opacity-70">
                Rates approved March 4, 2025 (+15% from AY 2024-2025).
              </p>
            </CardContent>
          </Card>

        </div>
      </div>
    </div>
  </AppLayout>
</template>