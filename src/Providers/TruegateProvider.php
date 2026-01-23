<?php

namespace Wowmaking\WebPurchases\Providers;

use GuzzleHttp\Client;

class TruegateProvider
{
    private const BASE_URL_SANDBOX = 'https://public-api.asapcashier.test.truegate.tech/api/v1/';
    private const BASE_URL_PROD = 'https://public-api.asapcashier.truegate.tech/api/v1/';

    /**
     * @var string
     */
    private $clientSecret;

    /**
     * @var Client
     */
    private $httpClient;

    public function __construct(string $clientSecret, bool $sandbox = false)
    {
        $this->clientSecret = $clientSecret;
        $this->httpClient = new Client(['base_uri' => $sandbox ? self::BASE_URL_SANDBOX : self::BASE_URL_PROD]);
    }

    public function listPlans(array $params): array
    {
        return $this->makeRequest('POST', 'subscriptions/subscription-product-plans', $params);
    }

    public function startSubscription(array $params)
    {
        $exceptFieldsForSign = [
            'metadata',
            'customPaymentDescriptor',
            'currency',
            'subscriptionPlanCountry',
            'customerIp',
        ];

        return $this->makeRequest('POST', 'subscriptions/start', $params, $exceptFieldsForSign);
    }

    public function startOneTimePayment(array $params) {
        return $this->makeRequest('POST', 'pay/start', $params, ['metadata', 'customPaymentDescriptor']);
    }

    public function oneTimePayment(array $params) {
        return $this->makeRequest('POST', 'one-time-payments/create', $params, ['metadata']);
    }

    public function oneTimePaymentWithExternalUserId(array $params) {
        return $this->makeRequest('POST', 'pay/recurrent', $params, ['metadata', 'description']);
    }

    public function cancelSubscription(array $params) {
        return $this->makeRequest('POST', 'subscriptions/cancel', $params);
    }

    public function getTransactionDetails(array $params) {
        return $this->makeRequest('POST', 'subscriptions/transactions/details', $params);
    }

    public function refund(array $params) {
        return $this->makeRequest('POST', 'pay/refund', $params);
    }

    public function changePlan(array $params)
    {
        return $this->makeRequest('POST', 'subscriptions/switch-plan', $params);
    }

    public function getSubscription(array $params) {
        return $this->makeRequest('POST', 'subscriptions/details', $params);
    }

    public function getTransactionsBySubscriptionId(array $params){
        return $this->makeRequest('POST', 'subscriptions/transactions/bySubscriptionId', $params);
    }

    private function makeRequest(string $method, string $path, array $body = [], array $exceptFildsForSign = [])
    {
        if($exceptFildsForSign) {
            $fieldsForSign = array_diff_key($body, array_flip($exceptFildsForSign));
        } else {
            $fieldsForSign = $body;
        }
        $body['signature'] = $this->signRequest($fieldsForSign);

        $body['metadata'] = empty($body['metadata'])
            ? new \stdClass()
            : (object) $body['metadata'];

        $response = $this->httpClient->request($method, $path, [
            'headers' =>
                [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ],
            'body' => json_encode($body)
        ]);


        return json_decode($response->getBody(), true);
    }

    private function signRequest(array $payload): string {
        $params = implode('&', array_map(function($k, $v) {
            if(is_bool($v)) {
                $v = $v ? 'true' : 'false';
            }
            return "$k=".urlencode($v);
        }, array_keys($payload), $payload));

        $signature = hash_hmac("sha256", $params, $this->clientSecret);
        return $signature;
    }
}
