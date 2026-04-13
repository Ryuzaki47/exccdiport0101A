<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Subject;
use App\Models\StudentAssessment;
use App\Models\Transaction;
use App\Models\Fee;
use Database\Seeders\Traits\GetAdminUserTrait;

class QuickStudentAssessmentSeeder extends Seeder
{
    use GetAdminUserTrait;
    public function run(): void
    {
        $schoolYear = '2025-2026';
        $semester = '1st Sem';

        // Create assessment for student ID 3 specifically
        $student = User::find(3);

        if (!$student || !($student->role->value === 'student')) {
            $this->command->error('Student not found or invalid');
            return;
        }

        // Get subjects
        $subjects = Subject::active()
            ->where('course', $student->course)
            ->where('year_level', $student->year_level)
            ->where('semester', $semester)
            ->get();

        if ($subjects->isEmpty()) {
            $this->command->error("No subjects found for {$student->name}");
            return;
        }

        // Calculate fees
        $tuitionFee = 0;
        $labFee = 0;
        $subjectData = [];

        foreach ($subjects as $subject) {
            $subjectCost = $subject->units * $subject->price_per_unit;
            $tuitionFee += $subjectCost;
            
            if ($subject->has_lab) {
                $labFee += $subject->lab_fee;
            }

            $subjectData[] = [
                'id' => $subject->id,
                'units' => $subject->units,
                'amount' => $subjectCost + ($subject->has_lab ? $subject->lab_fee : 0),
            ];
        }

        // Get other fees
        $otherFees = Fee::active()
            ->where('year_level', $student->year_level)
            ->where('semester', $semester)
            ->where('school_year', $schoolYear)
            ->whereIn('category', ['Library', 'Athletic', 'Miscellaneous'])
            ->get();

        $otherFeesTotal = 0;
        $feeBreakdown = [];

        foreach ($otherFees as $fee) {
            $otherFeesTotal += $fee->amount;
            $feeBreakdown[] = [
                'id' => $fee->id,
                'amount' => $fee->amount,
            ];
        }

        $totalAssessment = $tuitionFee + $labFee + $otherFeesTotal;

        // Calculate units (estimate from subjects)
        $lecUnits = $subjects->sum('units');
        $labUnits = $subjects->where('has_lab', true)->count();

        // Create assessment
        $assessment = StudentAssessment::create([
            'user_id' => $student->id,
            'assessment_number' => StudentAssessment::generateAssessmentNumber(),
            'year_level' => $student->year_level,
            'semester' => $semester,
            'school_year' => $schoolYear,
            'lec_units' => $lecUnits,
            'lab_units' => $labUnits,
            'total_assessment' => $totalAssessment,
            'status' => 'active',
        ]);

        // Create subject transactions
        foreach ($subjects as $subject) {
            $subjectCost = $subject->units * $subject->price_per_unit;
            $totalSubjectCost = $subjectCost + ($subject->has_lab ? $subject->lab_fee : 0);

            Transaction::create([
                'user_id' => $student->id,
                'reference' => 'SUBJ-' . strtoupper(\Illuminate\Support\Str::random(8)),
                'kind' => 'charge',
                'type' => 'Tuition',
                'year' => '2025',
                'semester' => $semester,
                'amount' => $totalSubjectCost,
                'status' => 'pending',
                'meta' => [
                    'assessment_id' => $assessment->id,
                    'subject_id' => $subject->id,
                ],
            ]);
        }

        // Create fee transactions
        foreach ($otherFees as $fee) {
            Transaction::create([
                'user_id' => $student->id,
                'fee_id' => $fee->id,
                'reference' => 'FEE-' . strtoupper(\Illuminate\Support\Str::random(8)),
                'kind' => 'charge',
                'type' => $fee->category,
                'year' => '2025',
                'semester' => $semester,
                'amount' => $fee->amount,
                'status' => 'pending',
                'meta' => [
                    'assessment_id' => $assessment->id,
                ],
            ]);
        }

        // Create payment terms
        $baseDate = now()->startOfMonth();
        // CONCERN FIX #7: Use authoritative config instead of outdated model constant
        $terms = config('fees.terms');

        $totalAmount = 0;
        $totalTerms = count($terms);
        foreach ($terms as $termOrder => $termData) {
            // For the last term, use remainder to ensure total equals assessment (no rounding)
            if ($termOrder === $totalTerms) {
                $amount = $totalAssessment - $totalAmount;
            } else {
                $amount = round($totalAssessment * ($termData['percentage'] / 100), 2);
                $totalAmount += $amount;
            }
            
            $dueDate = match ($termOrder) {
                1 => $baseDate->copy(),
                2 => $baseDate->copy()->addWeeks(4),
                3 => $baseDate->copy()->addWeeks(8),
                4 => $baseDate->copy()->addWeeks(12),
                5 => $baseDate->copy()->addWeeks(16),
                default => $baseDate->copy()->addWeeks($termOrder),
            };

            \App\Models\StudentPaymentTerm::create([
                'student_assessment_id' => $assessment->id,
                'user_id' => $student->id,
                'term_name' => $termData['name'],
                'term_order' => $termOrder,
                'percentage' => $termData['percentage'],
                'amount' => $amount,
                'balance' => $amount,
                'due_date' => $dueDate,
                'status' => \App\Models\StudentPaymentTerm::STATUS_PENDING,
                'remarks' => null,
                'paid_date' => null,
            ]);
        }

        $this->command->info("✓ Assessment created for {$student->name}");
        $this->command->info("  Total Assessment: ₱" . number_format($totalAssessment, 2));
        $this->command->info("  Subjects: {$subjects->count()}");
        $this->command->info("  Other Fees: {$otherFees->count()}");
        $this->command->info("  Payment Terms: " . count($terms));
    }
}
