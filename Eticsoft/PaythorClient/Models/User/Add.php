<?php

namespace Eticsoft\PaythorClient\Models\User;

class Add
{
    /**
     * User data
     *
     * @var array
     */
    protected $user = [
        'firstname' => '',
        'lastname' => '',
        'email' => '',
        'phone' => '',
        'password' => ''
    ];

    /**
     * Set first name
     *
     * @param string $firstname
     * @return self
     */
    public function setFirstname(string $firstname): self
    {
        $this->user['firstname'] = $firstname;
        return $this;
    }

    /**
     * Set last name
     *
     * @param string $lastname
     * @return self
     */
    public function setLastname(string $lastname): self
    {
        $this->user['lastname'] = $lastname;
        return $this;
    }

    /**
     * Set email
     *
     * @param string $email
     * @return self
     */
    public function setEmail(string $email): self
    {
        $this->user['email'] = $email;
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
        $this->user['phone'] = $phone;
        return $this;
    }

    /**
     * Set password
     *
     * @param string $password
     * @return self
     */
    public function setPassword(string $password): self
    {
        $this->user['password'] = $password;
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
            'user' => $this->user
        ];
    }
}