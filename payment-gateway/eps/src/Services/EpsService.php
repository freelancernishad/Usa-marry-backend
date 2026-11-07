<?php

namespace PaymentGateway\EPS\Services;

use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class EpsService
{
    protected array $config;

    public function __construct()
    {
        $this->config = config('eps') ?? [];
    }

    /**
     * Get a setting value, checking DB system_settings first, then config / env.
     */
    public function getSetting(string $key, $default = null)
    {
        try {
            $dbValue = DB::table('system_settings')
                ->where('key', $key)
                ->value('value');

            if ($dbValue !== null && $dbValue !== '') {
                if (strtolower($dbValue) === 'true') return true;
                if (strtolower($dbValue) === 'false') return false;
                return $dbValue;
            }
        } catch (\Throwable $e) {
            // DB table might not exist in some environments, fallback to config
        }

        $configKey = strtolower(str_replace('EPS_', '', $key));
        return $this->config[$configKey] ?? $default;
    }

    /**
     * Check if currently running in sandbox mode.
     */
    public function isSandbox(): bool
    {
        return (bool) $this->getSetting('EPS_SANDBOX', $this->config['sandbox'] ?? true);
    }

    /**
     * Get the active API Base URL.
     */
    public function getApiBaseUrl(): string
    {
        $mode = $this->isSandbox() ? 'sandbox' : 'live';
        return rtrim($this->config['api_base_url'][$mode] ?? 'https://sandboxpgapi.eps.com.bd', '/');
    }

    /**
     * Get the active Gateway Base URL.
     */
    public function getGatewayBaseUrl(): string
    {
        $mode = $this->isSandbox() ? 'sandbox' : 'live';
        return rtrim($this->config['gateway_base_url'][$mode] ?? 'https://sandboxpg.eps.com.bd', '/');
    }

    public function getStoreId(): string
    {
        return (string) $this->getSetting('EPS_STORE_ID', $this->config['store_id'] ?? '');
    }

    public function getUsername(): string
    {
        return (string) $this->getSetting('EPS_USERNAME', $this->config['username'] ?? '');
    }

    public function getPassword(): string
    {
        return (string) $this->getSetting('EPS_PASSWORD', $this->config['password'] ?? '');
    }

    public function getHashKey(): string
    {
        return (string) $this->getSetting('EPS_HASH_KEY', $this->config['hash_key'] ?? '');
    }

    /**
     * Generate HMAC SHA-512 Hash encoded in Base64 according to EPS specifications.
     * Step 1: Encode Hash Key using UTF-8
     * Step 2: Create HMACSHA512 using key
     * Step 3: Compute Hash using data
     * Step 4: Return Base64 string
     */
    public function generateHash(string $data): string
    {
        $hashKey = $this->getHashKey();
        return base64_encode(hash_hmac('sha512', $data, $hashKey, true));
    }

    public function getCacheKey(): string
    {
        $mode = $this->isSandbox() ? 'sandbox' : 'live';
        $user = $this->getUsername();
        $store = $this->getStoreId();
        return 'eps_auth_token_' . md5($user . '_' . $store . '_' . $mode);
    }

    /**
     * Get Authentication Token from EPS API.
     * Uses cache to avoid redundant requests until close to expiration.
     */
    public function getToken(): string
    {
        $cacheKey = $this->getCacheKey();

        $cachedToken = Cache::get($cacheKey);
        if ($cachedToken) {
            return $cachedToken;
        }

        $username = $this->getUsername();
        $password = $this->getPassword();
        $url = $this->getApiBaseUrl() . '/v1/Auth/GetToken';

        $xHash = $this->generateHash($username);

        Log::info('Requesting EPS Token', [
            'url' => $url,
            'username' => $username,
            'is_sandbox' => $this->isSandbox()
        ]);

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'x-hash' => $xHash,
        ])->timeout(20)->post($url, [
            'userName' => $username,
            'password' => $password,
        ]);

        if (!$response->successful()) {
            Log::error('EPS Token request failed HTTP', [
                'status' => $response->status(),
                'body' => $response->body()
            ]);
            throw new Exception('EPS Authentication failed: HTTP ' . $response->status());
        }

        $data = $response->json();

        if (empty($data['token'])) {
            $errorMsg = $data['errorMessage'] ?? 'Unknown EPS auth error';
            Log::error('EPS Token response error', ['data' => $data]);
            throw new Exception('EPS Authentication Error: ' . $errorMsg);
        }

        $token = $data['token'];

        // Default cache token for 50 minutes (tokens are typically valid for 1 hour or more)
        $ttlMinutes = 50;
        if (!empty($data['expireDate'])) {
            try {
                $expiresAt = \Carbon\Carbon::parse($data['expireDate']);
                $diff = now()->diffInMinutes($expiresAt, false);
                if ($diff > 5) {
                    $ttlMinutes = $diff - 5;
                }
            } catch (\Throwable $e) {
                // Ignore parse errors, use default 50 mins
            }
        }

        Cache::put($cacheKey, $token, now()->addMinutes($ttlMinutes));

        return $token;
    }

    /**
     * Initialize Payment with EPS.
     */
    public function initializePayment(array $paymentData): array
    {
        $token = $this->getToken();
        $url = $this->getApiBaseUrl() . '/v1/EPSEngine/InitializeEPS';

        $merchantTxId = (string) $paymentData['merchantTransactionId'];
        $xHash = $this->generateHash($merchantTxId);

        $payload = array_merge([
            'storeId' => $this->getStoreId(),
            'merchantTransactionId' => $merchantTxId,
            'CustomerOrderId' => $paymentData['CustomerOrderId'] ?? ('ORD-' . $merchantTxId),
            'transactionTypeId' => (int) ($this->config['transaction_type_id'] ?? 10),
            'financialEntityId' => 0,
            'transitionStatusId' => 0,
            'totalAmount' => (float) $paymentData['totalAmount'],
            'ipAddress' => request()->ip() ?? '127.0.0.1',
            'version' => $this->config['version'] ?? '1',
            'successUrl' => $paymentData['successUrl'],
            'failUrl' => $paymentData['failUrl'],
            'cancelUrl' => $paymentData['cancelUrl'],
            'customerName' => $paymentData['customerName'] ?? 'Valued Customer',
            'customerEmail' => $paymentData['customerEmail'] ?? 'customer@example.com',
            'customerAddress' => $paymentData['customerAddress'] ?? 'Dhaka, Bangladesh',
            'customerAddress2' => $paymentData['customerAddress2'] ?? '',
            'customerCity' => $paymentData['customerCity'] ?? 'Dhaka',
            'customerState' => $paymentData['customerState'] ?? 'Dhaka',
            'customerPostcode' => $paymentData['customerPostcode'] ?? '1200',
            'customerCountry' => $paymentData['customerCountry'] ?? 'BD',
            'customerPhone' => $paymentData['customerPhone'] ?? '01700000000',
            'shipmentName' => $paymentData['shipmentName'] ?? 'Digital Delivery',
            'shipmentAddress' => $paymentData['shipmentAddress'] ?? 'Online',
            'shipmentAddress2' => '',
            'shipmentCity' => 'Dhaka',
            'shipmentState' => 'Dhaka',
            'shipmentPostcode' => '1200',
            'shipmentCountry' => 'BD',
            'valueA' => (string) ($paymentData['valueA'] ?? ''),
            'valueB' => (string) ($paymentData['valueB'] ?? ''),
            'valueC' => (string) ($paymentData['valueC'] ?? ''),
            'valueD' => (string) ($paymentData['valueD'] ?? ''),
            'shippingMethod' => $this->config['shipping_method'] ?? 'NO',
            'noOfItem' => '1',
            'productName' => $paymentData['productName'] ?? 'USA Marry Subscription',
            'productProfile' => 'general',
            'productCategory' => 'Service',
            'ProductList' => [
                [
                    'ProductName' => $paymentData['productName'] ?? 'USA Marry Subscription',
                    'NoOfItem' => '1',
                    'ProductProfile' => 'general',
                    'ProductCategory' => 'Subscription',
                    'ProductPrice' => (string) $paymentData['totalAmount'],
                ]
            ],
        ], $paymentData);

        Log::info('Sending EPS InitializeEPS Request', [
            'url' => $url,
            'merchantTransactionId' => $merchantTxId,
            'totalAmount' => $payload['totalAmount']
        ]);

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $token,
            'x-hash' => $xHash,
        ])->timeout(30)->post($url, $payload);

        if (!$response->successful()) {
            if (in_array($response->status(), [401, 403, 404])) {
                Cache::forget($this->getCacheKey());
            }
            Log::error('EPS InitializeEPS failed HTTP', [
                'status' => $response->status(),
                'body' => $response->body()
            ]);
            throw new Exception('EPS Initialize error: HTTP ' . $response->status() . ' - ' . $response->body());
        }

        $resData = $response->json();

        Log::info('EPS InitializeEPS Response', ['response' => $resData]);

        if (!empty($resData['errorCode']) && $resData['errorCode'] !== '0') {
            throw new Exception('EPS Error (' . $resData['errorCode'] . '): ' . ($resData['errorMessage'] ?? 'Unknown error'));
        }

        $redirectUrl = $resData['redirectURL'] ?? $resData['RedirectURL'] ?? null;
        $transactionId = $resData['transactionId'] ?? $resData['TransactionId'] ?? null;

        if (!$redirectUrl && $transactionId) {
            $redirectUrl = $this->getGatewayBaseUrl() . '/PG?data=' . $transactionId;
        }

        return [
            'success' => !empty($redirectUrl),
            'transaction_id' => $transactionId,
            'redirect_url' => $redirectUrl,
            'raw' => $resData,
        ];
    }

    /**
     * Verify transaction status with EPS using merchantTransactionId.
     */
    public function checkTransactionStatus(string $merchantTransactionId): array
    {
        $token = $this->getToken();
        $url = $this->getApiBaseUrl() . '/v1/EPSEngine/CheckMerchantTransactionStatus';

        $xHash = $this->generateHash($merchantTransactionId);

        Log::info('Checking EPS Transaction Status', [
            'merchantTransactionId' => $merchantTransactionId,
            'url' => $url
        ]);

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'x-hash' => $xHash,
        ])->timeout(20)->get($url, [
            'merchantTransactionId' => $merchantTransactionId,
        ]);

        if (!$response->successful()) {
            Log::error('EPS CheckTransactionStatus HTTP Error', [
                'status' => $response->status(),
                'body' => $response->body()
            ]);
            throw new Exception('EPS Status Check failed: HTTP ' . $response->status());
        }

        $res = $response->json();
        Log::info('EPS CheckTransactionStatus Response', ['response' => $res]);

        return is_array($res) ? $res : [];
    }

    /**
     * Check if status response indicates success.
     */
    public function isTransactionSuccessful(array $statusResponse): bool
    {
        $status = strtolower($statusResponse['status'] ?? $statusResponse['Status'] ?? '');
        return in_array($status, ['success', 'successful', 'completed', 'valid', 'validated', '1']);
    }

    /**
     * Convert USD or other currencies to BDT.
     */
    public function convertToBDT(float $amount, string $fromCurrency = 'USD'): float
    {
        $from = strtolower($fromCurrency);
        if ($from === 'bdt') {
            return round($amount, 2);
        }

        $cacheKey = "eps_currency_rate_{$from}_bdt";

        $rate = Cache::remember($cacheKey, now()->addHours(6), function () use ($from) {
            try {
                $url = "https://cdn.jsdelivr.net/npm/@fawazahmed0/currency-api@latest/v1/currencies/{$from}.json";
                $res = Http::timeout(10)->get($url);
                if ($res->successful()) {
                    $data = $res->json();
                    if (isset($data[$from]['bdt'])) {
                        return (float) $data[$from]['bdt'];
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('Failed to fetch dynamic BDT exchange rate, falling back to 120.00', ['error' => $e->getMessage()]);
            }
            // Sensible fallback exchange rate if network fails
            return 120.00;
        });

        return round($amount * $rate, 2);
    }
}

