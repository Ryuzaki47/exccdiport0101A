<script setup lang="ts">
import { computed, watch } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import AppLayout from '@/layouts/AppLayout.vue'
import Breadcrumbs from '@/components/Breadcrumbs.vue'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { useDataFormatting } from '@/composables/useDataFormatting'
import { BookOpen, Calculator, Save, AlertTriangle, Info } from 'lucide-vue-next'

// ─── Types ────────────────────────────────────────────────────────────────────

interface FeeRates {
  tuition_per_unit: number
  lab_fee_per_subject: number
  entrepreneurship_fee: number
  misc_total: number
  misc_items: Array<{ id: number; key: string; label: string; amount: number; category: string }>
  payment_terms: Array<{ term_name: string; term_order: number; percentage: number }>
}

// ─── Props ────────────────────────────────────────────────────────────────────

const props = defineProps<{
  student: {
    id: number
    name: string
    account_id: string
    course: string
    year_level: string
  }
  assessment: {
    id: number
    semester: string
    school_year: string
    lec_units: number
    nstp_units: number          // NSTP lecture units stored on assessment
    lab_units: number
    discount_percentage?: number
    is_taking_nstp?: boolean
  }
  feeRates: FeeRates
}>()

const { formatCurrency } = useDataFormatting()

// ─── Breadcrumbs ──────────────────────────────────────────────────────────────

const breadcrumbs = [
  { title: 'Dashboard',        href: route('accounting.dashboard') },
  { title: 'Student Fees',     href: route('student-fees.index') },
  { title: props.student.name, href: route('student-fees.show', props.student.id) },
  { title: 'Edit Assessment',  href: route('student-fees.edit', props.student.id) },
]

// ─── Form ─────────────────────────────────────────────────────────────────────

// nstp_lec_units comes from assessment.nstp_units (stored at creation time)
// or falls back to is_taking_nstp legacy field
const nstpLecUnits = props.assessment.nstp_units
  ?? (props.assessment.is_taking_nstp ? 2 : 0) // legacy fallback: approximate with 2 units

const form = useForm({
  semester:            props.assessment.semester,
  school_year:         props.assessment.school_year,
  lec_units:           props.assessment.lec_units,
  lab_units:           props.assessment.lab_units,
  nstp_lec_units:      nstpLecUnits,
  discount_percentage: props.assessment.discount_percentage ?? 0,
})

// ─── Live Fee Computation — mirrors AssessmentService::compute() ───────────────
//
//   NSTP tuition is always at full price, never discounted.
//   Discount applies only to billable (non-NSTP) tuition.

const rate = props.feeRates.tuition_per_unit

const rawBillableTuition = computed(() => Number(form.lec_units) * rate)
const nstpTuition        = computed(() => Number(form.nstp_lec_units) * rate)
const hasNstp            = computed(() => Number(form.nstp_lec_units) > 0)
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
const total              = computed(() => tuitionFee.value + labFee.value + entrepreneurFee.value + miscFee.value)

const paymentTermBreakdown = computed(() =>
  props.feeRates.payment_terms.map((t) => ({
    term_name:  t.term_name,
    percentage: t.percentage,
    amount:     Math.round(total.value * (t.percentage / 100) * 100) / 100,
  }))
)

// ─── Submit ───────────────────────────────────────────────────────────────────

function submit() {
  form.put(route('student-fees.update', props.student.id))
}
</script>

<template>
  <AppLayout>
    <div class="w-full p-6 space-y-6">
      <Breadcrumbs :items="breadcrumbs" />

      <div>
        <h1 class="text-2xl font-bold">Edit Assessment — {{ student.name }}</h1>
        <p class="text-muted-foreground text-sm mt-1">
          {{ student.account_id }} · {{ student.course }} · {{ student.year_level }}
        </p>
      </div>

      <!-- Warning banner -->
      <div class="flex items-start gap-3 rounded-lg border border-yellow-300 bg-yellow-50 dark:bg-yellow-950/40 p-4 text-sm">
        <AlertTriangle class="h-4 w-4 text-yellow-600 mt-0.5 shrink-0" />
        <div>
          <p class="font-semibold text-yellow-800 dark:text-yellow-200">Editing will regenerate all payment terms.</p>
          <p class="text-yellow-700 dark:text-yellow-300 mt-0.5">
            Only allowed when no payments have been recorded yet.
          </p>
        </div>
      </div>

      <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">

        <!-- ── LEFT: Form ─────────────────────────────────────────── -->
        <div class="xl:col-span-2 space-y-5">

          <!-- Semester / School Year -->
          <Card>
            <CardHeader><CardTitle class="text-base">Enrollment Period</CardTitle></CardHeader>
            <CardContent class="grid grid-cols-2 gap-4">
              <div class="space-y-1.5">
                <Label for="semester">Semester</Label>
                <select id="semester" v-model="form.semester"
                  class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs focus:outline-none focus:ring-1 focus:ring-ring">
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

          <!-- Units Input -->
          <Card>
            <CardHeader>
              <CardTitle class="flex items-center gap-2 text-base">
                <BookOpen class="h-4 w-4" /> Units Enrolled
              </CardTitle>
            </CardHeader>
            <CardContent class="grid grid-cols-2 gap-6">
              <div class="space-y-1.5">
                <Label for="lec_units" class="flex items-center gap-1.5">
                  <span class="w-2 h-2 rounded-full bg-blue-500 inline-block"></span>
                  Billable Lecture Units
                </Label>
                <Input id="lec_units" type="number" v-model.number="form.lec_units"
                  min="0" max="30" class="text-center text-lg font-semibold" />
                <p class="text-xs text-muted-foreground text-center">× {{ formatCurrency(feeRates.tuition_per_unit) }} / unit</p>
                <p class="text-xs text-muted-foreground text-center">NSTP &amp; PATHFIT excluded</p>
                <p v-if="form.errors.lec_units" class="text-sm text-destructive">{{ form.errors.lec_units }}</p>
              </div>
              <div class="space-y-1.5">
                <Label for="lab_units" class="flex items-center gap-1.5">
                  <span class="w-2 h-2 rounded-full bg-orange-500 inline-block"></span>
                  Subjects with Lab
                </Label>
                <Input id="lab_units" type="number" v-model.number="form.lab_units"
                  min="0" max="20" class="text-center text-lg font-semibold" />
                <p class="text-xs text-muted-foreground text-center">× {{ formatCurrency(feeRates.lab_fee_per_subject) }} / subject</p>
                <p class="text-xs text-muted-foreground text-center">+ {{ formatCurrency(feeRates.entrepreneurship_fee ?? 600) }} entrep if any</p>
                <p v-if="form.errors.lab_units" class="text-sm text-destructive">{{ form.errors.lab_units }}</p>
              </div>
            </CardContent>

            <!-- NSTP units field (editable) -->
            <div class="px-6 pb-4 space-y-3">
              <div class="space-y-1.5">
                <Label for="nstp_lec_units" class="flex items-center gap-1.5">
                  <span class="w-2 h-2 rounded-full bg-amber-500 inline-block"></span>
                  NSTP Lecture Units
                  <span class="text-xs text-muted-foreground">(billed at full price — never discounted)</span>
                </Label>
                <Input id="nstp_lec_units" type="number" v-model.number="form.nstp_lec_units"
                  min="0" max="10" class="w-28 text-center" />
                <p class="text-xs text-muted-foreground">Set to 0 if student is not taking NSTP this semester.</p>
                <p v-if="form.errors.nstp_lec_units" class="text-sm text-destructive">{{ form.errors.nstp_lec_units }}</p>
              </div>
              <div class="flex items-start gap-2 rounded-md bg-blue-50 p-3 text-xs text-blue-800">
                <Info class="h-3.5 w-3.5 mt-0.5 shrink-0" />
                <span>NSTP and PATHFIT/PE are excluded from billing per CHED. Lab fee is charged once per subject with a lab, not per lab unit.</span>
              </div>
            </div>
          </Card>

          <!-- Discount / Scholarship -->
          <Card>
            <CardHeader>
              <CardTitle class="text-base flex items-center gap-2">
                <span class="text-amber-600">🎓</span> Scholarship / Discount
              </CardTitle>
            </CardHeader>
            <CardContent class="space-y-4">

              <!-- NSTP notice -->
              <div v-if="hasNstp"
                class="flex items-start gap-2 rounded-md bg-amber-50 border border-amber-300 p-3 text-sm text-amber-900">
                <AlertTriangle class="h-4 w-4 mt-0.5 shrink-0 text-amber-600" />
                <div>
                  <p class="font-semibold">NSTP in this Assessment</p>
                  <p class="text-xs text-amber-800 mt-0.5">
                    NSTP ({{ form.nstp_lec_units }} units, {{ formatCurrency(nstpTuition) }}) is always billed at full price.
                    Any discount below applies only to the remaining {{ form.lec_units }} billable lecture units.
                  </p>
                </div>
              </div>

              <!-- Percentage input -->
              <div class="space-y-3">
                <Label for="discount_percentage">Discount Percentage (%)</Label>
                <p class="text-xs text-muted-foreground -mt-2">
                  Enter 0 for no discount. Only tuition (non-NSTP portion) is discounted — lab and misc are always charged in full.
                </p>

                <!-- Quick-select presets -->
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

                <!-- Freeform input -->
                <div class="flex items-center gap-3">
                  <Input
                    id="discount_percentage"
                    type="number"
                    v-model.number="form.discount_percentage"
                    min="0" max="100" step="0.01" placeholder="0.00"
                    class="w-28 text-center text-lg font-semibold"
                  />
                  <span class="text-sm text-muted-foreground">% off tuition only</span>
                </div>
                <p v-if="form.errors.discount_percentage" class="text-sm text-destructive">
                  {{ form.errors.discount_percentage }}
                </p>
              </div>

              <!-- Discount preview -->
              <div v-if="form.discount_percentage > 0"
                class="rounded-md bg-green-50 border border-green-200 p-3 space-y-1.5 text-sm">
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
                    <span>NSTP tuition ({{ form.nstp_lec_units }} units — full price)</span>
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
                  <span>Lab Fee</span>
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
                  <span>{{ formatCurrency(total) }}</span>
                </div>
              </div>

            </CardContent>
          </Card>

          <!-- Actions -->
          <div class="flex gap-3 justify-end">
            <Button variant="outline" @click="router.visit(route('student-fees.show', student.id))">Cancel</Button>
            <Button :disabled="form.processing" @click="submit">
              <Save class="mr-2 h-4 w-4" />
              {{ form.processing ? 'Saving…' : 'Save Changes' }}
            </Button>
          </div>

        </div>

        <!-- ── RIGHT: Live Preview ─────────────────────────────────── -->
        <div class="space-y-4">
          <Card class="sticky top-6">
            <CardHeader>
              <CardTitle class="text-base flex items-center gap-2">
                <Calculator class="h-4 w-4" /> Live Fee Preview
              </CardTitle>
            </CardHeader>
            <CardContent class="space-y-2 text-sm">

              <div class="flex justify-between">
                <span class="text-muted-foreground">Tuition ({{ form.lec_units }} lec)</span>
                <span class="font-medium">{{ formatCurrency(tuitionFee) }}</span>
              </div>
              <div v-if="hasNstp" class="flex justify-between text-xs text-amber-700 pl-2">
                <span>incl. NSTP {{ form.nstp_lec_units }}u (full price)</span>
                <span>{{ formatCurrency(nstpTuition) }}</span>
              </div>
              <div v-if="discountSaving > 0" class="flex justify-between text-xs text-green-600 pl-2">
                <span>− {{ form.discount_percentage }}% saved</span>
                <span>− {{ formatCurrency(discountSaving) }}</span>
              </div>
              <div class="flex justify-between">
                <span class="text-muted-foreground">Lab Fee ({{ form.lab_units }} subj)</span>
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

              <div class="border-t pt-2 flex justify-between font-bold text-base">
                <span>Total</span>
                <span class="text-blue-600">{{ formatCurrency(total) }}</span>
              </div>

              <div v-if="total > 0" class="mt-4 border-t pt-3 space-y-1.5">
                <p class="text-xs font-semibold uppercase text-muted-foreground">New Payment Terms</p>
                <div v-for="t in paymentTermBreakdown" :key="t.term_name" class="flex justify-between text-xs">
                  <span class="text-muted-foreground">{{ t.term_name }} ({{ t.percentage }}%)</span>
                  <span class="font-medium">{{ formatCurrency(t.amount) }}</span>
                </div>
              </div>

            </CardContent>
          </Card>
        </div>

      </div>
    </div>
  </AppLayout>
</template>