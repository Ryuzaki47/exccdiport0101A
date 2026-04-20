<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\FeeSetting;
use Illuminate\Http\Request;
use Inertia\Inertia;

class FeeSettingsController extends Controller
{
    // ─── Index ────────────────────────────────────────────────────────────────

    public function index()
    {
        $settings = FeeSetting::where('is_active', true)
            ->orderByRaw("FIELD(category, 'rate', 'miscellaneous', 'other', 'term')")
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->groupBy('category')
            ->toArray();

        $miscTotal = FeeSetting::whereIn('category', ['miscellaneous', 'other'])
            ->where('is_active', true)
            ->sum('amount');

        return Inertia::render('Accounting/FeeSettings', [
            'settings'  => $settings,
            'miscTotal' => round($miscTotal, 2),
        ]);
    }

    // ─── Update (existing fee item amount) ────────────────────────────────────

    public function update(Request $request, FeeSetting $feeSetting)
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0', 'max:99999.99'],
            'label'  => ['sometimes', 'string', 'max:100'],
        ]);

        // Term percentages: validate sum = 100
        if ($feeSetting->category === 'term') {
            $this->validateTermPercentages($feeSetting->key, (float) $validated['amount']);
        }

        $updateData = ['amount' => $validated['amount']];
        if (isset($validated['label'])) {
            $updateData['label'] = $validated['label'];
        }

        $feeSetting->update($updateData);

        return back()->with('success', "'{$feeSetting->label}' updated successfully.");
    }

    // ─── Store (add new misc fee item) ────────────────────────────────────────

    public function store(Request $request)
    {
        $validated = $request->validate([
            'label'    => ['required', 'string', 'max:100'],
            'amount'   => ['required', 'numeric', 'min:0', 'max:99999.99'],
            'category' => ['required', 'in:miscellaneous,other'],
        ]);

        // Generate a unique key
        $key = FeeSetting::generateKey($validated['label'], $validated['category']);

        // Determine sort_order (append at end of category)
        $maxOrder = FeeSetting::where('category', $validated['category'])->max('sort_order') ?? 0;

        FeeSetting::create([
            'key'          => $key,
            'label'        => $validated['label'],
            'amount'       => $validated['amount'],
            'category'     => $validated['category'],
            'is_active'    => true,
            'sort_order'   => $maxOrder + 1,
            'is_deletable' => true,
        ]);

        return back()->with('success', "'{$validated['label']}' added to fee settings.");
    }

    // ─── Destroy (remove a misc fee item) ─────────────────────────────────────

    public function destroy(FeeSetting $feeSetting)
    {
        // System-critical rows (rates, terms) cannot be deleted
        if (! $feeSetting->is_deletable) {
            return back()->withErrors([
                'fee' => "'{$feeSetting->label}' is a system fee and cannot be removed.",
            ]);
        }

        if (in_array($feeSetting->category, ['rate', 'term'])) {
            return back()->withErrors([
                'fee' => 'Billing rates and payment terms cannot be deleted.',
            ]);
        }

        $label = $feeSetting->label;
        $feeSetting->delete();

        return back()->with('success', "'{$label}' removed from fee settings.");
    }

    // ─── Bulk Update ──────────────────────────────────────────────────────────

    public function bulkUpdate(Request $request)
    {
        $validated = $request->validate([
            'settings'          => 'required|array',
            'settings.*.id'     => 'required|integer|exists:fee_settings,id',
            'settings.*.amount' => 'required|numeric|min:0|max:99999.99',
        ]);

        // Validate term percentages sum to 100 if any term rows are included
        $termUpdates = collect($validated['settings'])->filter(function ($item) {
            $setting = FeeSetting::find($item['id']);
            return $setting && $setting->category === 'term';
        });

        if ($termUpdates->isNotEmpty()) {
            $newTermAmounts = [];
            foreach ($validated['settings'] as $item) {
                $s = FeeSetting::find($item['id']);
                if ($s && $s->category === 'term') {
                    $newTermAmounts[$s->key] = (float) $item['amount'];
                }
            }

            $allTerms = FeeSetting::where('category', 'term')->get();
            $total    = 0;
            foreach ($allTerms as $term) {
                $total += $newTermAmounts[$term->key] ?? (float) $term->amount;
            }

            if (abs($total - 100.00) > 0.01) {
                return back()->withErrors([
                    'terms' => "Payment term percentages must sum to 100%. Current total: {$total}%",
                ]);
            }
        }

        foreach ($validated['settings'] as $item) {
            FeeSetting::where('id', $item['id'])->update(['amount' => $item['amount']]);
        }

        return back()->with('success', 'Fee settings saved successfully.');
    }

    // ─── Private ──────────────────────────────────────────────────────────────

    private function validateTermPercentages(string $updatedKey, float $newValue): void
    {
        $allTerms = FeeSetting::where('category', 'term')->get();
        $total    = 0;

        foreach ($allTerms as $term) {
            $total += ($term->key === $updatedKey) ? $newValue : (float) $term->amount;
        }

        if (abs($total - 100.00) > 0.01) {
            abort(422, "Payment term percentages must sum to 100%. Current total: {$total}%");
        }
    }
}