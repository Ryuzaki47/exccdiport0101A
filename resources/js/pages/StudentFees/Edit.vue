<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import AppLayout from '@/layouts/AppLayout.vue'
import Breadcrumbs from '@/components/Breadcrumbs.vue'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { useDataFormatting } from '@/composables/useDataFormatting'
import { BookOpen, Calculator, Save, AlertTriangle } from 'lucide-vue-next'

// ─── Types ────────────────────────────────────────────────────────────────────

interface FeeRates {
  tuition_per_unit: number
  lab_fee_per_subject: number
  entrepreneurship_fee: number
  misc_total: number
  misc_items: Array<{ id: number; key: string; label: string; amount: number; category: string }>
  payment_terms: Array<{ term_name: string; term_order: number; percentage: number }>
  nstp_min_tuition: number
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
    nstp_units: number
    lab_units: number
    discount_type?: string
    discount_percentage?: number
    is_taking_nstp?: boolean
  }
  feeRates: FeeRates
}>()

// ─── Composables ──────────────────────────────────────────────────────────────

const { formatCurrency } = useDataFormatting()

// ─── Breadcrumbs ──────────────────────────────────────────────────────────────

const breadcrumbs = [
  { title: 'Dashboard',         href: route('accounting.dashboard') },
  { title: 'Student Fees',      href: route('student-fees.index') },
  { title: props.student.name,  href: route('student-fees.show', props.student.id) },
  { title: 'Edit Assessment',   href: route('student-fees.edit', props.student.id) },
]

// ─── Form ─────────────────────────────────────────────────────────────────────

const form = useForm({
  semester:            props.assessment.semester,
  school_year:         props.assessment.school_year,
  lec_units:           props.assessment.lec_units,
  lab_units:           props.assessment.lab_units,
  discount_type:       (props.assessment.discount_type ?? 'none') as 'none' | 'full' | 'nstp' | 'percentage',
  discount_percentage: props.assessment.discount_percentage ?? 0,
  is_taking_nstp:      props.assessment.is_taking_nstp ?? false,
})

// ─── Live Fee Computation — mirrors AssessmentService::compute() ──────────────

const rates = props.feeRates

const rawTuition = computed(() =>
  Number(form.lec_units) * rates.tuition_per_unit
)

const baseLabFee = computed(() =>
  Number(form.lab_units) * rates.lab_fee_per_subject
)

const entrepreneurshipFee = computed(() =>
  Number(form.lab_units) > 0 ? (rates.entrepreneurship_fee ?? 600) : 0
)

const nstpMinTuition = computed(() => rates.nstp_min_tuition)

const tuitionFee = computed(() => {
  const dt   = form.discount_type
  const nstp = form.is_taking_nstp
  const raw  = rawTuition.value
  const min  = nstpMinTuition.value

  if (dt === 'full')  return nstp ? min : 0
  if (dt === 'nstp')  return min

  if (dt === 'percentage') {
    const pct = Number(form.discount_percentage) || 0
    if (pct <= 0) return raw
    const discounted = Math.round(raw * (1 - pct / 100) * 100) / 100
    return nstp ? Math.max(min, discounted) : discounted
  }

  return raw // 'none'
})

const labFee    = computed(() => baseLabFee.value)
const miscFee   = computed(() => rates.misc_total)

const total = computed(() =>
  tuitionFee.value + labFee.value + entrepreneurshipFee.value + miscFee.value
)

// How much tuition discount saves vs full billing
const discountSaving = computed(() =>
  Math.max(0, Math.round((rawTuition.value - tuitionFee.value) * 100) / 100)
)

const paymentTermBreakdown = computed(() =>
  rates.payment_terms.map((t) => ({
    term_name:  t.term_name,
    percentage: t.percentage,
    amount:     Math.round(total.value * (t.percentage / 100) * 100) / 100,
  }))
)

// Reset discount_percentage to 0 when switching away from 'percentage' type
watch(() => form.discount_type, (val) => {
  if (val !== 'percentage') form.discount_percentage = 0
})

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
            Only allowed when no payments have been recorded yet. If payments exist, contact the admin.
          </p>
        </div>
      </div>

      <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">

        <!-- ── LEFT: Form ─────────────────────────────────────────── -->
        <div class="xl:col-span-2 space-y-5">

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

          <!-- Units Input -->
          <Card>
            <CardHeader>
              <CardTitle class="flex items-center gap-2 text-base">
                <BookOpen class="h-4 w-4" />
                Units Enrolled
              </CardTitle>
            </CardHeader>
            <CardContent class="grid grid-cols-2 gap-6">

              <div class="space-y-1.5">
                <Label for="lec_units" class="flex items-center gap-1.5">
                  <span class="w-2 h-2 rounded-full bg-blue-500 inline-block"></span>
                  Billable Lecture Units
                </Label>
                <Input
                  id="lec_units"
                  type="number"
                  v-model.number="form.lec_units"
                  min="0" max="30"
                  class="text-center text-lg font-semibold"
                />
                <p class="text-xs text-muted-foreground text-center">
                  × {{ formatCurrency(feeRates.tuition_per_unit) }} / unit
                </p>
                <p class="text-xs text-muted-foreground text-center">NSTP & PATHFIT already excluded</p>
                <p v-if="form.errors.lec_units" class="text-sm text-destructive">{{ form.errors.lec_units }}</p>
              </div>

              <div class="space-y-1.5">
                <Label for="lab_units" class="flex items-center gap-1.5">
                  <span class="w-2 h-2 rounded-full bg-orange-500 inline-block"></span>
                  Subjects with Lab Component
                </Label>
                <Input
                  id="lab_units"
                  type="number"
                  v-model.number="form.lab_units"
                  min="0" max="20"
                  class="text-center text-lg font-semibold"
                />
                <p class="text-xs text-muted-foreground text-center">
                  × {{ formatCurrency(feeRates.lab_fee_per_subject) }} / subject
                </p>
                <p class="text-xs text-muted-foreground text-center">
                  + {{ formatCurrency(feeRates.entrepreneurship_fee ?? 600) }} entrep fee if any
                </p>
                <p v-if="form.errors.lab_units" class="text-sm text-destructive">{{ form.errors.lab_units }}</p>
              </div>

            </CardContent>
          </Card>

          <!-- Discount / Scholarship Policy -->
          <Card>
            <CardHeader>
              <CardTitle class="text-base flex items-center gap-2">
                <span class="text-amber-600">🎓</span>
                Discount / Scholarship Policy
              </CardTitle>
            </CardHeader>
            <CardContent class="space-y-4">

              <!-- Discount Type Dropdown -->
              <div class="space-y-1.5">
                <Label for="discount_type">Scholarship / Discount Type</Label>
                <select
                  id="discount_type"
                  v-model="form.discount_type"
                  class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs focus:outline-none focus:ring-1 focus:ring-ring"
                >
                  <option value="none">None — Standard billing</option>
                  <option value="full">Full Scholarship — 100% tuition waived</option>
                  <option value="nstp">NSTP Waiver — tuition fixed at {{ formatCurrency(feeRates.nstp_min_tuition) }}</option>
                  <option value="percentage">Partial Scholarship — Enter percentage below</option>
                </select>
                <p v-if="form.errors.discount_type" class="text-sm text-destructive">
                  {{ form.errors.discount_type }}
                </p>
              </div>

              <!-- Percentage input — shown only for 'percentage' type -->
              <div v-if="form.discount_type === 'percentage'" class="space-y-2">
                <Label for="discount_percentage">Discount Percentage (%)</Label>
                <!-- Quick-select presets -->
                <div class="flex gap-1 flex-wrap">
                  <button
                    v-for="preset in [10, 20, 25, 50, 75, 100]"
                    :key="preset"
                    type="button"
                    @click="form.discount_percentage = preset"
                    :class="[
                      'px-2.5 py-1 rounded text-xs font-medium border transition-colors',
                      form.discount_percentage === preset
                        ? 'bg-amber-500 text-white border-amber-500'
                        : 'bg-white border-input text-muted-foreground hover:bg-muted'
                    ]"
                  >
                    {{ preset }}%
                  </button>
                </div>
                <!-- Freeform input -->
                <div class="flex items-center gap-2">
                  <Input
                    id="discount_percentage"
                    type="number"
                    v-model.number="form.discount_percentage"
                    min="0"
                    max="100"
                    step="0.01"
                    placeholder="0.00"
                    class="w-32 text-center"
                  />
                  <span class="text-sm text-muted-foreground">% off tuition only</span>
                </div>
                <p v-if="form.errors.discount_percentage" class="text-sm text-destructive">
                  {{ form.errors.discount_percentage }}
                </p>
                <p class="text-xs text-muted-foreground">
                  Lab and miscellaneous fees are never discounted per CCDI policy.
                </p>
              </div>

              <!-- NSTP checkbox -->
              <div
                v-if="form.discount_type !== 'none'"
                class="flex items-start gap-2 p-3 rounded-md bg-amber-50 border border-amber-200"
              >
                <input
                  id="is_taking_nstp"
                  type="checkbox"
                  v-model="form.is_taking_nstp"
                  class="mt-0.5 rounded border border-input"
                />
                <Label for="is_taking_nstp" class="cursor-pointer flex-1 text-amber-900">
                  Student is enrolled in NSTP this semester
                  <p class="text-xs text-amber-700 mt-0.5 font-normal">
                    When active with Full/Partial Scholarship: tuition floors at
                    {{ formatCurrency(feeRates.nstp_min_tuition) }} (1.5 units × rate).
                    Lab and misc always charged.
                  </p>
                </Label>
              </div>

              <!-- Discount preview -->
              <div
                v-if="form.discount_type !== 'none'"
                class="rounded-md bg-green-50 border border-green-200 p-3 space-y-1.5 text-sm"
              >
                <p class="font-semibold text-xs uppercase tracking-wide text-green-700 mb-1">
                  Effective Fees After Discount
                </p>
                <div class="flex justify-between text-green-900">
                  <span>Tuition</span>
                  <span class="font-semibold">{{ formatCurrency(tuitionFee) }}</span>
                </div>
                <div v-if="discountSaving > 0" class="flex justify-between text-green-700 text-xs">
                  <span>You save</span>
                  <span class="font-semibold text-green-600">− {{ formatCurrency(discountSaving) }}</span>
                </div>
                <div class="flex justify-between text-green-900">
                  <span>Lab Fee</span>
                  <span class="font-semibold">{{ formatCurrency(labFee) }}</span>
                </div>
                <div v-if="entrepreneurshipFee > 0" class="flex justify-between text-green-900">
                  <span>Entrepreneurship Fee</span>
                  <span class="font-semibold">{{ formatCurrency(entrepreneurshipFee) }}</span>
                </div>
                <div class="flex justify-between text-green-900">
                  <span>Miscellaneous</span>
                  <span class="font-semibold">{{ formatCurrency(miscFee) }}</span>
                </div>
                <div class="border-t border-green-300 pt-1.5 flex justify-between font-bold text-green-900">
                  <span>Total Assessment</span>
                  <span>{{ formatCurrency(total) }}</span>
                </div>
              </div>

            </CardContent>
          </Card>

          <!-- Actions -->
          <div class="flex gap-3 justify-end">
            <Button variant="outline" @click="router.visit(route('student-fees.show', student.id))">
              Cancel
            </Button>
            <Button :disabled="form.processing" @click="submit">
              <Save class="mr-2 h-4 w-4" />
              {{ form.processing ? 'Saving…' : 'Save Changes' }}
            </Button>
          </div>

        </div>

        <!-- ── RIGHT: Live Fee Preview ─────────────────────────────── -->
        <div class="space-y-4">
          <Card class="sticky top-6">
            <CardHeader>
              <CardTitle class="text-base flex items-center gap-2">
                <Calculator class="h-4 w-4" /> Live Fee Preview
              </CardTitle>
            </CardHeader>
            <CardContent class="space-y-2 text-sm">

              <div class="flex justify-between">
                <span class="text-muted-foreground">
                  Tuition ({{ form.lec_units }} lec × {{ formatCurrency(feeRates.tuition_per_unit) }})
                </span>
                <span class="font-medium">{{ formatCurrency(tuitionFee) }}</span>
              </div>

              <div v-if="discountSaving > 0" class="flex justify-between text-xs text-green-600">
                <span>Discount saving</span>
                <span class="font-medium">− {{ formatCurrency(discountSaving) }}</span>
              </div>

              <div class="flex justify-between">
                <span class="text-muted-foreground">
                  Lab Fee ({{ form.lab_units }} subj × {{ formatCurrency(feeRates.lab_fee_per_subject) }})
                </span>
                <span class="font-medium">{{ formatCurrency(labFee) }}</span>
              </div>

              <div v-if="entrepreneurshipFee > 0" class="flex justify-between">
                <span class="text-muted-foreground">Entrepreneurship Fee (flat)</span>
                <span class="font-medium">{{ formatCurrency(entrepreneurshipFee) }}</span>
              </div>

              <div class="flex justify-between">
                <span class="text-muted-foreground">Miscellaneous (fixed)</span>
                <span class="font-medium">{{ formatCurrency(miscFee) }}</span>
              </div>

              <div class="border-t pt-2 flex justify-between font-bold text-base">
                <span>Total</span>
                <span class="text-blue-600">{{ formatCurrency(total) }}</span>
              </div>

              <!-- Payment Terms Preview -->
              <div v-if="total > 0" class="mt-4 border-t pt-3 space-y-1.5">
                <p class="text-xs font-semibold uppercase text-muted-foreground">New Payment Terms</p>
                <div
                  v-for="t in paymentTermBreakdown"
                  :key="t.term_name"
                  class="flex justify-between text-xs"
                >
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