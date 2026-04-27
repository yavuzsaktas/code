<?php

namespace Eticsoft\PaythorClient\Resources;
 
use GuzzleHttp\Exception\GuzzleException;

use Eticsoft\PaythorClient\Models\App\Install;

class App extends Resource
{
    /**
     * List user's apps
     * 
     * @return array|null
     
     */
    public function listMy(): ?array
    {
        $response = $this->client->request('GET', 'app/list/my');
        return $this->client->decodeResponse($response);
    }

    /**
     * List all available apps
     * 
     * @return array|null
     
     */
    public function listAll(): ?array
    {
        $response = $this->client->request('GET', 'app/list/all');
        return $this->client->decodeResponse($response);
    }

    /**
     * Retrieve app details by ID
     * 
     * @param int $id App ID
     * @return array|null
     
     */
    public function retrieve(int $id): ?array
    {
        $response = $this->client->request('GET', "app/retrieve/{$id}");
        return $this->client->decodeResponse($response);
    }

    /**
     * Install an app
     * 
     * @param int $id App ID
     * @param Install $data
     * @return array|null
     
     */
    public function install(int $id, Install $data): ?array
    { 
        $response = $this->client->request('POST', "app/install/{$id}", $data->toArray());
        return $this->client->decodeResponse($response);
    }

    /**
     * Get API keys for an app
     * 
     * @param int $id App ID
     * @return array|null
     
     */
    public function getApiKeys(int $id): ?array
    {
        $response = $this->client->request('GET', "app/getapikeys/{$id}");
        return $this->client->decodeResponse($response);
    }

    /**
     * Uninstall an app
     * 
     * @param int $id App ID
     * @return array|null
     
     */
    public function uninstall(int $id): ?array
    {
        $response = $this->client->request('DELETE', "app/uninstall/{$id}");
        return $this->client->decodeResponse($response);
    }
} 