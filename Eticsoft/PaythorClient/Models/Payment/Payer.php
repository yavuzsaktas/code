<?php

namespace Eticsoft\PaythorClient\Models\Payment;

class Payer
{
    /**
     * @var string First name
     */
    private string $first_name = '';

    /**
     * @var string Last name
     */
    private string $last_name = '';

    /**
     * @var string Email address
     */
    private string $email = '';

    /**
     * @var string Phone number
     */
    private string $phone = '';

    /**
     * @var Address Payer address
     */
    private Address $address;

    /**
     * @var string|null IP address of the payer (required by API when method is creditcard)
     */
    private ?string $ip = null;

    /**
     * Set first name
     *
     * @param string $firstName First name
     * @return $this
     */
    public function setFirstName(string $firstName): self
    {
        $this->first_name = $firstName;
        return $this;
    }

    /**
     * Set last name
     *
     * @param string $lastName Last name
     * @return $this
     */
    public function setLastName(string $lastName): self
    {
        $this->last_name = $lastName;
        return $this;
    }

    /**
     * Set email address
     *
     * @param string $email Email address
     * @return $this
     */
    public function setEmail(string $email): self
    {
        $this->email = $email;
        return $this;
    }

    /**
     * Set phone number
     *
     * @param string $phone Phone number
     * @return $this
     */
    public function setPhone(string $phone): self
    {
        $this->phone = $phone;
        return $this;
    }

    /**
     * Set address
     *
     * @param Address $address Payer address
     * @return $this
     */
    public function setAddress(Address $address): self
    {
        $this->address = $address;
        return $this;
    }

    /**
     * Set IP address
     *
     * @param string $ip IP address
     * @return $this
     */
    public function setIp(string $ip): self
    {
        $this->ip = $ip;
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
            'first_name' => $this->first_name,
            'last_name'  => $this->last_name,
            'email'      => $this->email,
            'phone'      => $this->phone,
            'address'    => $this->address->toArray(),
        ];

        if ($this->ip !== null && trim($this->ip) !== '') {
            $data['ip'] = $this->ip;
        }

        return $data;
    }
}