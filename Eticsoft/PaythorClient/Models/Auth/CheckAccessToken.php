<?php

namespace Eticsoft\PaythorClient\Models\Auth;

class CheckAccessToken
{
    private string $accessToken;

    public function setAccessToken(string $accessToken): void
    {
        $this->accessToken = $accessToken;
    }

    public function toArray(): array
    {
        return [
            'accesstoken' => $this->accessToken
        ];
    }
}
