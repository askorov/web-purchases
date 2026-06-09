<?php

namespace Wowmaking\WebPurchases\Factories;

use InvalidArgumentException;
use Wowmaking\WebPurchases\PurchasesClients\Paddle\PaddleClient;
use Wowmaking\WebPurchases\PurchasesClients\PayPal\PayPalClient;
use Wowmaking\WebPurchases\PurchasesClients\Truegate\TruegateClient;
use Wowmaking\WebPurchases\PurchasesClients\PurchasesClient;
use Wowmaking\WebPurchases\PurchasesClients\Recurly\RecurlyClient;
use Wowmaking\WebPurchases\PurchasesClients\Solidgate\SolidgateClient;
use Wowmaking\WebPurchases\PurchasesClients\Stripe\StripeClient;
use Wowmaking\WebPurchases\Services\Subtruck\SubtruckService;

class PurchasesClientFactory
{
    public function create(array $clientParams, array $subtruckParams = [])
    {
        $purchasesClient = $this->resolvePurchasesClient($clientParams);

        $purchasesClient->setSubtruck($this->resolveSubtruck($subtruckParams));

        return $purchasesClient;
    }


    private function resolvePurchasesClient(array $config)
    {
        if (!in_array(($config['client_type'] ?? null), PurchasesClient::getPurchasesClientsTypes())) {
            throw new InvalidArgumentException('Invalid purchases client type.');
        }

        switch ($config['client_type']) {
            case PurchasesClient::PAYMENT_SERVICE_STRIPE:

                if (!isset($config['secret_key'])) {
                    throw new InvalidArgumentException('Invalid purchases client secret.');
                }
                $client = new StripeClient($config['secret_key']);
                break;

            case PurchasesClient::PAYMENT_SERVICE_RECURLY:
                if (!isset($config['public_key'], $config['secret_key'])) {
                    throw new InvalidArgumentException('Required parameters for recurly client was not provided.');
                }
                $client = new RecurlyClient($config['public_key'], $config['secret_key'], $config['region'] ?? null);

                if (!empty($config['countries']) && is_array($config['countries'])) {
                    $client->setVatCountries($config['countries']);
                }
                break;

            case PurchasesClient::PAYMENT_SERVICE_PAYPAL:
                if (!isset($config['client_id'], $config['sandbox'], $config['secret_key'])) {
                    throw new InvalidArgumentException('Required parameters for paypal client was not provided.');
                }

                $client = new PayPalClient($config['client_id'], $config['secret_key'], $config['sandbox']);
                break;

            case PurchasesClient::PAYMENT_SERVICE_SOLIDGATE:
                if (!isset($config['merchant_id'], $config['webhook_merchant_id'], $config['secret_key'], $config['webhook_secret_key'])) {
                    throw new InvalidArgumentException('Required parameters for solidgate client was not provided.');
                }

                $client = new SolidgateClient(
                    $config['merchant_id'],
                    $config['secret_key'],
                    $config['webhook_merchant_id'],
                    $config['webhook_secret_key']
                );
                break;
            case PurchasesClient::PAYMENT_SERVICE_PADDLE:
                if (!isset($config['vendor_id'], $config['vendor_auth_code'], $config['sandbox'], $config['public_key'])) {
                    throw new InvalidArgumentException('Required parameters for paddle client was not provided.');
                }

                $client = new PaddleClient(
                    $config['vendor_id'],
                    $config['vendor_auth_code'],
                    $config['public_key'],
                    $config['sandbox']
                );
                break;
            case PurchasesClient::PAYMENT_SERVICE_TRUEGATE:
                if (!isset($config['project_id'], $config['sandbox'], $config['secret_key'])) {
                    throw new InvalidArgumentException('Required parameters for truegate client was not provided.');
                }
                $client = new TruegateClient($config['project_id'], $config['secret_key'], $config['sandbox']);
                break;


            default:
                throw new InvalidArgumentException('Purchases client initialization error.');
        }

        return $client;
    }

    private function resolveSubtruck(array $config): ?SubtruckService
    {
        if (!isset($config['token'], $config['idfm'])) {
            return null;
        }

        return SubtruckService::service($config['token'], $config['idfm']);
    }
}
