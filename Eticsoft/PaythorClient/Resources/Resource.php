<?php

namespace Eticsoft\PaythorClient\Resources;

use Eticsoft\PaythorClient\PaythorClient;

abstract class Resource
{
    protected PaythorClient $client; 

    public function __construct(PaythorClient $client )
    {
        $this->client = $client;
    }
}
