<?php

namespace App\Jobs;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ProcessLoyaltyWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public Order $order;

    /**
     * Create a new job instance.
     */
    public function __construct(Order $order)
    {
        $this->order = $order;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        if (!$this->order->loyalty_token) {
            return;
        }

        $loyaltyApiUrl = config('services.loyalty.api_url') ?: env('LOYALTY_API_URL', 'http://127.0.0.1:8001/api');
        $webhookUrl = rtrim($loyaltyApiUrl, '/') . '/loyalty/transactions/webhook';

        $payload = [
            'qr_token' => $this->order->loyalty_token,
            'amount' => $this->order->total,
            'transaction_reference' => $this->order->order_number,
        ];

        $secret = config('services.loyalty.webhook_secret') ?: env('LOYALTY_WEBHOOK_SECRET');
        $body = json_encode($payload);
        $signature = $secret ? hash_hmac('sha256', $body, $secret) : '';

        try {
            Log::info("Sending Loyalty Webhook for Order #{$this->order->order_number} (Token: {$this->order->loyalty_token}) to {$webhookUrl}", $payload);
            
            $request = Http::timeout(15)->withoutVerifying();
            
            if ($secret) {
                $request = $request->withHeaders([
                    'X-Webhook-Signature' => $signature,
                ]);
            }
            
            $response = $request->withBody($body, 'application/json')->post($webhookUrl);

            if ($response->successful()) {
                Log::info("Loyalty Webhook success for Order #{$this->order->order_number}: " . $response->body());
            } else {
                Log::error("Loyalty Webhook failed with HTTP status {$response->status()} for Order #{$this->order->order_number}: " . $response->body());
            }
        } catch (\Exception $e) {
            Log::error("Loyalty Webhook exception for Order #{$this->order->order_number}: " . $e->getMessage());
        }
    }
}
