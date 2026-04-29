<?php

namespace Eticsoft\PaythorClient\Models\Payment\Instrument;

class CreditCard
{
    private string $holder;
    private string $number;
    private string $cvc;
    private int $expireMonth;
    private int $expireYear;

    /**
     * @param string $holder Card holder name
     * @param string $number Card number
     * @param string $cvc Card CVC code
     * @param int $expireMonth Card expiration month
     * @param int $expireYear Card expiration year
     */
    public function __construct(
        string $holder = '',
        string $number = '',
        string $cvc = '',
        int $expireMonth = 0,
        int $expireYear = 0
    ) {
        $this->holder = $holder;
        $this->number = $number;
        $this->cvc = $cvc;
        $this->expireMonth = $expireMonth;
        $this->expireYear = $expireYear;
    }

    /**
     * Set the card holder name
     * 
     * @param string $holder Card holder name
     * @return self
     */
    public function setHolder(string $holder): self
    {
        $this->holder = $holder;
        return $this;
    }

    /**
     * Set the card number
     * 
     * @param string $number Card number
     * @return self
     */
    public function setNumber(string $number): self
    {
        $this->number = $number;
        return $this;
    }

    /**
     * Set the card CVC code
     * 
     * @param string $cvc Card CVC code
     * @return self
     */
    public function setCvc(string $cvc): self
    {
        $this->cvc = $cvc;
        return $this;
    }

    /**
     * Set the card expiration month
     * 
     * @param int $expireMonth Card expiration month
     * @return self
     */
    public function setExpireMonth(int $expireMonth): self
    {
        $this->expireMonth = $expireMonth;
        return $this;
    }

    /**
     * Set the card expiration year
     * 
     * @param int $expireYear Card expiration year
     * @return self
     */
    public function setExpireYear(int $expireYear): self
    {
        $this->expireYear = $expireYear;
        return $this;
    }

    /**
     * Convert credit card to array
     * 
     * @return array
     */
    public function toArray(): array
    {
        return [
            'card' => [
                'holder' => $this->holder,
                'number' => $this->number,
                'cvc' => $this->cvc,
                'expire_month' => $this->expireMonth,
                'expire_year' => $this->expireYear
            ]
        ];
    }
}