<?php

namespace App\Http\Controllers;

use App\Models\Subject;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * SubjectController
 *
 * Handles admin/accounting management of the curriculum subjects table.
 * Specifically allows editing lec_units and lab_units per subject,
 * which directly affects auto-populated assessments for regular students.
 *
 * Routes are now re-enabled in routes/web.php under accounting middleware.
 */
class SubjectController extends Controller
{
    // ─── Index ────────────────────────────────────────────────────────────────

    public function index(Request $request): Response
    {
        $query = Subject::query()->where('is_active', true);

        if ($request->filled('course')) {
            $query->where('course', $request->course);
        }

        if ($request->filled('year_level')) {
            $query->where('year_level', $request->year_level);
        }

        if ($request->filled('semester')) {
            $query->where('semester', $request->semester);
        }

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('name', 'like', "%{$s}%")
                  ->orWhere('code', 'like', "%{$s}%");
            });
        }

        $subjects = $query
            ->orderBy('course')
            ->orderBy('year_level')
            ->orderBy('semester')
            ->orderBy('code')
            ->paginate(50)
            ->through(fn ($s) => [
                'id'         => $s->id,
                'code'       => $s->code,
                'name'       => $s->name,
                'lec_units'  => $s->lec_units,
                'lab_units'  => $s->lab_units,
                'year_level' => $s->year_level,
                'semester'   => $s->semester,
                'course'     => $s->course,
                'is_active'  => $s->is_active,
            ]);

        $subjects->appends($request->only(['course', 'year_level', 'semester', 'search']));

        $courses    = Subject::distinct()->pluck('course')->sort()->values();
        $yearLevels = Subject::distinct()->pluck('year_level')->sort()->values();
        $semesters  = Subject::distinct()->pluck('semester')->sort()->values();

        return Inertia::render('Subjects/Index', [
            'subjects'   => $subjects,
            'filters'    => $request->only(['course', 'year_level', 'semester', 'search']),
            'courses'    => $courses,
            'yearLevels' => $yearLevels,
            'semesters'  => $semesters,
        ]);
    }

    // ─── Edit ─────────────────────────────────────────────────────────────────

    public function edit(Subject $subject): Response
    {
        return Inertia::render('Subjects/Edit', [
            'subject' => [
                'id'         => $subject->id,
                'code'       => $subject->code,
                'name'       => $subject->name,
                'lec_units'  => $subject->lec_units,
                'lab_units'  => $subject->lab_units,
                'year_level' => $subject->year_level,
                'semester'   => $subject->semester,
                'course'     => $subject->course,
            ],
        ]);
    }

    // ─── Update ───────────────────────────────────────────────────────────────

    public function update(Request $request, Subject $subject)
    {
        $validated = $request->validate([
            'lec_units' => ['required', 'integer', 'min:0', 'max:10'],
            'lab_units' => ['required', 'integer', 'min:0', 'max:5'],
        ]);

        $subject->update($validated);

        return redirect()
            ->route('subjects.index')
            ->with('success', "'{$subject->name}' updated: {$validated['lec_units']} LEC, {$validated['lab_units']} LAB.");
    }

    // ─── Inline Update (AJAX) ─────────────────────────────────────────────────

    /**
     * Quick inline update from the index table — no page redirect needed.
     */
    public function inlineUpdate(Request $request, Subject $subject): \Illuminate\Http\JsonResponse
    {
        $validated = $request->validate([
            'lec_units' => ['required', 'integer', 'min:0', 'max:10'],
            'lab_units' => ['required', 'integer', 'min:0', 'max:5'],
        ]);

        $subject->update($validated);

        return response()->json([
            'success'   => true,
            'lec_units' => $subject->fresh()->lec_units,
            'lab_units' => $subject->fresh()->lab_units,
        ]);
    }
}