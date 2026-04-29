<?php

namespace Eticsoft\PaythorClient\Models\Auth;

class SignIn
{
    /**
     * Authentication query parameters
     *
     * @var array
     */
    protected $auth_query = [
        'auth_method' => 'email_password',
        'email' => '',
        'password' => '',
        'program_id' => null,
        'app_id' => null,
    ];

    /**
     * Set email
     *
     * @param string $email
     * @return self
     */
    public function setEmail(string $email): self
    {
        $this->auth_query['email'] = $email;
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
        $this->auth_query['password'] = $password;
        return $this;
    }

    /**
     * Set program ID
     *
     * @param int $programId
     * @return self
     */
    public function setProgramId(int $programId): self
    {
        $this->auth_query['program_id'] = $programId;
        return $this;
    }

    /**
     * Set app ID
     *
     * @param int $appId
     * @return self
     */
    public function setAppId(int $appId): self
    {
        $this->auth_query['app_id'] = $appId;
        return $this;
    }

    /**
     * Set store URL
     *
     * @param string $storeUrl
     * @return self
     */
    public function setStoreUrl(string $storeUrl): self
    {
        $this->auth_query['store_url'] = $storeUrl;
        return $this;
    }

    /**
     * Set store stage
     *
     * @param string $storeStage
     * @return self
     */
    public function setStoreStage(string $storeStage): self
    {
        $this->auth_query['app_stage'] = $storeStage;
        return $this;
    }

    /**
     * Convert the model to an array for API requests
     *
     * @return array
     */
    public function toArray(): array
    {
        if (in_array($this->auth_query['app_id'], [102,103,104,105])) {
            if (empty($this->auth_query['app_stage']) || empty($this->auth_query['store_url'])) {
                throw new \Exception('App stage and store URL are required for this app ID');
            }
        }
        return [
            'auth_query' => $this->auth_query
        ];
    }
}
