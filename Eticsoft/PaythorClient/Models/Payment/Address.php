<?php

namespace Eticsoft\PaythorClient\Models\Payment;

class Address
{
    /**
     * @var string Address line 1
     */
    private string $line_1 = ''; 

    /**
     * @var string|null Address line 2
     */
    private ?string $line_2 = null;

    /**
     * @var string City
     */
    private string $city = '';

    /**
     * @var string State/Province
     */
    private string $state = '';

    /**
     * @var string Postal code
     */
    private string $postal_code = '';

    /**
     * @var string Country code
     */
    private string $country = '';
 
    /**
     * Set address line 1
     *
     * @param string $line1 Address line 1
     * @return $this
     */
    public function setLine1(string $line1): self
    {
        $this->line_1 = $line1;
        return $this;
    }

    /**
     * Set address line 2
     *
     * @param string|null $line2 Address line 2
     * @return $this
     */
    public function setLine2(?string $line2): self
    {
        $this->line_2 = $line2;
        return $this;
    }

    /**
     * Set city
     *
     * @param string $city City
     * @return $this
     */
    public function setCity(string $city): self
    {
        $this->city = $city;
        return $this;
    }

    /**
     * Set state/province
     *
     * @param string $state State/Province
     * @return $this
     */
    public function setState(string $state): self
    {
        $this->state = $state;
        return $this;
    }

    /**
     * Set postal code
     *
     * @param string $postalCode Postal code
     * @return $this
     */
    public function setPostalCode(string $postalCode): self
    {
        $this->postal_code = $postalCode;
        return $this;
    }

    /**
     * Set country code
     *
     * @param string $country Country code
     * @return $this
     */
    public function setCountry(string $country): self
    {
        $this->country = $country;
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
            'line_1' => $this->line_1,
            'city' => $this->city,
            'state' => $this->state,
            'postal_code' => $this->postal_code,
            'country' => $this->country
        ];

        if ($this->line_2) {
            $data['line_2'] = $this->line_2;
        }

        return $data;
    }
}