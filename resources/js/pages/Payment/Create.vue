<script setup lang="ts">
import { ref, computed } from 'vue'
import axios from 'axios'

const props = defineProps<{
    student: { id: number; name?: string }
    publicKey?: string
}>()

const form = ref({
    amount: '',
    description: '',
    reference_number: '',
})

const selectedMethod = ref('ewallet')
const loading = ref(false)

const paymentMethods = [
    { value: 'ewallet', label: 'GCash / Maya', icon: '📱' },
    { value: 'card',    label: 'Credit Card',  icon: '💳' },
    { value: 'bank_transfer', label: 'PNB Transfer', icon: '🏦' },
]

const buttonLabel = computed(() => {
    if (selectedMethod.value === 'bank_transfer') return '📤 Submit Bank Transfer'
    if (selectedMethod.value === 'card') return '💳 Pay with Card'
    return '📱 Pay with GCash / Maya'
})

async function submitPayment() {
    if (!form.value.amount || !form.value.description) return
    loading.value = true

    // Kuhanin ang CSRF token mula sa meta tag (auto-inject ng Laravel/Inertia)
    const csrfToken = document.querySelector('meta[name="csrf-token"]')
        ?.getAttribute('content') ?? ''

    try {
        if (selectedMethod.value === 'bank_transfer') {

            const res = await fetch('/student/payment/bank-transfer', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({
                    student_id:       props.student.id,
                    amount:           form.value.amount,
                    description:      form.value.description,
                    reference_number: form.value.reference_number,
                }),
            })

            if (!res.ok) throw new Error('Bank transfer failed')
            alert('✅ Bank transfer submitted! Please wait for admin verification.')

        } else {

            const res = await fetch('/student/payment/checkout', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({
                    student_id:  props.student.id,
                    amount:      form.value.amount,
                    description: form.value.description,
                }),
            })

            if (!res.ok) {
                const err = await res.json()
                throw new Error(err?.error ?? 'Checkout failed')
            }

            const data = await res.json()
            window.location.href = data.checkout_url
        }

    } catch (e: any) {
        alert('❌ ' + (e?.message ?? 'Something went wrong. Please try again.'))
    } finally {
        loading.value = false
    }
}
</script>
<template>
    <div class="min-h-screen bg-gray-50 p-6">
        <div class="max-w-2xl mx-auto">

            <!-- Header -->
            <div class="mb-6">
                <h1 class="text-3xl font-bold text-gray-800">Make a Payment</h1>
                <p class="text-gray-500 mt-1">Pay your school fees securely</p>
            </div>

            <!-- Payment Form Card -->
            <div class="bg-white rounded-2xl shadow p-6 space-y-5">

                <!-- Amount -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        Amount (₱)
                    </label>
                    <input
                        v-model="form.amount"
                        type="number"
                        min="100"
                        placeholder="e.g. 5000"
                        class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                    />
                </div>

                <!-- Description -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        Payment For
                    </label>
                    <input
                        v-model="form.description"
                        type="text"
                        placeholder="e.g. Tuition Fee - 1st Semester"
                        class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                    />
                </div>

                <!-- Payment Method Tabs -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Payment Method
                    </label>
                    <div class="flex gap-3">
                        <button
                            v-for="method in paymentMethods"
                            :key="method.value"
                            @click="selectedMethod = method.value"
                            :class="[
                                'flex-1 border-2 rounded-xl py-3 px-2 text-center text-sm font-medium transition-all',
                                selectedMethod === method.value
                                    ? 'border-blue-500 bg-blue-50 text-blue-700'
                                    : 'border-gray-200 text-gray-600 hover:border-gray-400'
                            ]"
                        >
                            <div class="text-2xl mb-1">{{ method.icon }}</div>
                            {{ method.label }}
                        </button>
                    </div>
                </div>

                <!-- Bank Transfer Details (shown only if bank transfer selected) -->
                <div v-if="selectedMethod === 'bank_transfer'" class="bg-yellow-50 border border-yellow-200 rounded-xl p-4 space-y-2">
                    <p class="font-semibold text-yellow-800">🏦 Bank Transfer Instructions</p>
                    <p class="text-sm text-yellow-700">Bank: <strong>PNB - Philippine National Bank</strong></p>
                    <p class="text-sm text-yellow-700">Account Name: <strong>CCDI School</strong></p>
                    <p class="text-sm text-yellow-700">Account Number: <strong>1234-5678-9012</strong></p>
                    <p class="text-sm text-yellow-700 mt-2">After transferring, enter your reference number below:</p>
                    <input
                        v-model="form.reference_number"
                        type="text"
                        placeholder="Reference / Transaction Number"
                        class="w-full border border-yellow-300 rounded-lg px-4 py-2 bg-white focus:outline-none focus:ring-2 focus:ring-yellow-400"
                    />
                </div>

                <!-- GCash/Maya Info -->
                <div v-if="selectedMethod === 'ewallet'" class="bg-blue-50 border border-blue-200 rounded-xl p-4">
                    <p class="font-semibold text-blue-800">📱 GCash / Maya Payment</p>
                    <p class="text-sm text-blue-700 mt-1">
                        You will be redirected to PayMongo's secure checkout page where you can choose GCash or Maya.
                        <strong>No real money</strong> — test mode lang ito! 🎉
                    </p>
                </div>

                <!-- Card Info -->
                <div v-if="selectedMethod === 'card'" class="bg-green-50 border border-green-200 rounded-xl p-4">
                    <p class="font-semibold text-green-800">💳 Test Card Details</p>
                    <p class="text-sm text-green-700 mt-1">Card Number: <strong>4343434343434345</strong></p>
                    <p class="text-sm text-green-700">Expiry: <strong>Any future date</strong> | CVC: <strong>Any 3 digits</strong></p>
                </div>

                <!-- Submit Button -->
                <button
                    @click="submitPayment"
                    :disabled="loading || !form.amount || !form.description"
                    class="w-full bg-blue-600 hover:bg-blue-700 disabled:opacity-50 text-white font-semibold py-3 rounded-xl transition-all"
                >
                    {{ loading ? 'Processing...' : buttonLabel }}
                </button>

                <!-- Test Mode Badge -->
                <p class="text-xs text-center text-gray-400">
                    🔒 Secured by PayMongo · Test Mode (No real charges)
                </p>
            </div>
        </div>
    </div>
</template>