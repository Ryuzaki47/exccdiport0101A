<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/layouts/AppLayout.vue'
import Breadcrumbs from '@/components/Breadcrumbs.vue'
import { Input } from '@/components/ui/input'
import { Loader2, Save, X, Pencil } from 'lucide-vue-next'

// ─── Types ───────────────────────────────────────────────────────────────────

interface Subject {
  id: number
  code: string
  name: string
  lec_units: number
  lab_units: number
  year_level: string
  semester: string
  course: string
  is_active: boolean
}

interface PaginatedSubjects {
  data: Subject[]
  links: Array<{ url: string | null; label: string; active: boolean }>
  meta: { current_page: number; last_page: number; total: number }
}

// ─── Props ───────────────────────────────────────────────────────────────────

const props = defineProps<{
  subjects: PaginatedSubjects
  filters: { course?: string; year_level?: string; semester?: string; search?: string }
  courses: string[]
  yearLevels: string[]
  semesters: string[]
}>()

// ─── Breadcrumbs ─────────────────────────────────────────────────────────────

const breadcrumbs = [
  { title: 'Dashboard', href: route('accounting.dashboard') },
  { title: 'Subjects', href: route('subjects.index') },
]

// ─── Filter state ─────────────────────────────────────────────────────────────

const filters = ref({ ...props.filters })

function applyFilters() {
  router.get(route('subjects.index'), filters.value, { preserveScroll: true, replace: true })
}

function resetFilters() {
  filters.value = {}
  router.get(route('subjects.index'), {}, { preserveScroll: true, replace: true })
}

// ─── Inline edit state ───────────────────────────────────────────────────────

const editingId  = ref<number | null>(null)
const editLec    = ref(0)
const editLab    = ref(0)
const editSaving = ref(false)
const flashMsg   = ref('')
const flashType  = ref<'success' | 'error'>('success')

function startEdit(subject: Subject) {
  editingId.value = subject.id
  editLec.value   = subject.lec_units
  editLab.value   = subject.lab_units
}

function cancelEdit() {
  editingId.value = null
}

async function saveInline(subject: Subject) {
  editSaving.value = true

  try {
    const res = await fetch(route('subjects.inline-update', subject.id), {
      method:  'PATCH',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN':  (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '',
        'Accept':        'application/json',
      },
      body: JSON.stringify({
        lec_units: editLec.value,
        lab_units: editLab.value,
      }),
    })

    const data = await res.json()

    if (data.success) {
      // Update in-place so we don't need a full reload
      subject.lec_units = data.lec_units
      subject.lab_units = data.lab_units
      editingId.value   = null
      flashMsg.value    = `${subject.name} updated.`
      flashType.value   = 'success'
    } else {
      flashMsg.value  = 'Update failed.'
      flashType.value = 'error'
    }
  } catch {
    flashMsg.value  = 'Network error — try again.'
    flashType.value = 'error'
  } finally {
    editSaving.value = false
    setTimeout(() => flashMsg.value = '', 3000)
  }
}
</script>

<template>
  <AppLayout title="Subjects">
    <div class="w-full p-6 space-y-6">
      <Breadcrumbs :items="breadcrumbs" />

      <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold">Curriculum Subjects</h1>
        <p class="text-sm text-muted-foreground">
          {{ subjects.meta?.total ?? subjects.data.length }} subjects
        </p>
      </div>

      <!-- Flash message -->
      <div v-if="flashMsg"
        :class="flashType === 'success'
          ? 'bg-green-50 border-green-200 text-green-800'
          : 'bg-red-50 border-red-200 text-red-800'"
        class="rounded-lg border px-4 py-3 text-sm">
        {{ flashMsg }}
      </div>

      <!-- Filters -->
      <div class="flex flex-wrap gap-3 items-end">
        <div>
          <label class="text-xs text-muted-foreground block mb-1">Course</label>
          <select v-model="filters.course" @change="applyFilters"
            class="h-9 rounded-md border border-input bg-transparent px-3 text-sm focus:outline-none focus:ring-1 focus:ring-ring">
            <option value="">All Courses</option>
            <option v-for="c in courses" :key="c" :value="c">{{ c }}</option>
          </select>
        </div>
        <div>
          <label class="text-xs text-muted-foreground block mb-1">Year Level</label>
          <select v-model="filters.year_level" @change="applyFilters"
            class="h-9 rounded-md border border-input bg-transparent px-3 text-sm focus:outline-none focus:ring-1 focus:ring-ring">
            <option value="">All Years</option>
            <option v-for="y in yearLevels" :key="y" :value="y">{{ y }}</option>
          </select>
        </div>
        <div>
          <label class="text-xs text-muted-foreground block mb-1">Semester</label>
          <select v-model="filters.semester" @change="applyFilters"
            class="h-9 rounded-md border border-input bg-transparent px-3 text-sm focus:outline-none focus:ring-1 focus:ring-ring">
            <option value="">All Semesters</option>
            <option v-for="s in semesters" :key="s" :value="s">{{ s }}</option>
          </select>
        </div>
        <div>
          <label class="text-xs text-muted-foreground block mb-1">Search</label>
          <Input v-model="filters.search" placeholder="Code or name…" class="h-9 w-48"
            @keyup.enter="applyFilters" />
        </div>
        <button @click="resetFilters"
          class="h-9 px-3 text-sm text-muted-foreground hover:text-foreground underline">
          Reset
        </button>
      </div>

      <!-- Table -->
      <div class="rounded-xl border overflow-hidden">
        <table class="w-full text-sm">
          <thead class="bg-muted text-muted-foreground text-xs uppercase">
            <tr>
              <th class="text-left px-4 py-3">Code</th>
              <th class="text-left px-4 py-3">Subject Name</th>
              <th class="text-left px-4 py-3">Course</th>
              <th class="text-center px-4 py-3">Year</th>
              <th class="text-center px-4 py-3">Semester</th>
              <th class="text-center px-4 py-3">LEC</th>
              <th class="text-center px-4 py-3">LAB</th>
              <th class="text-center px-4 py-3">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-border">
            <tr v-for="s in subjects.data" :key="s.id"
              class="hover:bg-muted/50 transition-colors"
              :class="{ 'bg-amber-50': s.code.toUpperCase().startsWith('NSTP') || s.name.toUpperCase().includes('NATIONAL SERVICE') }">
              <td class="px-4 py-3 font-mono text-xs">{{ s.code }}</td>
              <td class="px-4 py-3 max-w-xs truncate">
                {{ s.name }}
                <span v-if="s.code.toUpperCase().startsWith('NSTP') || s.name.toUpperCase().includes('NATIONAL SERVICE')"
                  class="ml-1 text-xs bg-amber-200 text-amber-800 px-1 rounded">NSTP</span>
                <span v-else-if="s.code.toUpperCase().startsWith('PATHFIT') || s.code.toUpperCase().startsWith('PE')"
                  class="ml-1 text-xs bg-purple-200 text-purple-800 px-1 rounded">PE</span>
              </td>
              <td class="px-4 py-3 text-xs text-muted-foreground max-w-xs truncate">{{ s.course }}</td>
              <td class="px-4 py-3 text-center text-xs">{{ s.year_level }}</td>
              <td class="px-4 py-3 text-center text-xs">{{ s.semester }}</td>

              <!-- LEC units — inline edit or display -->
              <td class="px-4 py-3 text-center">
                <span v-if="editingId !== s.id"
                  class="font-semibold text-blue-700">{{ s.lec_units }}</span>
                <input v-else type="number" v-model.number="editLec" min="0" max="10"
                  class="w-14 border border-blue-400 rounded px-1 py-0.5 text-center text-sm font-mono focus:outline-none focus:ring-1 focus:ring-blue-400"
                />
              </td>

              <!-- LAB units — inline edit or display -->
              <td class="px-4 py-3 text-center">
                <span v-if="editingId !== s.id"
                  :class="s.lab_units > 0 ? 'text-orange-600 font-semibold' : 'text-muted-foreground'">
                  {{ s.lab_units }}
                </span>
                <input v-else type="number" v-model.number="editLab" min="0" max="5"
                  class="w-14 border border-orange-400 rounded px-1 py-0.5 text-center text-sm font-mono focus:outline-none focus:ring-1 focus:ring-orange-400"
                />
              </td>

              <!-- Actions -->
              <td class="px-4 py-3 text-center">
                <div v-if="editingId !== s.id">
                  <button @click="startEdit(s)"
                    class="inline-flex items-center gap-1 text-xs text-blue-600 hover:text-blue-800 font-medium">
                    <Pencil class="h-3 w-3" /> Edit
                  </button>
                </div>
                <div v-else class="flex items-center justify-center gap-2">
                  <button @click="saveInline(s)" :disabled="editSaving"
                    class="inline-flex items-center gap-1 text-xs text-green-600 hover:text-green-800 font-medium disabled:opacity-40">
                    <Loader2 v-if="editSaving" class="h-3 w-3 animate-spin" />
                    <Save v-else class="h-3 w-3" />
                    Save
                  </button>
                  <button @click="cancelEdit"
                    class="inline-flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground">
                    <X class="h-3 w-3" /> Cancel
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <div v-if="subjects.links?.length" class="flex justify-center gap-1 flex-wrap">
        <component
          v-for="link in subjects.links"
          :key="link.label"
          :is="link.url ? 'a' : 'span'"
          :href="link.url ?? undefined"
          v-html="link.label"
          :class="[
            'px-3 py-1 rounded text-sm border transition-colors',
            link.active
              ? 'bg-primary text-primary-foreground border-primary'
              : link.url
                ? 'border-border hover:bg-muted cursor-pointer'
                : 'border-border text-muted-foreground cursor-default opacity-50',
          ]"
        />
      </div>
    </div>
  </AppLayout>
</template>