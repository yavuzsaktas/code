<?php

namespace Eticsoft\PaythorClient\Models\Payment;

class Create
{
    /**
     * @var float Payment amount
     */
    private float $amount = 1;

    /**
     * @var string Payment currency
     */
    private string $currency = 'TRY';

    /**
     * @var float Buyer fee
     */
    private float $buyerFee = 1;

    /**
     * @var string Payment method
     */
    private string $method = 'creditcard';

    /**
     * @var string|int|null Merchant reference
     */
    private string|int|null $merchantReference;

    /**
     * @var string|null Payment description
     */
    private string|null $returnUrl;

    /**
     * Set payment amount
     *
     * @param string $amount Payment amount
     * @return $this
     */
    public function setAmount(string $amount): self
    {
        $this->amount = $amount;
        return $this;
    }

    /**
     * Set payment currency
     *
     * @param string $currency Payment currency
     * @return $this
     */
    public function setCurrency(string $currency): self
    {
        $this->currency = $currency;
        return $this;
    }

    /**
     * Set buyer fee
     *
     * @param string $buyerFee Buyer fee
     * @return $this
     */
    public function setBuyerFee(string $buyerFee): self
    {
        $this->buyerFee = $buyerFee;
        return $this;
    }

    /**
     * Set payment method
     *
     * @param string $method Payment method (creditcard)
     * @return $this
     */
    public function setMethod(string $method): self
    {
        if (!in_array($method, ['creditcard'])) {
            throw new \InvalidArgumentException('Invalid payment method');
        }
        $this->method = $method;
        return $this;
    }

    /**
     * Set merchant reference
     *
     * @param string $merchantReference Merchant reference
     * @return $this
     */
    public function setMerchantReference(string $merchantReference): self
    {
        $this->merchantReference = $merchantReference;
        return $this;
    }

    /**
     * Set return url
     *
     * @param string $returnUrl Return url
     * @return $this
     */
    public function setReturnUrl(string $returnUrl): self
    {
        $this->returnUrl = $returnUrl;
        return $this;
    }

    /**
     * Convert the model to an array for API request
     *
     * @return array
     */
    public function toArray(): array
    { 
        $data = [
            'amount' => $this->amount,
            'currency' => $this->currency,
            'buyerFee' => $this->buyerFee,
            'method' => $this->method,
            'merchant_reference' => isset($this->merchantReference) ? $this->merchantReference : random_int(1000000000000000, 9999999999999999),
        ];

        if (!empty($this->returnUrl)) {
            $data['return_url'] = $this->returnUrl;
        }

        return $data;
    }
}