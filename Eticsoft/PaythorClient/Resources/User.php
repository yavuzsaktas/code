<?php

namespace Eticsoft\PaythorClient\Resources;
 
use GuzzleHttp\Exception\GuzzleException;

use Eticsoft\PaythorClient\Models\User\UserList; 
use Eticsoft\PaythorClient\Models\User\Add;
use Eticsoft\PaythorClient\Models\User\Update;

class User extends Resource
{
    /**
     * List users with optional search parameters
     * 
     * @param UserList $data
     * @return array|null
     
     */
    public function list(UserList $data): ?array
    {
        $data = ['pagination' => $data->toArray()];
        if (!empty($search)) {
            $data['search'] = $search;
        }
        
        $response = $this->client->request('POST', 'user/list', $data);
        return $this->client->decodeResponse($response);
    }

    /**
     * Retrieve user details by ID
     * 
     * @param int $id User ID
     * @return array|null
     
     */
    public function retrieve(int $id): ?array
    {
        $response = $this->client->request('GET', "user/{$id}");
        return $this->client->decodeResponse($response);
    }

    /**
     * Add a new user
     * 
     * @param Add $data
     * @return array|null
     
     */
    public function add(Add $data): ?array
    {
        $response = $this->client->request('POST', 'user/add', $data->toArray());
        return $this->client->decodeResponse($response);
    }

    /**
     * Update user information
     * 
     * @param int $id User ID
     * @param Update $data
     * @return array|null
     
     */
    public function update(int $id, Update $data): ?array
    {
        $response = $this->client->request('POST', "user/update/{$id}", $data->toArray());
        return $this->client->decodeResponse($response);
    }

    /**
     * Suspend a user
     * 
     * @param int $id User ID
     * @return array|null
     
     */
    public function suspend(int $id): ?array
    {
        $response = $this->client->request('GET', "user/suspend/{$id}");
        return $this->client->decodeResponse($response);
    }

    /**
     * Activate a user
     * 
     * @param int $id User ID
     * @return array|null
     
     */
    public function activate(int $id): ?array
    {
        $response = $this->client->request('GET', "user/activate/{$id}");
        return $this->client->decodeResponse($response);
    }

    /**
     * Delete a user
     * 
     * @param string $id User ID or token
     * @return array|null
     
     */
    public function delete(string $id): ?array
    {
        $response = $this->client->request('DELETE', "user/delete/{$id}");
        return $this->client->decodeResponse($response);
    }
} 