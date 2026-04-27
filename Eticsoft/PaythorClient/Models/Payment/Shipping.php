<?php

namespace Eticsoft\PaythorClient\Models\Payment;

class Shipping
{
    private string $firstName = '';
    private string $lastName = '';
    private string $phone = '';
    private string $email = '';
    private ?Address $address = null;

    /**
     * Set first name
     * 
     * @param string $firstName
     * @return $this
     */
    public function setFirstName(string $firstName): self
    {
        $this->firstName = $firstName;
        return $this;
    }

    /**
     * Set last name
     * 
     * @param string $lastName
     * @return $this
     */
    public function setLastName(string $lastName): self
    {
        $this->lastName = $lastName;
        return $this;
    }

    /**
     * Set phone
     * 
     * @param string $phone
     * @return $this
     */
    public function setPhone(string $phone): self
    {
        $this->phone = $phone;
        return $this;
    }

    /**
     * Set email
     * 
     * @param string $email
     * @return $this
     */
    public function setEmail(string $email): self
    {
        $this->email = $email;
        return $this;
    }

    /**
     * Set address
     * 
     * @param Address $address
     * @return $this
     */
    public function setAddress(Address $address): self
    {
        $this->address = $address;
        return $this;
    }

    /**
     * Convert shipping to array
     * 
     * @return array
     */
    public function toArray(): array
    {
        return [
            'firstName' => $this->firstName,
            'lastName' => $this->lastName,
            'phone' => $this->phone,
            'email' => $this->email,
            'address' => $this->address ? $this->address->toArray() : null
        ];
    }
} 