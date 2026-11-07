<?php

namespace PaymentGateway\EPS\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class EpsPaymentSuccessEvent
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $subscription;
    public $payment;
    public $epsResponse;

    /**
     * Create a new event instance.
     */
    public function __construct($subscription, $payment = null, array $epsResponse = [])
    {
        $this->subscription = $subscription;
        $this->payment = $payment;
        $this->epsResponse = $epsResponse;
    }
}

