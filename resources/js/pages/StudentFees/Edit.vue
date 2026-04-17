<script setup lang="ts">
import { computed } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import AppLayout from '@/layouts/AppLayout.vue'
import Breadcrumbs from '@/components/Breadcrumbs.vue'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { useDataFormatting } from '@/composables/useDataFormatting'
import { BookOpen, FlaskConical, Calculator, Save, AlertTriangle } from 'lucide-vue-next'

// ─── Props ───────────────────────────────────────────────────────────────────

interface FeeRates {
  tuition_per_lec_unit: number
  lab_fee_per_unit: number
  misc_fee_fixed: number
  payment_terms: Array<{ term_name: string; term_order: number; percentage: number }>
}

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
    lab_units: number
    discount_percentage?: number
  }
  feeRates: FeeRates
}>()

// ─── Composables ─────────────────────────────────────────────────────────────

const { formatCurrency } = useDataFormatting()

// ─── Breadcrumbs ─────────────────────────────────────────────────────────────

const breadcrumbs = [
  { title: 'Dashboard', href: route('accounting.dashboard') },
  { title: 'Student Fees', href: route('student-fees.index') },
  { title: props.student.name, href: route('student-fees.show', props.student.id) },
  { title: 'Edit Assessment', href: route('student-fees.edit', props.student.id) },
]

// ─── Form ─────────────────────────────────────────────────────────────────────

const form = useForm({
  semester:             props.assessment.semester,
  school_year:          props.assessment.school_year,
  lec_units:            props.assessment.lec_units,
  lab_units:            props.assessment.lab_units,
  discount_percentage:  props.assessment.discount_percentage ?? 0,
})

// ─── Live Fee Computation ─────────────────────────────────────────────────────

// Base tuition (before discount)
const baseTuition = computed(() =>
  Number(form.lec_units) * props.feeRates.tuition_per_lec_unit
)

// Minimum tuition floor (1.5 units × rate)
const minimumTuition = computed(() =>
  1.5 * props.feeRates.tuition_per_lec_unit // ₱546
)

// Discounted tuition (applies minimum floor)
const tuitionFee = computed(() => {
  const base = baseTuition.value
  const min = minimumTuition.value
  const discount = Math.min(100, Math.max(0, Number(form.discount_percentage) || 0)) / 100
  return Math.round((min + (base - min) * (1 - discount)) * 100) / 100
})

const labFee = computed(() =>
  Number(form.lab_units) * props.feeRates.lab_fee_per_unit
)

const entrepreneurshipFee = computed(() =>
  Number(form.lab_units) > 0 ? 600 : 0
)

const miscFee = computed(() => props.feeRates.misc_fee_fixed)
const total = computed(() => tuitionFee.value + labFee.value + entrepreneurshipFee.value + miscFee.value)
const totalUnits = computed(() => Number(form.lec_units) + Number(form.lab_units))

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

      <h1 class="text-2xl font-bold">Edit Assessment — {{ student.name }}</h1>
      <p class="text-muted-foreground text-sm -mt-4">
        {{ student.account_id }} · {{ student.course }} · {{ student.year_level }}
      </p>

      <!-- Warning banner -->
      <div class="flex items-start gap-3 rounded-lg border border-yellow-300 bg-yellow-50 dark:bg-yellow-950/40 p-4 text-sm">
        <AlertTriangle class="h-4 w-4 text-yellow-600 mt-0.5 flex-shrink-0" />
        <div>
          <p class="font-semibold text-yellow-800 dark:text-yellow-200">Editing will regenerate all payment terms.</p>
          <p class="text-yellow-700 dark:text-yellow-300 mt-0.5">
            This is only allowed when no payments have been recorded yet. If payments exist, contact the admin.
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
            <div class="px-6 pb-4">
              <div class="flex items-center justify-center gap-2 rounded-md bg-muted py-2 text-sm">
                <span class="text-muted-foreground">Total units:</span>
                <span class="font-bold text-base">{{ totalUnits }}</span>
                <span class="text-muted-foreground text-xs">({{ form.lec_units }} LEC + {{ form.lab_units }} LAB)</span>
              </div>
            </div>
          </Card>

          <!-- Discount Input -->
          <Card>
            <CardHeader>
              <CardTitle class="text-base flex items-center gap-2">
                <span class="text-yellow-600">₱</span>
                Tuition Discount
                <span class="ml-auto text-xs font-normal text-muted-foreground">
                  Optional
                </span>
              </CardTitle>
            </CardHeader>
            <CardContent class="space-y-3">
              <div class="space-y-1.5">
                <Label for="discount_percentage" class="flex items-center justify-between">
                  <span>Discount %</span>
                  <span class="text-sm text-muted-foreground">
                    0% = Full tuition | 100% = Minimum (₱546)
                  </span>
                </Label>
                <div class="flex gap-3 items-center">
                  <Input 
                    id="discount_percentage" 
                    type="number" 
                    v-model.number="form.discount_percentage"
                    min="0" 
                    max="100" 
                    step="0.01"
                    class="text-center text-lg font-semibold" 
                  />
                  <span class="text-sm font-medium text-muted-foreground">%</span>
                </div>
                <p v-if="form.errors.discount_percentage" class="text-sm text-destructive">
                  {{ form.errors.discount_percentage }}
                </p>
              </div>

              <!-- Discount Preview -->
              <div v-if="Number(form.lec_units) > 0" class="rounded-md bg-blue-50 p-3 space-y-1.5">
                <div class="text-xs text-muted-foreground">Full tuition: <span class="font-semibold text-blue-900">{{ formatCurrency(baseTuition) }}</span></div>
                <div class="text-xs text-muted-foreground">Minimum floor: <span class="font-semibold text-blue-900">{{ formatCurrency(minimumTuition) }}</span></div>
                <div class="border-t pt-1.5 text-sm">
                  <div class="flex justify-between">
                    <span class="font-semibold">Final tuition:</span>
                    <span class="font-bold text-base text-blue-900">{{ formatCurrency(tuitionFee) }}</span>
                  </div>
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

        <!-- ── RIGHT: Live Preview ────────────────────────────────── -->
        <div class="space-y-4">
          <Card class="sticky top-6">
            <CardHeader>
              <CardTitle class="text-base flex items-center gap-2">
                <Calculator class="h-4 w-4" /> Fee Preview
              </CardTitle>
            </CardHeader>
            <CardContent class="space-y-2 text-sm">
              <div class="flex justify-between">
                <span class="text-muted-foreground">Tuition ({{ form.lec_units }} lec × {{ formatCurrency(feeRates.tuition_per_lec_unit) }})</span>
                <span class="font-medium">{{ formatCurrency(tuitionFee) }}</span>
              </div>
              <div class="flex justify-between">
                <span class="text-muted-foreground">Lab Fee ({{ form.lab_units }} units × {{ formatCurrency(feeRates.lab_fee_per_unit) }})</span>
                <span class="font-medium">{{ formatCurrency(labFee) }}</span>
              </div>
              <div v-if="entrepreneurshipFee > 0" class="flex justify-between">
                <span class="text-muted-foreground">+ Entrepreneurship Fee</span>
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