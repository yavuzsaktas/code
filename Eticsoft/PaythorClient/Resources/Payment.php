<?php

namespace Eticsoft\PaythorClient\Resources;
 
use GuzzleHttp\Exception\GuzzleException;

use Eticsoft\PaythorClient\Models\Payment\Create;
use Eticsoft\PaythorClient\Models\Payment\Payer;
use Eticsoft\PaythorClient\Models\Payment\Order;
use Eticsoft\PaythorClient\Models\Payment\Capture;
use Eticsoft\PaythorClient\Models\Payment\Refund;
use Eticsoft\PaythorClient\Models\Payment\PaymentList;

class Payment extends Resource
{
    /**
     * Create a new payment
     * 
     * @param Create $paymentData Payment data including amount, currency, method, etc.
     * @param Payer $payerData Payer information
     * @param Order $orderData Order details including cart items
     * @return array|null
     
     */
    public function create(Create $paymentData, Payer $payerData, Order $orderData): ?array
    {
        $data = [
            'payment' => $paymentData->toArray(),
            'payer' => $payerData->toArray(),
            'order' => $orderData->toArray()
        ];
 
        $response = $this->client->request('POST', 'payment/create', $data); 
        return $this->client->decodeResponse($response);
    }

    /**
     * Capture a payment
     * 
     * @param string $token Payment token
     * @param Capture $captureData Capture data including amount, currency, payment instrument
     * @return array|null
     
     */
    public function capture(string $token, Capture $captureData): ?array
    {
        $response = $this->client->request('POST', "payment/capture/{$token}", ['capture' => $captureData->toArray()]);
        return $this->client->decodeResponse($response);
    }

    /**
     * Refund a payment
     * 
     * @param string $token Payment token
     * @param Refund $refundData Refund data
     * @return array|null
     
     */
    public function refund(string $token, Refund $refundData): ?array
    {
        $response = $this->client->request('POST', "payment/refund/{$token}", ['refund' => $refundData->toArray()]);
        return $this->client->decodeResponse($response);
    }

    /**
     * Cancel a payment
     * 
     * @param string $token Payment token
     * @return array|null
     
     */
    public function cancel(string $token): ?array
    {
        $response = $this->client->request('POST', "payment/cancel/{$token}");
        return $this->client->decodeResponse($response);
    }

    /**
     * Get custom payment method
     * 
     * @param string $gateway Gateway name
     * @param string $token Payment token
     * @param array $data Method data
     * @return array|null
     
     */
    public function getCustomMethod(string $gateway, string $token, array $data): ?array
    {
        $response = $this->client->request('POST', "payment/getCustomMethod/{$gateway}/{$token}", $data);
        return $this->client->decodeResponse($response);
    }

    /**
     * List payments with optional search parameters
     * 
     * @param array $pagination Pagination parameters
     * @param array $search Optional search parameters
     * @return array|null
     
     */
    public function list(PaymentList $paymentList): ?array
    {
        $data = $paymentList->toArray();
        $response = $this->client->request('POST', 'payment/list', $data);
        return $this->client->decodeResponse($response);
    }

    /**
     * Retrieve payment details by ID
     * 
     * @param int $id Payment ID
     * @return array|null
     
     */
    public function retrieve(int $id): ?array
    {
        $response = $this->client->request('GET', "payment/retrieve/{$id}");
        return $this->client->decodeResponse($response);
    }

    /**
     * Get payment by token
     * 
     * @param string $token Payment token
     * @return array|null
     
     */
    public function getByToken(string $token): ?array
    {
        $response = $this->client->request('GET', "process/getbytoken/{$token}");
        return $this->client->decodeResponse($response);
    }
} 