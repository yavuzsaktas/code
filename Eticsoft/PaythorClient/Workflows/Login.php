<?php

namespace Eticsoft\PaythorClient\Workflows;

use Eticsoft\PaythorClient\PaythorClient;

use Eticsoft\PaythorClient\Resources\App;
use Eticsoft\PaythorClient\Resources\Auth;

use Eticsoft\PaythorClient\Models\App\Install;
use Eticsoft\PaythorClient\Models\Auth\SignIn;
use Eticsoft\PaythorClient\Models\Auth\OtpVerify;
use Eticsoft\PaythorClient\Models\Auth\CheckAccessToken;

class Login
{
    public PaythorClient $client;

    public function __construct(PaythorClient $client)
    {
        $this->client = $client;
    }

    public function login(string $email, string $password)
    {
        $signIn = new SignIn();
        $signIn->setEmail($email);
        $signIn->setPassword($password);
        $signIn->setProgramId($this->client->getProgramId());
        $signIn->setAppId($this->client->getAppId());
        $signIn->setStoreUrl("https://store.eticsoft.com");
        $signIn->setStoreStage("development");
        $response = $this->client->auth()->signIn($signIn);

        if (!in_array($response['status_code'], [200, 201])) { 
            throw new \Exception('Login failed' . $response['message']);
        }

        print_r($response);

        $this->client->setToken($response['data']['token_string']);

        $auth = new Auth($this->client);
        $otpVerify = new OtpVerify();
        $otpVerify->setTarget($email);
        $otpVerify->setOtp('123456');
        $otpResponse = $auth->otpVerify($otpVerify);

        if (!in_array($otpResponse['status_code'], [200, 201])) {
            throw new \Exception('OTP verification failed' . $otpResponse['message']);
        }

        $app = new App($this->client);
        $myApp = $this->getMyApp($app);

        if (empty($myApp)) {
            $install = new Install();
            $install->setAppStage('development');
            $install->setParams([
                'app_id' => $this->client->getAppId(),
                'program_id' => $this->client->getProgramId(),
            ]);
            $myApp = $app->install($this->client->getAppId(), $install);
            $myApp = $this->getMyApp($app);
        }

        if (empty($myApp)) {
            throw new \Exception('App installation failed');
        }

        // $checkAccessToken = new CheckAccessToken();
        // $checkAccessToken->setAccessToken($this->client->getToken());
        // $checkAccessTokenResponse = $auth->checkAccessToken($checkAccessToken);
        // print_r($checkAccessTokenResponse);
        // exit;
        // if (!in_array($checkAccessTokenResponse['status_code'], [200, 201])) {
        //     throw new \Exception('Access token check failed' . $checkAccessTokenResponse['message']);
        // } 

        $apiKeys = $app->getApiKeys($myApp['id']);
        print_r($apiKeys);
        exit;

        if (!in_array($apiKeys['status_code'], [200, 201])) {
            throw new \Exception('API keys retrieval failed' . $apiKeys['message']);
        }

        $public_key = $apiKeys['data']['public_key'] ?? null;
        $secret_key = $apiKeys['data']['secret_key'] ?? null;
        $this->client->setApiKey($public_key);
        $this->client->setApiSecret($secret_key);

        print_r($apiKeys);
        exit;

        return $response;
    }

    private function getMyApp(App $app)
    {
        $apps = $app->listMy();
        if (!in_array($apps['status_code'], [200, 201])) {
            return [];
        }
        $data = current(array_filter($apps['data'], function ($app) {
            return $app['app_id'] == $this->client->getAppId();
        }));
        return $data;
    }
}
