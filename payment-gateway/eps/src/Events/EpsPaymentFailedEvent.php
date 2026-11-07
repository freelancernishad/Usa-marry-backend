<?php

namespace PaymentGateway\EPS\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class EpsPaymentFailedEvent
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $subscription;
    public $reason;
    public $epsResponse;

    /**
     * Create a new event instance.
     */
    public function __construct($subscription = null, string $reason = '', array $epsResponse = [])
    {
        $this->subscription = $subscription;
        $this->reason = $reason;
        $this->epsResponse = $epsResponse;
    }
}

