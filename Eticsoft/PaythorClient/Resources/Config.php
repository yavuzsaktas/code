<?php

namespace Eticsoft\PaythorClient\Resources;
 
use GuzzleHttp\Exception\GuzzleException;

use Eticsoft\PaythorClient\Models\Config\PaymentOptionsUpdate;

class Config extends Resource
{
    /**
     * Get payment options configuration.
     *
     * @return array|null Decoded JSON response or null on error.
     
     */
    public function getPaymentOptions(): ?array
    {
        $response = $this->client->request('GET', 'config/payment/options');
        return $this->client->decodeResponse($response);
    }

    /**
     * Update payment options configuration.
     *
     * @param PaymentOptionsUpdate $data
     * @return array|null Decoded JSON response or null on error.
     
     */
    public function updatePaymentOptions(PaymentOptionsUpdate $data): ?array
    {
        $response = $this->client->request('POST', 'config/payment/options/update', $data->toArray());
        return $this->client->decodeResponse($response);
    }
}
