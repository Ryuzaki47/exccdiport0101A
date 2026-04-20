<script setup lang="ts">
import { ref, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { Trash2, Plus, Info } from 'lucide-vue-next';

// ─── Types ───────────────────────────────────────────────────────────────────

interface FeeSetting {
  id: number;
  key: string;
  label: string;
  amount: string;
  category: string;
  is_deletable?: boolean;
}

const props = defineProps<{
  settings: Record<string, FeeSetting[]>;
  miscTotal: number;
}>();

// ─── State ───────────────────────────────────────────────────────────────────

const editing    = ref<number | null>(null);
const editValues = ref<Record<number, string>>({});
const saving     = ref(false);
const flashSuccess = ref('');
const flashError   = ref('');

// New misc item form
const showAddForm  = ref(false);
const newItemLabel  = ref('');
const newItemAmount = ref('');
const newItemCategory = ref<'miscellaneous' | 'other'>('miscellaneous');
const addSaving     = ref(false);

// Delete confirmation
const deletingId = ref<number | null>(null);

// ─── Computed ─────────────────────────────────────────────────────────────────

const rateSettings  = computed(() => props.settings['rate']          ?? []);
const miscSettings  = computed(() => props.settings['miscellaneous'] ?? []);
const otherSettings = computed(() => props.settings['other']         ?? []);
const termSettings  = computed(() => props.settings['term']          ?? []);

const allMiscSettings = computed(() => [...miscSettings.value, ...otherSettings.value]);

const liveMiscTotal = computed(() =>
  allMiscSettings.value.reduce((sum, s) => {
    const val = editValues.value[s.id] !== undefined
      ? parseFloat(editValues.value[s.id] || '0')
      : parseFloat(s.amount);
    return sum + (isNaN(val) ? 0 : val);
  }, 0)
);

const termTotal = computed(() =>
  termSettings.value.reduce((sum, s) => {
    const val = editValues.value[s.id] !== undefined
      ? parseFloat(editValues.value[s.id] || '0')
      : parseFloat(s.amount);
    return sum + (isNaN(val) ? 0 : val);
  }, 0)
);

// ─── Edit / Save ──────────────────────────────────────────────────────────────

function startEdit(id: number, current: string) {
  editing.value = id;
  editValues.value[id] = current;
}

function cancelEdit(id: number) {
  editing.value = null;
  delete editValues.value[id];
}

function saveOne(setting: FeeSetting) {
  saving.value = true;
  flashSuccess.value = '';
  flashError.value   = '';

  router.patch(route('accounting.fee-settings.update', setting.id), {
    amount: parseFloat(editValues.value[setting.id] || '0'),
  }, {
    preserveScroll: true,
    onSuccess: () => {
      editing.value = null;
      delete editValues.value[setting.id];
      flashSuccess.value = `${setting.label} updated.`;
      setTimeout(() => flashSuccess.value = '', 3000);
    },
    onError: (errors: Record<string, string>) => {
      flashError.value = Object.values(errors).flat().join(' ');
    },
    onFinish: () => { saving.value = false; },
  });
}

// ─── Add Misc Item ────────────────────────────────────────────────────────────

function addMiscItem() {
  if (! newItemLabel.value.trim()) return;

  addSaving.value    = true;
  flashSuccess.value = '';
  flashError.value   = '';

  router.post(route('accounting.fee-settings.store'), {
    label:    newItemLabel.value.trim(),
    amount:   parseFloat(newItemAmount.value || '0'),
    category: newItemCategory.value,
  }, {
    preserveScroll: true,
    onSuccess: () => {
      newItemLabel.value    = '';
      newItemAmount.value   = '';
      newItemCategory.value = 'miscellaneous';
      showAddForm.value     = false;
      flashSuccess.value    = 'Fee item added.';
      setTimeout(() => flashSuccess.value = '', 3000);
    },
    onError: (errors: Record<string, string>) => {
      flashError.value = Object.values(errors).flat().join(' ');
    },
    onFinish: () => { addSaving.value = false; },
  });
}

// ─── Delete Misc Item ─────────────────────────────────────────────────────────

function confirmDelete(id: number) {
  deletingId.value = id;
}

function cancelDelete() {
  deletingId.value = null;
}

function deleteMiscItem(setting: FeeSetting) {
  flashSuccess.value = '';
  flashError.value   = '';

  router.delete(route('accounting.fee-settings.destroy', setting.id), {
    preserveScroll: true,
    onSuccess: () => {
      deletingId.value   = null;
      flashSuccess.value = `'${setting.label}' removed.`;
      setTimeout(() => flashSuccess.value = '', 3000);
    },
    onError: (errors: Record<string, string>) => {
      deletingId.value = null;
      flashError.value = Object.values(errors).flat().join(' ');
    },
  });
}

// ─── Format ───────────────────────────────────────────────────────────────────

function fmt(val: string | number) {
  return '₱' + parseFloat(String(val)).toLocaleString('en-PH', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  });
}
</script>

<template>
  <AppLayout title="Fee Settings">
    <div class="max-w-3xl mx-auto px-4 py-8 space-y-8">

      <div>
        <h1 class="text-2xl font-bold text-gray-900">Fee Settings</h1>
        <p class="text-sm text-gray-500 mt-1">
          Changes apply to <strong>new assessments only</strong>. Existing assessments are not affected.
        </p>
      </div>

      <!-- Flash messages -->
      <div v-if="flashSuccess"
        class="bg-green-50 border border-green-200 text-green-800 rounded-lg px-4 py-3 text-sm">
        ✓ {{ flashSuccess }}
      </div>
      <div v-if="flashError"
        class="bg-red-50 border border-red-200 text-red-800 rounded-lg px-4 py-3 text-sm">
        {{ flashError }}
      </div>

      <!-- ── Billing Rates ─────────────────────────────────────────────────── -->
      <section>
        <h2 class="text-xs font-semibold text-gray-500 uppercase tracking-widest mb-3">Billing Rates</h2>
        <div class="bg-white rounded-xl border border-gray-200 divide-y divide-gray-100">
          <div v-for="s in rateSettings" :key="s.id"
            class="flex items-center justify-between px-5 py-4 hover:bg-gray-50">
            <span class="text-sm text-gray-700">{{ s.label }}</span>
            <div class="flex items-center gap-4">
              <span v-if="editing !== s.id"
                class="font-mono text-sm font-semibold text-gray-900 w-28 text-right">
                {{ fmt(s.amount) }}
              </span>
              <div v-else class="flex items-center gap-1">
                <span class="text-gray-400 text-sm">₱</span>
                <input type="number" step="0.01" min="0"
                  v-model="editValues[s.id]"
                  class="w-28 border border-blue-400 rounded px-2 py-1 text-right text-sm font-mono focus:outline-none focus:ring-2 focus:ring-blue-300"
                  @keyup.enter="saveOne(s)"
                  @keyup.escape="cancelEdit(s.id)"
                />
              </div>
              <div class="w-20 text-right">
                <button v-if="editing !== s.id"
                  @click="startEdit(s.id, s.amount)"
                  class="text-blue-600 hover:text-blue-800 text-xs font-medium">Edit</button>
                <div v-else class="flex gap-2 justify-end">
                  <button @click="saveOne(s)" :disabled="saving"
                    class="text-green-600 hover:text-green-800 text-xs font-medium disabled:opacity-40">Save</button>
                  <button @click="cancelEdit(s.id)"
                    class="text-gray-400 hover:text-gray-600 text-xs">Cancel</button>
                </div>
              </div>
            </div>
          </div>
        </div>
        <div class="flex items-start gap-2 mt-2 text-xs text-gray-400">
          <Info class="h-3.5 w-3.5 mt-0.5 shrink-0" />
          <span>Tuition per lecture unit. Lab fee charged once per subject with laboratory sessions.</span>
        </div>
      </section>

      <!-- ── Miscellaneous Fees ────────────────────────────────────────────── -->
      <section>
        <div class="flex items-baseline justify-between mb-3">
          <h2 class="text-xs font-semibold text-gray-500 uppercase tracking-widest">
            Miscellaneous Fees
          </h2>
          <span class="text-sm text-gray-600">
            Total:
            <span class="font-semibold text-gray-900 font-mono">{{ fmt(liveMiscTotal) }}</span>
            <span class="text-gray-400 text-xs ml-1">(per semester)</span>
          </span>
        </div>

        <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
          <table class="w-full text-sm">
            <thead class="bg-gray-50 border-b border-gray-200 text-xs uppercase tracking-wide">
              <tr>
                <th class="text-left px-5 py-3 font-medium text-gray-500">Fee</th>
                <th class="text-left px-5 py-3 font-medium text-gray-500">Type</th>
                <th class="text-right px-5 py-3 font-medium text-gray-500">Amount</th>
                <th class="w-28 px-5 py-3"></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              <tr v-for="s in allMiscSettings" :key="s.id" class="hover:bg-gray-50">
                <td class="px-5 py-3 text-gray-700">{{ s.label }}</td>
                <td class="px-5 py-3">
                  <span :class="s.category === 'other'
                    ? 'bg-purple-50 text-purple-700 border-purple-200'
                    : 'bg-sky-50 text-sky-700 border-sky-200'"
                    class="text-xs px-2 py-0.5 rounded-full border font-medium">
                    {{ s.category === 'other' ? 'Other' : 'Misc' }}
                  </span>
                </td>
                <td class="px-5 py-3 text-right">
                  <span v-if="editing !== s.id" class="font-mono font-semibold text-gray-900">
                    {{ fmt(s.amount) }}
                  </span>
                  <div v-else class="flex items-center justify-end gap-1">
                    <span class="text-gray-400">₱</span>
                    <input type="number" step="0.01" min="0"
                      v-model="editValues[s.id]"
                      class="w-28 border border-blue-400 rounded px-2 py-1 text-right text-sm font-mono focus:outline-none focus:ring-2 focus:ring-blue-300"
                      @keyup.enter="saveOne(s)"
                      @keyup.escape="cancelEdit(s.id)"
                    />
                  </div>
                </td>
                <td class="px-5 py-3 text-right">
                  <!-- Not in delete confirm mode -->
                  <div v-if="deletingId !== s.id" class="flex items-center justify-end gap-2">
                    <button v-if="editing !== s.id"
                      @click="startEdit(s.id, s.amount)"
                      class="text-blue-600 hover:text-blue-800 text-xs font-medium">Edit</button>
                    <div v-else class="flex gap-2">
                      <button @click="saveOne(s)" :disabled="saving"
                        class="text-green-600 hover:text-green-800 text-xs font-medium disabled:opacity-40">Save</button>
                      <button @click="cancelEdit(s.id)"
                        class="text-gray-400 hover:text-gray-600 text-xs">Cancel</button>
                    </div>
                    <!-- Delete button — only if deletable -->
                    <button
                      v-if="s.is_deletable !== false && editing !== s.id"
                      @click="confirmDelete(s.id)"
                      class="text-red-400 hover:text-red-600 transition-colors"
                      title="Remove this fee"
                    >
                      <Trash2 class="h-3.5 w-3.5" />
                    </button>
                  </div>

                  <!-- Delete confirm mode -->
                  <div v-else class="flex items-center justify-end gap-2">
                    <span class="text-xs text-red-700 font-medium">Remove?</span>
                    <button @click="deleteMiscItem(s)"
                      class="text-red-600 hover:text-red-800 text-xs font-semibold">Yes</button>
                    <button @click="cancelDelete()"
                      class="text-gray-400 hover:text-gray-600 text-xs">No</button>
                  </div>
                </td>
              </tr>
            </tbody>
            <tfoot class="bg-gray-50 border-t-2 border-gray-200">
              <tr>
                <td colspan="2" class="px-5 py-3 text-sm font-semibold text-gray-700">
                  Total Miscellaneous
                </td>
                <td class="px-5 py-3 text-right font-mono font-bold text-gray-900">
                  {{ fmt(liveMiscTotal) }}
                </td>
                <td></td>
              </tr>
            </tfoot>
          </table>
        </div>

        <!-- Add New Misc Item -->
        <div class="mt-3">
          <button
            v-if="!showAddForm"
            @click="showAddForm = true"
            class="flex items-center gap-1.5 text-sm text-blue-600 hover:text-blue-800 font-medium"
          >
            <Plus class="h-4 w-4" /> Add miscellaneous fee item
          </button>

          <div v-else class="mt-3 rounded-xl border border-dashed border-blue-300 bg-blue-50 p-4 space-y-3">
            <p class="text-sm font-semibold text-blue-900">New Fee Item</p>
            <div class="grid grid-cols-2 gap-3">
              <div class="space-y-1">
                <label class="text-xs text-gray-600 font-medium">Fee Name</label>
                <input v-model="newItemLabel" type="text"
                  placeholder="e.g. Student Council Fee"
                  class="w-full border border-gray-300 rounded-md px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-300"
                />
              </div>
              <div class="space-y-1">
                <label class="text-xs text-gray-600 font-medium">Amount (₱)</label>
                <input v-model="newItemAmount" type="number" step="0.01" min="0"
                  placeholder="0.00"
                  class="w-full border border-gray-300 rounded-md px-3 py-1.5 text-sm text-right font-mono focus:outline-none focus:ring-2 focus:ring-blue-300"
                />
              </div>
            </div>
            <div class="space-y-1">
              <label class="text-xs text-gray-600 font-medium">Category</label>
              <select v-model="newItemCategory"
                class="w-full border border-gray-300 rounded-md px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-300">
                <option value="miscellaneous">Miscellaneous</option>
                <option value="other">Other</option>
              </select>
            </div>
            <div class="flex gap-2 justify-end pt-1">
              <button @click="showAddForm = false; newItemLabel = ''; newItemAmount = ''"
                class="text-sm text-gray-500 hover:text-gray-700 px-3 py-1.5">Cancel</button>
              <button
                @click="addMiscItem"
                :disabled="addSaving || !newItemLabel.trim()"
                class="text-sm bg-blue-600 hover:bg-blue-700 text-white px-4 py-1.5 rounded-md disabled:opacity-40 flex items-center gap-1.5"
              >
                <Plus class="h-3.5 w-3.5" />
                {{ addSaving ? 'Adding…' : 'Add Fee' }}
              </button>
            </div>
          </div>
        </div>
      </section>

      <!-- ── Payment Terms ─────────────────────────────────────────────────── -->
      <section>
        <div class="flex items-baseline justify-between mb-3">
          <h2 class="text-xs font-semibold text-gray-500 uppercase tracking-widest">Payment Terms</h2>
          <span :class="Math.abs(termTotal - 100) > 0.01 ? 'text-red-600 font-semibold' : 'text-gray-500'"
            class="text-sm">
            Total: {{ termTotal.toFixed(2) }}%
            <span v-if="Math.abs(termTotal - 100) > 0.01" class="text-xs"> — must equal 100%</span>
          </span>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 divide-y divide-gray-100">
          <div v-for="s in termSettings" :key="s.id"
            class="flex items-center justify-between px-5 py-4 hover:bg-gray-50">
            <span class="text-sm text-gray-700">{{ s.label }}</span>
            <div class="flex items-center gap-4">
              <span v-if="editing !== s.id"
                class="font-mono text-sm font-semibold text-gray-900 w-20 text-right">
                {{ parseFloat(s.amount).toFixed(2) }}%
              </span>
              <div v-else class="flex items-center gap-1">
                <input type="number" step="0.5" min="0" max="100"
                  v-model="editValues[s.id]"
                  class="w-20 border border-blue-400 rounded px-2 py-1 text-right text-sm font-mono focus:outline-none focus:ring-2 focus:ring-blue-300"
                  @keyup.enter="saveOne(s)"
                  @keyup.escape="cancelEdit(s.id)"
                />
                <span class="text-gray-400 text-sm">%</span>
              </div>
              <div class="w-20 text-right">
                <button v-if="editing !== s.id"
                  @click="startEdit(s.id, s.amount)"
                  class="text-blue-600 hover:text-blue-800 text-xs font-medium">Edit</button>
                <div v-else class="flex gap-2 justify-end">
                  <button @click="saveOne(s)" :disabled="saving"
                    class="text-green-600 hover:text-green-800 text-xs font-medium disabled:opacity-40">Save</button>
                  <button @click="cancelEdit(s.id)"
                    class="text-gray-400 hover:text-gray-600 text-xs">Cancel</button>
                </div>
              </div>
            </div>
          </div>
        </div>
        <p class="text-xs text-gray-400 mt-2">
          All 5 term percentages must sum to exactly 100%. System validates this on save.
        </p>
      </section>

    </div>
  </AppLayout>
</template>