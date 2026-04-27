<?php

namespace Eticsoft\PaythorClient\Models\Config;

class PaymentOptionsUpdate
{
    /**
     * @var array Payment options data
     */
    private array $paymentOptions = [];

    /**
     * Set installment options for a specific card type
     *
     * @param string $cardType Card type (default, axess, bonus, etc.)
     * @param array $installments Array of installment options
     * @return $this
     */
    public function setInstallments(string $cardType, array $installments): self
    {
        $this->paymentOptions['installments'][$cardType] = $installments;
        return $this;
    }

    /**
     * Add a single installment option to a card type
     *
     * @param string $cardType Card type (default, axess, bonus, etc.)
     * @param int $months Number of months
     * @param float $gatewayFeePercent Gateway fee percentage
     * @param float $buyerFeePercent Buyer fee percentage
     * @param string $gateway Gateway name
     * @return $this
     */
    public function addInstallment(
        string $cardType,
        int $months,
        float $gatewayFeePercent,
        float $buyerFeePercent,
        string $gateway
    ): self {
        $installment = [
            'months' => $months,
            'gateway_fee_percent' => $gatewayFeePercent,
            'buyer_fee_percent' => $buyerFeePercent,
            'gateway' => $gateway
        ];

        if (!isset($this->paymentOptions['installments'][$cardType])) {
            $this->paymentOptions['installments'][$cardType] = [];
        }

        $this->paymentOptions['installments'][$cardType][] = $installment;
        return $this;
    } 

    /**
     * Set all payment options at once
     *
     * @param array $paymentOptions Complete payment options array
     * @return $this
     */
    public function setPaymentOptions(array $paymentOptions): self
    {
        $this->paymentOptions = $paymentOptions;
        return $this;
    }
 
    /**
     * Convert the model to an array for API request
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'payment_options' => $this->paymentOptions
        ];
    }
}