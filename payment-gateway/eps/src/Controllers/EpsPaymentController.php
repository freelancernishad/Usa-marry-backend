<?php

namespace PaymentGateway\EPS\Controllers;

use App\Helpers\NotificationHelper;
use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use PaymentGateway\EPS\Events\EpsPaymentFailedEvent;
use PaymentGateway\EPS\Events\EpsPaymentSuccessEvent;
use PaymentGateway\EPS\Services\EpsService;

class EpsPaymentController extends Controller
{
    protected EpsService $epsService;

    public function __construct(EpsService $epsService)
    {
        $this->epsService = $epsService;
    }

    /**
     * Initiate EPS payment for a Plan Subscription.
     */
    public function initiate(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'plan_id' => 'required|exists:plans,id',
            'coupon_code' => 'nullable|string',
            'success_url' => 'nullable|string',
            'cancel_url' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $user = Auth::guard('user')->user() ?? Auth::user() ?? $request->user();
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated user'
            ], 401);
        }

        try {
            $plan = Plan::findOrFail($request->plan_id);

            // Calculate duration / end_date
            if (is_numeric($plan->duration)) {
                $endDate = now()->addMonths((int) $plan->duration);
            } elseif (preg_match('/^(\d+)\s*(month|months)$/i', $plan->duration, $matches)) {
                $endDate = now()->addMonths((int) $matches[1]);
            } elseif (preg_match('/^(\d+)\s*(year|years)$/i', $plan->duration, $matches)) {
                $endDate = now()->addYears((int) $matches[1]);
            } elseif (strtolower($plan->duration) === 'lifetime') {
                $endDate = null;
            } else {
                $endDate = now()->addMonth();
            }

            $originalUSD = (float) ($plan->discounted_price ?? $plan->original_price);
            $discountUSD = 0.0;
            $discountPercent = 0.0;
            $couponCode = null;

            // Coupon calculation
            if ($request->filled('coupon_code')) {
                $coupon = Coupon::where('code', $request->coupon_code)
                    ->where('is_active', true)
                    ->where(function ($query) {
                        $now = now();
                        $query->where(function ($q) use ($now) {
                            $q->whereNull('valid_from')->orWhere('valid_from', '<=', $now);
                        })->where(function ($q) use ($now) {
                            $q->whereNull('valid_until')->orWhere('valid_until', '>=', $now);
                        });
                    })
                    ->first();

                if ($coupon) {
                    $couponCode = $coupon->code;
                    if ($coupon->type === 'percentage') {
                        $discountPercent = (float) $coupon->value;
                        $discountUSD = ($originalUSD * $discountPercent) / 100;
                    } else {
                        $discountUSD = (float) $coupon->value;
                        $discountPercent = ($discountUSD / max($originalUSD, 1)) * 100;
                    }
                }
            }

            $finalUSD = max($originalUSD - $discountUSD, 0);

            // Convert to BDT for EPS
            $finalAmountBDT = $this->epsService->convertToBDT($finalUSD, 'USD');
            $originalAmountBDT = $this->epsService->convertToBDT($originalUSD, 'USD');
            $discountAmountBDT = $this->epsService->convertToBDT($discountUSD, 'USD');

            // Unique merchant transaction ID (EPS requires unique minimum 14-digit numeric/string)
            $merchantTxId = now()->format('YmdHis') . mt_rand(1000, 9999);

            // Create pending subscription
            $subscription = Subscription::create([
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'start_date' => now(),
                'end_date' => $endDate,
                'original_amount' => $originalAmountBDT,
                'final_amount' => $finalAmountBDT,
                'amount' => $finalAmountBDT,
                'currency' => 'BDT',
                'payment_method' => 'EPS',
                'transaction_id' => $merchantTxId,
                'status' => 'Pending',
                'plan_features' => $plan->features,
                'coupon_code' => $couponCode,
                'discount_amount' => $discountAmountBDT,
                'discount_percent' => $discountPercent,
            ]);

            // Set up return callback URLs to this controller
            $serverSuccessUrl = url('/eps/success?merchant_tx_id=' . $merchantTxId);
            $serverFailUrl = url('/eps/fail?merchant_tx_id=' . $merchantTxId);
            $serverCancelUrl = url('/eps/cancel?merchant_tx_id=' . $merchantTxId);

            $profile = $user->profile ?? null;
            $customerName = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''));
            if (empty($customerName)) {
                $customerName = $user->name ?? 'Valued Customer';
            }

            $epsPayload = [
                'merchantTransactionId' => $merchantTxId,
                'CustomerOrderId' => 'ORD-' . $subscription->id . '-' . time(),
                'totalAmount' => $finalAmountBDT,
                'customerName' => $customerName,
                'customerEmail' => $user->email ?? 'customer@usamarry.com',
                'customerPhone' => preg_replace('/\D/', '', $user->phone ?? '01700000000'),
                'customerAddress' => $profile->institution ?? $profile->occupation ?? 'Dhaka, Bangladesh',
                'customerCity' => $profile->city ?? 'Dhaka',
                'customerState' => $profile->state ?? 'Dhaka',
                'customerPostcode' => '1200',
                'customerCountry' => 'BD',
                'productName' => 'USA Marry ' . $plan->name,
                'valueA' => (string) $subscription->id,
                'valueB' => (string) $user->id,
                'valueC' => (string) $plan->id,
                'valueD' => (string) ($request->success_url ?? ''),
                'successUrl' => $serverSuccessUrl,
                'failUrl' => $serverFailUrl,
                'cancelUrl' => $serverCancelUrl,
            ];

            $epsResponse = $this->epsService->initializePayment($epsPayload);

            if (!$epsResponse['success'] || empty($epsResponse['redirect_url'])) {
                Log::error('EPS Payment Initialization Failed', ['response' => $epsResponse]);
                $subscription->update(['status' => 'Failed']);
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to initialize payment with EPS.',
                    'details' => $epsResponse['raw'] ?? null
                ], 500);
            }

            return response()->json([
                'success' => true,
                'url' => $epsResponse['redirect_url'],
                'redirect_url' => $epsResponse['redirect_url'],
                'merchant_transaction_id' => $merchantTxId,
                'subscription_id' => $subscription->id,
                'amount_bdt' => $finalAmountBDT,
            ]);

        } catch (Exception $e) {
            Log::error('EPS Initiate Exception', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Handle user redirect from EPS upon successful payment.
     */
    public function success(Request $request)
    {
        $merchantTxId = $request->query('merchant_tx_id')
            ?? $request->input('merchantTransactionId')
            ?? $request->query('merchantTransactionId')
            ?? $request->input('merchant_tx_id');

        Log::info('EPS Success Callback Received', [
            'merchant_tx_id' => $merchantTxId,
            'query' => $request->query(),
            'body' => $request->all(),
        ]);

        $frontendBase = rtrim(config('eps.frontend.base_url', 'http://localhost:3000'), '/');
        $successPath = config('eps.frontend.success_path', '/payment/success');
        $failedPath = config('eps.frontend.failed_path', '/payment/failed');

        if (!$merchantTxId) {
            return redirect()->away($frontendBase . $failedPath . '?error=missing_transaction_id');
        }

        $subscription = Subscription::where('transaction_id', $merchantTxId)->first();
        if (!$subscription) {
            Log::error('Subscription not found for EPS callback', ['merchant_tx_id' => $merchantTxId]);
            return redirect()->away($frontendBase . $failedPath . '?session_id=' . $merchantTxId . '&error=subscription_not_found');
        }

        try {
            // Server-to-server transaction status check with EPS
            $statusCheck = $this->epsService->checkTransactionStatus($merchantTxId);

            if ($this->epsService->isTransactionSuccessful($statusCheck)) {
                $this->completePayment($subscription, $statusCheck, $merchantTxId);
                return redirect()->away($frontendBase . $successPath . '?session_id=' . $merchantTxId);
            } else {
                Log::warning('EPS Transaction status was not successful', [
                    'merchant_tx_id' => $merchantTxId,
                    'statusCheck' => $statusCheck
                ]);
                $subscription->update(['status' => 'Failed']);
                event(new EpsPaymentFailedEvent($subscription, 'EPS status was not successful', $statusCheck));
                return redirect()->away($frontendBase . $failedPath . '?session_id=' . $merchantTxId . '&error=payment_unsuccessful');
            }
        } catch (Exception $e) {
            Log::error('EPS Success verification failed', [
                'merchant_tx_id' => $merchantTxId,
                'error' => $e->getMessage()
            ]);
            // If subscription was already completed by IPN, still let user succeed
            if ($subscription->status === 'Success') {
                return redirect()->away($frontendBase . $successPath . '?session_id=' . $merchantTxId);
            }

            return redirect()->away($frontendBase . $failedPath . '?session_id=' . $merchantTxId . '&error=' . urlencode($e->getMessage()));
        }
    }

    /**
     * Handle user redirect from EPS upon payment failure.
     */
    public function fail(Request $request)
    {
        $merchantTxId = $request->query('merchant_tx_id')
            ?? $request->input('merchantTransactionId')
            ?? $request->query('merchantTransactionId');

        Log::info('EPS Fail Callback Received', [
            'merchant_tx_id' => $merchantTxId,
            'all' => $request->all(),
        ]);

        if ($merchantTxId) {
            $subscription = Subscription::where('transaction_id', $merchantTxId)->first();
            if ($subscription && $subscription->status !== 'Success') {
                $subscription->update(['status' => 'Failed']);
                event(new EpsPaymentFailedEvent($subscription, 'User redirected to fail endpoint', $request->all()));
            }
        }

        $frontendBase = rtrim(config('eps.frontend.base_url', 'http://localhost:3000'), '/');
        $failedPath = config('eps.frontend.failed_path', '/payment/failed');

        return redirect()->away($frontendBase . $failedPath . '?session_id=' . $merchantTxId . '&error=payment_failed');
    }

    /**
     * Handle user redirect from EPS upon payment cancellation.
     */
    public function cancel(Request $request)
    {
        $merchantTxId = $request->query('merchant_tx_id')
            ?? $request->input('merchantTransactionId')
            ?? $request->query('merchantTransactionId');

        Log::info('EPS Cancel Callback Received', [
            'merchant_tx_id' => $merchantTxId,
            'all' => $request->all(),
        ]);

        if ($merchantTxId) {
            $subscription = Subscription::where('transaction_id', $merchantTxId)->first();
            if ($subscription && $subscription->status !== 'Success') {
                $subscription->update(['status' => 'Failed']);
                event(new EpsPaymentFailedEvent($subscription, 'Payment cancelled by user', $request->all()));
            }
        }

        $frontendBase = rtrim(config('eps.frontend.base_url', 'http://localhost:3000'), '/');
        $cancelPath = config('eps.frontend.cancel_path', '/payment/failed');

        return redirect()->away($frontendBase . $cancelPath . '?session_id=' . $merchantTxId . '&error=payment_cancelled');
    }

    /**
     * Handle Instant Payment Notification (IPN) / Webhook from EPS.
     */
    public function ipn(Request $request)
    {
        Log::info('EPS IPN Received', ['payload' => $request->all()]);

        $merchantTxId = $request->input('merchantTransactionId')
            ?? $request->input('MerchantTransactionId')
            ?? $request->query('merchant_tx_id');

        if (!$merchantTxId) {
            return response()->json(['error' => 'Missing merchantTransactionId'], 400);
        }

        $subscription = Subscription::where('transaction_id', $merchantTxId)->first();
        if (!$subscription) {
            return response()->json(['error' => 'Subscription not found'], 404);
        }

        if ($subscription->status === 'Success') {
            return response()->json(['status' => 'already_processed']);
        }

        try {
            $statusCheck = $this->epsService->checkTransactionStatus($merchantTxId);

            if ($this->epsService->isTransactionSuccessful($statusCheck)) {
                $this->completePayment($subscription, $statusCheck, $merchantTxId);
                return response()->json(['status' => 'success']);
            } else {
                $subscription->update(['status' => 'Failed']);
                event(new EpsPaymentFailedEvent($subscription, 'IPN verified failed status', $statusCheck));
                return response()->json(['status' => 'failed_verified']);
            }
        } catch (Exception $e) {
            Log::error('EPS IPN Error', ['error' => $e->getMessage()]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * API to check status of a transaction.
     */
    public function status(Request $request, string $merchantTxId)
    {
        try {
            $status = $this->epsService->checkTransactionStatus($merchantTxId);
            $subscription = Subscription::where('transaction_id', $merchantTxId)->first();

            return response()->json([
                'success' => true,
                'eps_status' => $status,
                'subscription_status' => $subscription?->status ?? 'Not Found',
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Internal method to complete payment, update subscription & payments table, send notification.
     */
    protected function completePayment(Subscription $subscription, array $statusCheck, string $merchantTxId): void
    {
        if ($subscription->status === 'Success') {
            return;
        }

        $financialEntity = $statusCheck['FinancialEntity'] ?? $statusCheck['financialEntity'] ?? 'Online';
        $paidAmount = (float) ($statusCheck['TotalAmount'] ?? $statusCheck['totalAmount'] ?? $subscription->amount);
        $epsTxId = $statusCheck['EpsTransactionId'] ?? $statusCheck['epsTransactionId'] ?? null;

        $subscription->update([
            'status' => 'Success',
            'payment_method' => 'EPS (' . $financialEntity . ')',
            'store_amount' => $paidAmount,
            'card_issuer' => $financialEntity,
        ]);

        // Record in payments table
        $payment = Payment::updateOrCreate(
            ['transaction_id' => $merchantTxId],
            [
                'session_id' => $epsTxId ?? $merchantTxId,
                'user_id' => $subscription->user_id,
                'gateway' => 'eps',
                'currency' => 'BDT',
                'amount' => $paidAmount,
                'status' => 'completed',
                'payable_type' => 'App\\Models\\Plan',
                'payable_id' => $subscription->plan_id,
                'payment_method' => 'EPS (' . $financialEntity . ')',
                'payer_email' => $subscription->user->email ?? null,
                'paid_at' => now(),
                'response_data' => $statusCheck,
            ]
        );

        // Send notifications
        $user = $subscription->user;
        $planName = $subscription->plan->name ?? 'Plan';
        if ($user && class_exists(NotificationHelper::class)) {
            try {
                NotificationHelper::sendPlanPurchaseNotification($user, $planName, $paidAmount, 'subscriptions', $subscription->id);
            } catch (\Throwable $e) {
                Log::warning('NotificationHelper failed', ['error' => $e->getMessage()]);
            }
        }

        // Dispatch Event
        event(new EpsPaymentSuccessEvent($subscription, $payment, $statusCheck));

        Log::info('EPS Payment Completed Successfully', [
            'merchant_tx_id' => $merchantTxId,
            'subscription_id' => $subscription->id,
            'amount' => $paidAmount,
            'financial_entity' => $financialEntity
        ]);
    }
}

