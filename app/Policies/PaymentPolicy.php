<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Payment;

class PaymentPolicy
{
    /**
     * Determine whether the user can verify the payment.
     */
    public function verifyPayment(User $user, Payment $payment): bool
    {
        return $user->isAdmin() || $user->isAccounting();
    }
}