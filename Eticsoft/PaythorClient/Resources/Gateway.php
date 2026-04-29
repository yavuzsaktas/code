<?php

namespace Eticsoft\PaythorClient\Resources;
 
use GuzzleHttp\Exception\GuzzleException;

use Eticsoft\PaythorClient\Models\Gateway\Config;

class Gateway extends Resource
{
    /**
     * List the gateways installed for the current merchant.
     *
     * @return array|null Decoded JSON response or null on error.
     
     */
    public function listMyGateways(): ?array
    {
        $response = $this->client->request('GET', 'gateway/list/my');
        return $this->client->decodeResponse($response);
    }

    /**
     * List all available gateways in the system.
     *
     * @return array|null Decoded JSON response or null on error.
     
     */
    public function listAllGateways(): ?array
    {
        $response = $this->client->request('GET', 'gateway/list/all');
        return $this->client->decodeResponse($response);
    }

    /**
     * Get the configuration schema for a specific gateway.
     *
     * @param string $gatewayName The name of the gateway (e.g., "Stripe", "Paycell").
     * @return array|null Decoded JSON response or null on error.
     
     */
    public function getSchema(string $gatewayName): ?array
    {
        $response = $this->client->request('GET', "gateway/schema/{$gatewayName}");
        return $this->client->decodeResponse($response);
    }

    /**
     * Retrieve the current configuration for a specific installed gateway.
     *
     * @param string $gatewayName The name of the gateway.
     * @return array|null Decoded JSON response or null on error.
     
     */
    public function retrieve(string $gatewayName): ?array
    {
        $response = $this->client->request('GET', "gateway/retrieve/{$gatewayName}");
        return $this->client->decodeResponse($response);
    }

    /**
     * Update the configuration for an installed gateway.
     *
     * @param string $gatewayName The name of the gateway to update.
     * @param Config $data
     * @return array|null Decoded JSON response or null on error.
     
     */
    public function update(string $gatewayName, Config $data): ?array
    {
        $response = $this->client->request('POST', "gateway/update/{$gatewayName}", $data->toArray());
        return $this->client->decodeResponse($response);
    }

    /**
     * Install a new gateway for the merchant.
     *
     * @param string $gatewayName The name of the gateway to install.
     * @param Config $data
     * @return array|null Decoded JSON response or null on error.
     
     */
    public function install(string $gatewayName, Config $data): ?array
    {
        $response = $this->client->request('POST', "gateway/install/{$gatewayName}", $data->toArray());
        return $this->client->decodeResponse($response);
    }

    /**
     * Uninstall a gateway for the merchant.
     *
     * @param string $gatewayName The name of the gateway to uninstall.
     * @return array|null Decoded JSON response or null on error.
     
     */
    public function uninstall(string $gatewayName): ?array
    {
        $response = $this->client->request('DELETE', "gateway/uninstall/{$gatewayName}");
        return $this->client->decodeResponse($response);
    }

    /**
     * Handle gateway callback (Note: Typically called by the gateway, not the client).
     * Included for completeness based on Postman collection.
     *
     * @param string $gatewayName The name of the gateway sending the callback.
     * @param array $data The callback data (usually POSTed by the gateway).
     * @return array|null Decoded JSON response or null on error.
     
     */
    public function callback(string $gatewayName, array $data): ?array
    {
        // Determine if data should be sent as JSON or form params based on how gateways send callbacks
        // Assuming JSON for now, adjust if needed. Postman shows raw JSON body.
        $response = $this->client->request('POST', "gateway/callback/{$gatewayName}", $data);
        return $this->client->decodeResponse($response);
    }
}
