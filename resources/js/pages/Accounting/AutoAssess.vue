<script setup lang="ts">
import { ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/layouts/AppLayout.vue'
import Breadcrumbs from '@/components/Breadcrumbs.vue'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { useDataFormatting } from '@/composables/useDataFormatting'
import {
  Zap, BookOpen, Users, CheckCircle2, AlertTriangle,
  Loader2, ChevronRight, Info, SkipForward,
} from 'lucide-vue-next'

// ─── Types ────────────────────────────────────────────────────────────────────

interface FeeRates {
  tuition_per_unit: number
  lab_fee_per_subject: number
  misc_total: number
  nstp_min_tuition: number
}

interface StudentPreview {
  id: number
  name: string
  account_id: string
  skip: boolean
  skip_reason: string | null
  fees: { tuition_fee: number; lab_fee: number; misc_fee: number; total: number } | null
}

interface PreviewResult {
  ok: boolean
  message: string | null
  curriculum: {
    billable_lec_units: number
    lab_subject_count: number
    nstp_units: number
    pathfit_units: number
    subject_count: number
  } | null
  summary: { total: number; eligible: number; skipped: number } | null
  fees_preview: { tuition_fee: number; lab_fee: number; misc_fee: number; total: number } | null
  students: StudentPreview[]
}

// ─── Props ────────────────────────────────────────────────────────────────────

const props = defineProps<{
  availableCourses: string[]
  feeRates: FeeRates
  currentSchoolYear: string
}>()

// ─── Composables ─────────────────────────────────────────────────────────────

const { formatCurrency } = useDataFormatting()

// ─── Breadcrumbs ─────────────────────────────────────────────────────────────

const breadcrumbs = [
  { title: 'Dashboard', href: route('accounting.dashboard') },
  { title: 'Auto-Assessment', href: route('accounting.auto-assess.index') },
]

// ─── Form State ───────────────────────────────────────────────────────────────

const form = ref({
  course:       '',
  year_level:   '1st Year',
  semester:     '1st',
  school_year:  props.currentSchoolYear,
  discount_type: 'none',
})

const yearLevels = ['1st Year', '2nd Year', '3rd Year', '4th Year', '5th Year']
const semesters  = [
  { value: '1st',    label: '1st Semester' },
  { value: '2nd',    label: '2nd Semester' },
  { value: 'Summer', label: 'Summer' },
]
const discountOptions = [
  { value: 'none', label: 'None (Standard billing)' },
  { value: 'full', label: 'Full Scholarship (tuition waived)' },
  { value: 'nstp', label: 'NSTP Waiver (tuition fixed at minimum)' },
]

// ─── State Machine ────────────────────────────────────────────────────────────

type Step = 'form' | 'previewing' | 'preview' | 'generating' | 'done'
const step = ref<Step>('form')

const previewResult  = ref<PreviewResult | null>(null)
const previewError   = ref('')
const generateError  = ref('')
const generateResult = ref<{ created: number; skipped: number } | null>(null)

// ─── Computed ─────────────────────────────────────────────────────────────────

const canPreview = computed(() =>
  form.value.course && form.value.year_level && form.value.semester && form.value.school_year
)

const eligibleCount = computed(() => previewResult.value?.summary?.eligible ?? 0)

// ─── Actions ──────────────────────────────────────────────────────────────────

async function runPreview() {
  previewError.value = ''
  step.value = 'previewing'

  try {
    const res = await fetch(route('accounting.auto-assess.preview'), {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '',
        'Accept': 'application/json',
      },
      body: JSON.stringify(form.value),
    })

    const data: PreviewResult = await res.json()
    previewResult.value = data

    if (!data.ok) {
      previewError.value = data.message ?? 'Preview failed.'
      step.value = 'form'
      return
    }

    step.value = 'preview'
  } catch {
    previewError.value = 'Network error — please try again.'
    step.value = 'form'
  }
}

async function runGenerate() {
  generateError.value = ''
  step.value = 'generating'

  try {
    const res = await fetch(route('accounting.auto-assess.generate'), {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '',
        'Accept': 'application/json',
      },
      body: JSON.stringify(form.value),
    })

    const data = await res.json()

    if (!data.ok) {
      generateError.value = data.message ?? 'Generation failed.'
      step.value = 'preview'
      return
    }

    generateResult.value = { created: data.created, skipped: data.skipped }
    step.value = 'done'
  } catch {
    generateError.value = 'Network error — please try again.'
    step.value = 'preview'
  }
}

function reset() {
  step.value = 'form'
  previewResult.value = null
  previewError.value = ''
  generateError.value = ''
  generateResult.value = null
}
</script>

<template>
  <AppLayout>
    <div class="w-full p-6 space-y-6 max-w-5xl">
      <Breadcrumbs :items="breadcrumbs" />

      <div class="flex items-center gap-3">
        <Zap class="h-6 w-6 text-blue-600" />
        <div>
          <h1 class="text-2xl font-bold">Auto-Generate Assessments</h1>
          <p class="text-sm text-muted-foreground mt-0.5">
            Batch-create fee assessments for all regular students in a course cohort.
          </p>
        </div>
      </div>

      <!-- ── Step indicator ─────────────────────────────────────────────────── -->
      <div class="flex items-center gap-2 text-sm">
        <span :class="['font-medium', step === 'form' ? 'text-blue-600' : 'text-muted-foreground']">
          1. Configure
        </span>
        <ChevronRight class="h-4 w-4 text-muted-foreground" />
        <span :class="['font-medium', step === 'preview' ? 'text-blue-600' : 'text-muted-foreground']">
          2. Preview
        </span>
        <ChevronRight class="h-4 w-4 text-muted-foreground" />
        <span :class="['font-medium', step === 'done' ? 'text-green-600' : 'text-muted-foreground']">
          3. Done
        </span>
      </div>

      <!-- ── FORM STEP ───────────────────────────────────────────────────────── -->
      <div v-if="step === 'form' || step === 'previewing'" class="grid grid-cols-1 xl:grid-cols-3 gap-6">
        <div class="xl:col-span-2 space-y-4">
          <Card>
            <CardHeader>
              <CardTitle class="flex items-center gap-2 text-base">
                <BookOpen class="h-4 w-4" /> Cohort Selection
              </CardTitle>
            </CardHeader>
            <CardContent class="grid grid-cols-2 gap-4">
              <!-- Course -->
              <div class="space-y-1.5 col-span-2">
                <label class="text-sm font-medium">Course</label>
                <select
                  v-model="form.course"
                  class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs focus:outline-none focus:ring-1 focus:ring-ring"
                >
                  <option value="">— Select course —</option>
                  <option v-for="c in availableCourses" :key="c" :value="c">{{ c }}</option>
                </select>
              </div>

              <!-- Year Level -->
              <div class="space-y-1.5">
                <label class="text-sm font-medium">Year Level</label>
                <select
                  v-model="form.year_level"
                  class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs focus:outline-none focus:ring-1 focus:ring-ring"
                >
                  <option v-for="y in yearLevels" :key="y" :value="y">{{ y }}</option>
                </select>
              </div>

              <!-- Semester -->
              <div class="space-y-1.5">
                <label class="text-sm font-medium">Semester</label>
                <select
                  v-model="form.semester"
                  class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs focus:outline-none focus:ring-1 focus:ring-ring"
                >
                  <option v-for="s in semesters" :key="s.value" :value="s.value">{{ s.label }}</option>
                </select>
              </div>

              <!-- School Year -->
              <div class="space-y-1.5 col-span-2">
                <label class="text-sm font-medium">School Year</label>
                <input
                  v-model="form.school_year"
                  type="text"
                  placeholder="e.g. 2025-2026"
                  class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs focus:outline-none focus:ring-1 focus:ring-ring"
                />
              </div>

              <!-- Discount -->
              <div class="space-y-1.5 col-span-2">
                <label class="text-sm font-medium">Batch Discount</label>
                <select
                  v-model="form.discount_type"
                  class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs focus:outline-none focus:ring-1 focus:ring-ring"
                >
                  <option v-for="d in discountOptions" :key="d.value" :value="d.value">{{ d.label }}</option>
                </select>
                <p class="text-xs text-muted-foreground">
                  Applies the same discount to every student in this cohort.
                  For individual discounts, use the manual assessment form.
                </p>
              </div>
            </CardContent>
          </Card>

          <!-- Error -->
          <div v-if="previewError"
            class="flex items-start gap-2 rounded-md bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-800">
            <AlertTriangle class="h-4 w-4 mt-0.5 shrink-0" />
            {{ previewError }}
          </div>

          <!-- Note -->
          <div class="flex items-start gap-2 rounded-md bg-blue-50 border border-blue-200 px-4 py-3 text-sm text-blue-800">
            <Info class="h-4 w-4 mt-0.5 shrink-0" />
            <span>
              Only <strong>regular, active</strong> students are included.
              Irregular students must be assessed manually.
              Students who already have an active assessment for this term are skipped.
            </span>
          </div>

          <div class="flex justify-end">
            <Button
              :disabled="!canPreview || step === 'previewing'"
              @click="runPreview"
            >
              <Loader2 v-if="step === 'previewing'" class="mr-2 h-4 w-4 animate-spin" />
              <Zap v-else class="mr-2 h-4 w-4" />
              {{ step === 'previewing' ? 'Checking…' : 'Preview Students' }}
            </Button>
          </div>
        </div>

        <!-- Right: Rate info -->
        <Card class="sticky top-6 h-fit bg-muted/50">
          <CardContent class="pt-4 space-y-2 text-xs text-muted-foreground">
            <p class="font-semibold text-foreground text-sm mb-2">Current Fee Rates</p>
            <div class="flex justify-between">
              <span>Per lecture unit:</span>
              <span class="font-mono">{{ formatCurrency(feeRates.tuition_per_unit) }}</span>
            </div>
            <div class="flex justify-between">
              <span>Per lab subject:</span>
              <span class="font-mono">{{ formatCurrency(feeRates.lab_fee_per_subject) }}</span>
            </div>
            <div class="flex justify-between">
              <span>Misc (fixed):</span>
              <span class="font-mono">{{ formatCurrency(feeRates.misc_total) }}</span>
            </div>
            <div class="flex justify-between">
              <span>NSTP minimum:</span>
              <span class="font-mono">{{ formatCurrency(feeRates.nstp_min_tuition) }}</span>
            </div>
            <p class="pt-2 text-xs opacity-60">Rates are live from Fee Settings.</p>
          </CardContent>
        </Card>
      </div>

      <!-- ── PREVIEW STEP ────────────────────────────────────────────────────── -->
      <div v-if="step === 'preview' && previewResult" class="space-y-5">

        <!-- Summary cards -->
        <div class="grid grid-cols-3 gap-4">
          <Card>
            <CardContent class="pt-4 text-center">
              <p class="text-3xl font-bold text-foreground">{{ previewResult.summary?.total }}</p>
              <p class="text-xs text-muted-foreground mt-1 flex items-center justify-center gap-1">
                <Users class="h-3 w-3" /> Total students found
              </p>
            </CardContent>
          </Card>
          <Card class="border-green-200 bg-green-50">
            <CardContent class="pt-4 text-center">
              <p class="text-3xl font-bold text-green-700">{{ previewResult.summary?.eligible }}</p>
              <p class="text-xs text-green-600 mt-1 flex items-center justify-center gap-1">
                <CheckCircle2 class="h-3 w-3" /> Will be assessed
              </p>
            </CardContent>
          </Card>
          <Card class="border-amber-200 bg-amber-50">
            <CardContent class="pt-4 text-center">
              <p class="text-3xl font-bold text-amber-700">{{ previewResult.summary?.skipped }}</p>
              <p class="text-xs text-amber-600 mt-1 flex items-center justify-center gap-1">
                <SkipForward class="h-3 w-3" /> Already assessed
              </p>
            </CardContent>
          </Card>
        </div>

        <!-- Fee breakdown for this cohort -->
        <Card v-if="previewResult.fees_preview">
          <CardHeader>
            <CardTitle class="text-base">Fee Breakdown (per student, this cohort)</CardTitle>
          </CardHeader>
          <CardContent class="space-y-2 text-sm">
            <div class="flex justify-between">
              <span class="text-muted-foreground">
                Tuition ({{ previewResult.curriculum?.billable_lec_units }} billable LEC units)
              </span>
              <span class="font-medium">{{ formatCurrency(previewResult.fees_preview.tuition_fee) }}</span>
            </div>
            <div class="flex justify-between">
              <span class="text-muted-foreground">
                Lab Fee ({{ previewResult.curriculum?.lab_subject_count }} subjects)
              </span>
              <span class="font-medium">{{ formatCurrency(previewResult.fees_preview.lab_fee) }}</span>
            </div>
            <div class="flex justify-between">
              <span class="text-muted-foreground">Miscellaneous (fixed)</span>
              <span class="font-medium">{{ formatCurrency(previewResult.fees_preview.misc_fee) }}</span>
            </div>
            <div class="border-t pt-2 flex justify-between font-bold text-base">
              <span>Total Assessment</span>
              <span class="text-blue-600">{{ formatCurrency(previewResult.fees_preview.total) }}</span>
            </div>
          </CardContent>
        </Card>

        <!-- Curriculum info -->
        <Card v-if="previewResult.curriculum" class="bg-muted/30">
          <CardContent class="pt-4 text-xs text-muted-foreground grid grid-cols-4 gap-3">
            <div class="text-center">
              <p class="text-lg font-bold text-foreground">{{ previewResult.curriculum.subject_count }}</p>
              <p>Total subjects</p>
            </div>
            <div class="text-center">
              <p class="text-lg font-bold text-green-700">{{ previewResult.curriculum.billable_lec_units }}</p>
              <p>Billable LEC units</p>
            </div>
            <div class="text-center">
              <p class="text-lg font-bold text-orange-600">{{ previewResult.curriculum.lab_subject_count }}</p>
              <p>Lab subjects</p>
            </div>
            <div class="text-center">
              <p class="text-lg font-bold text-amber-600">{{ previewResult.curriculum.nstp_units }}</p>
              <p>NSTP units (excluded)</p>
            </div>
          </CardContent>
        </Card>

        <!-- Student list -->
        <Card>
          <CardHeader>
            <CardTitle class="text-base">Students</CardTitle>
          </CardHeader>
          <CardContent class="p-0">
            <div class="rounded-b-lg overflow-hidden">
              <table class="w-full text-sm">
                <thead class="bg-muted text-muted-foreground text-xs">
                  <tr>
                    <th class="text-left px-4 py-2">Student</th>
                    <th class="text-left px-4 py-2">Account ID</th>
                    <th class="text-right px-4 py-2">Total</th>
                    <th class="text-center px-4 py-2">Status</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-border">
                  <tr v-for="s in previewResult.students" :key="s.id"
                    :class="s.skip ? 'opacity-50' : 'hover:bg-muted/40'">
                    <td class="px-4 py-2.5 font-medium">{{ s.name }}</td>
                    <td class="px-4 py-2.5 text-muted-foreground font-mono text-xs">{{ s.account_id }}</td>
                    <td class="px-4 py-2.5 text-right font-mono">
                      <span v-if="!s.skip">{{ formatCurrency(s.fees!.total) }}</span>
                      <span v-else class="text-muted-foreground">—</span>
                    </td>
                    <td class="px-4 py-2.5 text-center">
                      <span v-if="s.skip"
                        class="inline-flex items-center gap-1 text-xs text-amber-700 bg-amber-100 px-2 py-0.5 rounded-full">
                        <SkipForward class="h-3 w-3" /> Skip
                      </span>
                      <span v-else
                        class="inline-flex items-center gap-1 text-xs text-green-700 bg-green-100 px-2 py-0.5 rounded-full">
                        <CheckCircle2 class="h-3 w-3" /> Generate
                      </span>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </CardContent>
        </Card>

        <!-- Generate error -->
        <div v-if="generateError"
          class="flex items-start gap-2 rounded-md bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-800">
          <AlertTriangle class="h-4 w-4 mt-0.5 shrink-0" />
          {{ generateError }}
        </div>

        <!-- Actions -->
        <div class="flex items-center justify-between">
          <Button variant="outline" @click="reset">← Back</Button>

          <div class="flex items-center gap-3">
            <p v-if="eligibleCount === 0" class="text-sm text-muted-foreground">
              No eligible students — nothing to generate.
            </p>
            <Button
              v-if="eligibleCount > 0"
              :disabled="step === 'generating'"
              @click="runGenerate"
              class="bg-green-600 hover:bg-green-700"
            >
              <Loader2 v-if="step === 'generating'" class="mr-2 h-4 w-4 animate-spin" />
              <Zap v-else class="mr-2 h-4 w-4" />
              {{ step === 'generating' ? 'Generating…' : `Generate ${eligibleCount} Assessments` }}
            </Button>
          </div>
        </div>
      </div>

      <!-- ── DONE STEP ───────────────────────────────────────────────────────── -->
      <div v-if="step === 'done' && generateResult" class="space-y-5">
        <Card class="border-green-200 bg-green-50">
          <CardContent class="pt-6 text-center space-y-3">
            <CheckCircle2 class="h-12 w-12 text-green-600 mx-auto" />
            <h2 class="text-xl font-bold text-green-800">Assessments Generated</h2>
            <p class="text-green-700">
              <span class="font-bold text-2xl">{{ generateResult.created }}</span> assessments created ·
              <span class="font-bold text-2xl">{{ generateResult.skipped }}</span> skipped (already assessed)
            </p>
          </CardContent>
        </Card>

        <div class="flex items-center justify-center gap-4">
          <Button variant="outline" @click="reset">
            Generate Another Batch
          </Button>
          <Button @click="router.visit(route('student-fees.index'))">
            View Student Fees
          </Button>
        </div>
      </div>

    </div>
  </AppLayout>
</template>