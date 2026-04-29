<?php

namespace Eticsoft\PaythorClient\Models\Auth;

class ResetPassword
{
    /**
     * Reset password parameters
     *
     * @var array
     */
    protected $resetpassword = [
        'email' => '',
        'phone' => '',
        'otp_code' => '',
        'new_password' => ''
    ];

    /**
     * Set email
     *
     * @param string $email
     * @return self
     */
    public function setEmail(string $email): self
    {
        $this->resetpassword['email'] = $email;
        return $this;
    }

    /**
     * Set phone
     *
     * @param string $phone
     * @return self
     */
    public function setPhone(string $phone): self
    {
        $this->resetpassword['phone'] = $phone;
        return $this;
    }

    /**
     * Set OTP code
     *
     * @param string $otpCode
     * @return self
     */
    public function setOtpCode(string $otpCode): self
    {
        $this->resetpassword['otp_code'] = $otpCode;
        return $this;
    }

    /**
     * Set new password
     *
     * @param string $newPassword
     * @return self
     */
    public function setNewPassword(string $newPassword): self
    {
        $this->resetpassword['new_password'] = $newPassword;
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
            'resetpassword' => $this->resetpassword
        ];
    }
}