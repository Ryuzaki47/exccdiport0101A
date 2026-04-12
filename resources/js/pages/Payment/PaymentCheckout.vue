<template>
  <div class="max-w-md mx-auto p-6 bg-white rounded-xl shadow">
    <h2 class="text-2xl font-bold mb-2">Pay via PayMongo</h2>
    <p class="text-gray-500 mb-6 text-sm">
      Supports GCash, Maya, and Credit/Debit Card
    </p>

    <div class="space-y-4">
      <div>
        <label class="block text-sm font-medium mb-1">Amount (₱)</label>
        <input v-model="amount" type="number" min="100"
               class="w-full border rounded-lg p-2" />
      </div>
      <div>
        <label class="block text-sm font-medium mb-1">Description</label>
        <input v-model="description" type="text"
               class="w-full border rounded-lg p-2"
               placeholder="e.g. Tuition Fee - 1st Semester" />
      </div>

      <button @click="pay" :disabled="loading"
              class="w-full bg-green-600 text-white py-3 rounded-lg hover:bg-green-700 font-semibold">
        {{ loading ? 'Processing...' : '💳 Proceed to Payment' }}
      </button>

      <!-- Test mode reminder -->
      <p class="text-xs text-center text-yellow-600 bg-yellow-50 p-2 rounded">
        ⚠️ Test Mode — Use card <strong>4343434343434345</strong> or select GCash/Maya
        sa PayMongo checkout para mag-simulate ng payment.
      </p>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import axios from 'axios'

const props = defineProps<{ studentId: number; transactionId?: number }>()

const amount = ref(500)
const description = ref('')
const loading = ref(false)

async function pay() {
  loading.value = true
  try {
    const { data } = await axios.post('/api/payments/checkout', {
      student_id:     props.studentId,
      transaction_id: props.transactionId,
      amount:         amount.value,
      description:    description.value,
    })
    // Redirect to PayMongo's hosted checkout page
    window.location.href = data.checkout_url
  } catch (e) {
    alert('Error creating payment. Please try again.')
  } finally {
    loading.value = false
  }
}
</script>