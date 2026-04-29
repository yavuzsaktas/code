<?php

namespace Eticsoft\PaythorClient\Models\Payment;

class Refund
{
    private int $amount;
    private string $currency;
    private string $paymentInstrument;

    public function __construct(int $amount, string $currency, string $paymentInstrument)
    {
        $this->amount = $amount;
        $this->currency = $currency;
        $this->paymentInstrument = $paymentInstrument;
    }

    public function toArray(): array
    {
        return [
            'amount' => $this->amount,
            'currency' => $this->currency,
            'payment_instrument' => $this->paymentInstrument
        ];
    }
}