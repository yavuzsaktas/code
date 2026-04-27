<?php

namespace Eticsoft\PaythorClient\Models\Auth;

class Register
{
    /**
     * User information
     *
     * @var array
     */
    protected $user = [
        'firstname' => '',
        'lastname' => '',
        'phone' => '',
        'email' => '',
        'password' => ''
    ];

    /**
     * Merchant information
     *
     * @var array
     */
    protected $merchant = [
        'program_id' => null,
        'name' => '',
        'company' => '',
        'email' => '',
        'phone' => '',
        'web' => '',
        'country' => '',
        'lang' => ''
    ];

    /**
     * Set user's first name
     *
     * @param string $firstname
     * @return self
     */
    public function setUserFirstname(string $firstname): self
    {
        $this->user['firstname'] = $firstname;
        return $this;
    }

    /**
     * Set user's last name
     *
     * @param string $lastname
     * @return self
     */
    public function setUserLastname(string $lastname): self
    {
        $this->user['lastname'] = $lastname;
        return $this;
    }

    /**
     * Set user's phone number
     *
     * @param string $phone
     * @return self
     */
    public function setUserPhone(string $phone): self
    {
        $this->user['phone'] = $phone;
        return $this;
    }

    /**
     * Set user's email
     *
     * @param string $email
     * @return self
     */
    public function setUserEmail(string $email): self
    {
        $this->user['email'] = $email;
        return $this;
    }

    /**
     * Set user's password
     *
     * @param string $password
     * @return self
     */
    public function setUserPassword(string $password): self
    {
        $this->user['password'] = $password;
        return $this;
    }

    /**
     * Set merchant's program ID
     *
     * @param int $programId
     * @return self
     */
    public function setMerchantProgramId(int $programId): self
    {
        $this->merchant['program_id'] = $programId;
        return $this;
    }

    /**
     * Set merchant's name
     *
     * @param string $name
     * @return self
     */
    public function setMerchantName(string $name): self
    {
        $this->merchant['name'] = $name;
        return $this;
    }

    /**
     * Set merchant's company
     *
     * @param string $company
     * @return self
     */
    public function setMerchantCompany(string $company): self
    {
        $this->merchant['company'] = $company;
        return $this;
    }

    /**
     * Set merchant's email
     *
     * @param string $email
     * @return self
     */
    public function setMerchantEmail(string $email): self
    {
        $this->merchant['email'] = $email;
        return $this;
    }

    /**
     * Set merchant's phone
     *
     * @param string $phone
     * @return self
     */
    public function setMerchantPhone(string $phone): self
    {
        $this->merchant['phone'] = $phone;
        return $this;
    }

    /**
     * Set merchant's website
     *
     * @param string $web
     * @return self
     */
    public function setMerchantWeb(string $web): self
    {
        $this->merchant['web'] = $web;
        return $this;
    }

    /**
     * Set merchant's country
     *
     * @param string $country
     * @return self
     */
    public function setMerchantCountry(string $country): self
    {
        $this->merchant['country'] = $country;
        return $this;
    }

    /**
     * Set merchant's language
     *
     * @param string $lang
     * @return self
     */
    public function setMerchantLang(string $lang): self
    {
        $this->merchant['lang'] = $lang;
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
            'user' => $this->user,
            'merchant' => $this->merchant
        ];
    }
}