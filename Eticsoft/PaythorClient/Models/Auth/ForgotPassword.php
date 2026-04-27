<?php

namespace Eticsoft\PaythorClient\Models\Auth;

class ForgotPassword
{
    /**
     * Forgot password parameters
     *
     * @var array
     */
    protected $forgotpassword = [
        'email' => '',
        'phone' => ''
    ];

    /**
     * Set email
     *
     * @param string $email
     * @return self
     */
    public function setEmail(string $email): self
    {
        $this->forgotpassword['email'] = $email;
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
        $this->forgotpassword['phone'] = $phone;
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
            'forgotpassword' => $this->forgotpassword
        ];
    }
}