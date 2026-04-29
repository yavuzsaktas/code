<?php

namespace Eticsoft\PaythorClient\Models\App;

class Install
{
    /**
     * @var string The store URL
     */
    private array $params = [];

    /**
     * @var string The app stage (development/production)
     */
    private string $appStage;
 
    /**
     * Set the store URL
     *
     * @param string $storeUrl
     * @return $this
     */
    public function setParams(array $params): self
    {
        $this->params = $params;
        return $this;
    }
 
    /**
     * Set the app stage
     *
     * @param string $appStage
     * @return $this
     */
    public function setAppStage(string $appStage): self
    {
        $this->appStage = $appStage;
        return $this;
    }
  
    /**
     * Convert the object to an array
     *
     * @return array
     */
    public function toArray(): array
    {
        $data = [
            'install' => [ 
                'app_stage' => $this->appStage, 
            ]
        ];
        if (is_array($this->params) || $this->params instanceof \Traversable) {
            foreach ($this->params as $key => $value) {
                $data['install'][$key] = $value;
            }
        }
        return $data;
    }
}