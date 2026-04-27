<?php

namespace Eticsoft\PaythorClient\Models\Payment;

use Eticsoft\PaythorClient\Models\Payment\Instrument\CreditCard;

class Capture
{
    private int $amount;
    private string $currency;
    private array $paymentInstrument;

    /**
     * @param int $amount Amount to capture
     * @param string $currency Currency code
     * @param array $paymentInstrument Payment instrument details
     */
    public function __construct(int $amount, string $currency, array $paymentInstrument)
    {
        $this->amount = $amount;
        $this->currency = $currency;
        $this->paymentInstrument = $paymentInstrument;
    }

    /**
     * Set the amount to capture
     * 
     * @param int $amount Amount to capture
     * @return self
     */
    public function setAmount(int $amount): self
    {
        $this->amount = $amount;
        return $this;
    }

    /**
     * Set the currency code
     * 
     * @param string $currency Currency code
     * @return self
     */
    public function setCurrency(string $currency): self
    {
        $this->currency = $currency;
        return $this;
    }

    /**
     * Set the payment instrument details
     * 
     * @param array $paymentInstrument Payment instrument details
     * @return self
     */
    public function setPaymentInstrument(array $paymentInstrument): self
    {
        $this->paymentInstrument = $paymentInstrument;
        return $this;
    }

    /**
     * Set card payment details
     * 
     * @param string $holder Card holder name
     * @param string $number Card number
     * @param string $cvc Card CVC code
     * @param int $expireMonth Card expiration month
     * @param int $expireYear Card expiration year
     * @return self
     */
    public function setCardPayment(CreditCard $creditCard): self
    {
        $this->paymentInstrument = $creditCard->toArray();
        return $this;
    }

    /**
     * Convert capture to array
     * 
     * @return array
     */
    public function toArray(): array
    {
        return [
            'capture' => [
                'amount' => $this->amount,
                'currency' => $this->currency,
                'payment_instrument' => $this->paymentInstrument
            ]
        ];
    }
}