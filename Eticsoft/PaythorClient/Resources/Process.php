<?php

namespace Eticsoft\PaythorClient\Resources;
 
class Process extends Resource
{ 
    public function retrieve(string $token): ?array
    {
        $response = $this->client->request('GET', "process/getbytoken/{$token}");
        return $this->client->decodeResponse($response);
    } 

    public function getByToken(string $token): ?array
    {
        $response = $this->client->request('GET', "process/getbytoken/{$token}");
        return $this->client->decodeResponse($response);
    }
} 