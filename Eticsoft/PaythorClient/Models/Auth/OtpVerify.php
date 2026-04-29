<?php

namespace Eticsoft\PaythorClient\Models\Auth;

class OtpVerify
{
    /**
     * The email or phone number to verify
     *
     * @var string
     */
    private string $target;

    /**
     * The OTP code to verify
     *
     * @var string
     */
    private string $otp;
 
    /**
     * Set the target email or phone number
     *
     * @param string $target
     * @return self
     */
    public function setTarget(string $target): self
    {
        $this->target = $target;
        return $this;
    }

    /**
     * Set the OTP code
     *
     * @param string $otp
     * @return self
     */
    public function setOtp(string $otp): self
    {
        $this->otp = $otp;
        return $this;
    }

    /**
     * Convert the model to an array for API requests
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'target' => $this->target,
            'otp' => $this->otp
        ];
    }
}