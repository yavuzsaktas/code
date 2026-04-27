<?php

namespace Eticsoft\PaythorClient;

use Exception;

class PaythorClient
{
    protected string $baseUrl = 'https://live-api.sanalpospro.com/';
    protected ?string $token = null;
    protected ?int $programId = 3;
    protected ?int $appId = 99;
    protected ?int $status = 1;
    protected array $keys = [
        'public' => null,
        'private' => null,
    ];
    protected ?string $hash_time = null;
    protected ?string $hash_rand = null;

    /**
     * Constructor.
     * 
     * @param array $options Additional options for the client.
     */
    public function __construct(array $options = [])
    {
        $this->baseUrl = rtrim($this->baseUrl, '/');

        if (isset($options['base_url'])) {
            $this->baseUrl = rtrim($options['base_url'], '/');
        }
        if (isset($options['token'])) {
            $this->token = $options['token'];
        }
        if (isset($options['program_id'])) {
            $this->programId = $options['program_id'];
        }
        if (isset($options['app_id'])) {
            $this->appId = $options['app_id'];
        }
        if (isset($options['public_key'])) {
            $this->keys['public'] = $options['public_key'];
        }
        if (isset($options['private_key'])) {
            $this->keys['private'] = $options['private_key'];
        }
    }

    public function setStatus(int $status): void
    {
        $this->status = $status;
    }

    /**
     * Set the authentication token.
     *
     * @param string $token
     * @return void
     */
    public function setToken(string $token): void
    {
        $this->token = $token;
    }
    
    public function getToken(): string
    {
        return $this->token;
    }

    /**
     * Set the public key.
     * 
     * @param string $publicKey
     * @return void
     */
    public function setPublicKey(string $publicKey): void
    {
        $this->keys['public'] = $publicKey;
    }

    /**
     * Set the private key.
     * 
     * @param string $privateKey
     * @return void
     */
    public function setPrivateKey(string $privateKey): void
    {
        $this->keys['private'] = $privateKey;
    }
 
    /**
     * Set the Program ID.
     *
     * @param int $programId
     * @return void
     */
    public function setProgramId(int $programId): void
    {
        $this->programId = $programId;
    }

    /**
     * Get the Program ID.
     *
     * @return int
     */
    public function getProgramId(): int
    {
        return $this->programId;
    }

    /**
     * Set the App ID.
     *
     * @param int $appId
     * @return void
     */
    public function setAppId(int $appId): void
    {
        $this->appId = $appId;
    }

    /**
     * Get the App ID.
     *
     * @return int
     */
    public function getAppId(): int
    {
        return $this->appId;
    }

    /**
     * Make an API request.
     *
     * @param string $method HTTP method (GET, POST, PUT, DELETE, etc.).
     * @param string $url The URL endpoint.
     * @param array $data Request data.
     * @return string
     */
    public function request(string $method, string $url, array $data = []): string
    {
        $curl = curl_init();

        // Set URL
        $fullUrl = $this->baseUrl . '/' . ltrim($url, '/');
 
        // Prepare cURL options
        $curlOptions = [
            CURLOPT_URL => $fullUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
        ];

        // Set headers
        $headers = [
            'ETC-PROGRAM-ID: ' . $this->programId,
            'ETC-APP-ID: ' . $this->appId,
            'Content-Type: application/json',
        ];

        if ($this->token) {
            $headers[] = 'Authorization: Bearer ' . $this->token;
        }

        if (isset($this->keys['public']) && isset($this->keys['private'])) {  
            $hash = $this->setHash($this->keys['public'], $this->keys['private']);
            $headers[] = 'X-Timestamp: ' . $this->hash_time;
            $headers[] = 'X-Nonce: ' . $this->hash_rand;
            $headers[] = 'Authorization: ApiKeys ' . $this->keys['public'] . ':' . $hash;
        } 
      
        $curlOptions[CURLOPT_HTTPHEADER] = $headers;

        // Handle request data
        if (!empty($data)) {
            if ($method === 'GET') {
                $fullUrl .= '?' . http_build_query($data);
                $curlOptions[CURLOPT_URL] = $fullUrl;
            } else {
                $curlOptions[CURLOPT_POSTFIELDS] = json_encode($data);
            }
        }

        // Set all cURL options
        curl_setopt_array($curl, $curlOptions);

        // Execute request first, then get the status code
        $response = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $this->setStatus($status); 

        // Check for cURL errors
        if (curl_errno($curl)) {
            $error = [
                'error' => 'cURL Error: ' . curl_error($curl),
                'response' => $response,
                'http_code' => curl_getinfo($curl, CURLINFO_HTTP_CODE)
            ];
            $response = json_encode($error);
        }

        // Close cURL handle
        curl_close($curl);

        return $response;
    }

    /**
     * Decode JSON response.
     *
     * @param string $response Raw response from API
     * @param int $httpCode HTTP status code
     * @return array
     */
    public function decodeResponse(string $response): array
    {
        if (empty($response)) {
            return [
                'error' => 'Empty response',
                'response' => $response, 
                'status' => $this->status,
            ];
        }
 
        $decoded = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'error' => 'JSON decode error: ' . json_last_error_msg(),
                'response' => $response, 
                'status' => $this->status,
            ];
        }
 
        return array_merge($decoded, [
            'status_code' => $this->status,
        ]);
    }


    public function setHash($publicKey, $secretKey): string
    {
        $this->hash_time = (string)microtime(true);
        $this->hash_rand = (string)rand(1000000, 9999999);
        $hash = hash('sha256', $publicKey . $secretKey . $this->hash_time . $this->hash_rand);
        return $hash;
    }


    // --- Resource Accessors ---

    /**
     * Access Auth related endpoints.
     * @return Resources\Auth
     */
    public function auth(): Resources\Auth
    {
        return new Resources\Auth($this);
    }

    /**
     * Access User related endpoints.
     * @return Resources\User
     */
    public function user(): Resources\User
    {
        return new Resources\User($this);
    }

    /**
     * Access Config related endpoints.
     * @return Resources\Config
     */
    public function config(): Resources\Config
    {
        return new Resources\Config($this);
    }

    /**
     * Access Gateway related endpoints.
     * @return Resources\Gateway
     */
    public function gateway(): Resources\Gateway
    {
        return new Resources\Gateway($this);
    }

    /**
     * Access App related endpoints.
     * @return Resources\App
     */
    public function app(): Resources\App
    {
        return new Resources\App($this);
    }

    /**
     * Access Payment related endpoints.
     * @return Resources\Payment
     */
    public function payment(): Resources\Payment
    {
        return new Resources\Payment($this);
    }

    /**
     * Access Process related endpoints.
     * @return Resources\Process
     */
    public function process(): Resources\Process
    {
        return new Resources\Process($this);
    }
}
