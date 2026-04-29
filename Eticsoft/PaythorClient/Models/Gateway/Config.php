<?php

namespace Eticsoft\PaythorClient\Models\Gateway;

class Config
{
    /**
     * @var array Gateway parameters
     */
    private array $params = [];
 
    /**
     * Set all parameters at once
     *
     * @param string $key Key
     * @param string $value Value
     * @return $this
     */
    public function setParams(string $key, string $value): self
    {
        $this->params[$key] = $value;
        return $this;
    }

    /**
     * Convert the model to an array for API request
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'params' => $this->params
        ];
    }
}