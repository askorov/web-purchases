<?php

namespace Wowmaking\WebPurchases\PurchasesClients\Recurly;

use Recurly\Client;
use Recurly\Client as Provider;
use Recurly\Errors\NotFound;
use Recurly\Resources\Item;
use Recurly\Resources\Plan;
use Throwable;
use Wowmaking\WebPurchases\PurchasesClients\PurchasesClient;
use Wowmaking\WebPurchases\Resources\Entities\Customer;
use Wowmaking\WebPurchases\Resources\Entities\Price;
use Wowmaking\WebPurchases\Resources\Entities\PriceCurrency;
use Wowmaking\WebPurchases\Resources\Entities\Subscription;
use Wowmaking\WebPurchases\Services\CountryCodeConverterService;

class RecurlyClient extends PurchasesClient
{
    /**
     * @var string
     */
    protected $publicKey;

    protected $region;

    /**
     * ISO 3166-1 alpha-2 country codes for which VAT-inclusive prices should be generated.
     *
     * @var string[]
     */
    private array $vatCountries = [];

    public function __construct(string $publicKey, string $secretKey, ?string $region = null)
    {
        $this->publicKey = $publicKey;
        $this->region = $region;
        parent::__construct($secretKey);
    }

    /**
     * Set country codes (alpha-2) for which VAT-inclusive prices will be generated in getPrices().
     *
     * @param string[] $countries e.g. ['DE', 'PL', 'SE']
     */
    public function setVatCountries(array $countries): void
    {
        $this->vatCountries = array_map('strtoupper', $countries);
    }

    /**
     * @return string[]
     */
    public function getVatCountries(): array
    {
        return $this->vatCountries;
    }

    public function isSupportsCustomers(): bool
    {
        return true;
    }

    public function loadProvider()
    {
        if($this->region) {
            $provider = new Provider($this->getSecretKey(), null,['region'=> $this->region]);
        } else {
            $provider = new Provider($this->getSecretKey());
        }

        $this->setProvider($provider);
    }

    /**
     * @return Provider
     */
    public function getProvider(): Provider
    {
        return $this->provider;
    }

    /**
     * @param array $pricesIds
     * @return Price[]
     */
    public function getPrices(array $pricesIds = []): array
    {
        $prices = [];

        $this->collectPlanPrices($prices, $pricesIds);
        $this->collectItemPrices($prices, $pricesIds);

        if (!empty($this->vatCountries)) {
            foreach ($prices as $price) {
                $this->appendVatPriceCurrencies($price);
            }
        }

        return $prices;
    }

    private function collectPlanPrices(array &$prices, array $pricesIds): void
    {
        $response = $this->getProvider()->listPlans([
            'params' => [
                'state' => 'active'
            ]
        ]);

        /** @var Plan $plan */
        foreach ($response as $plan) {
            if (!isset($plan->getCurrencies()[0])) {
                continue;
            }

            if (count($pricesIds) && !in_array($plan->getCode(), $pricesIds)) {
                continue;
            }

            $price = new Price();
            $price->setId($plan->getCode());
            $price->setType(Price::TYPE_SUBSCRIPTION);
            $price->setAmount($plan->getCurrencies()[0]->getUnitAmount());
            $price->setCurrency($plan->getCurrencies()[0]->getCurrency());
            $price->setTrialPeriodDays($plan->getTrialLength());
            $price->setTrialPriceAmount($plan->getCurrencies()[0]->getSetupFee());
            $price->setPeriod($plan->getIntervalLength(), $plan->getIntervalUnit());

            $prices[] = $price;
        }
    }

    private function collectItemPrices(array &$prices, array $pricesIds): void
    {
        $response = $this->getProvider()->listItems([
            'params' => [
                'state' => 'active'
            ]
        ]);

        /** @var Item $item */
        foreach ($response as $item) {
            if (!isset($item->getCurrencies()[0])) {
                continue;
            }

            if (count($pricesIds) && !in_array($item->getCode(), $pricesIds)) {
                continue;
            }

            $price = new Price();
            $price->setId($item->getCode());
            $price->setType(Price::TYPE_ONE_TIME);
            $price->setAmount($item->getCurrencies()[0]->getUnitAmount());
            $price->setCurrency($item->getCurrencies()[0]->getCurrency());

            $prices[] = $price;
        }
    }

    /**
     * For each configured VAT country, call previewPurchase with the actual product
     * to get exact tax from Recurly. Stores results as PriceCurrency (alpha-3 codes).
     */
    private function appendVatPriceCurrencies(Price $price): void
    {
        $baseCurrency = $price->getCurrency();
        $baseAmount = (float) $price->getAmount();
        $trialAmount = (float) $price->getTrialPriceAmount();

        foreach ($this->vatCountries as $alpha2Code) {
            try {
                $purchaseBody = [
                    'currency' => $baseCurrency,
                    'account' => [
                        'code' => 'vat-preview-' . $price->getId() . '-' . strtolower($alpha2Code),
                        'billing_info' => [
                            'address' => [
                                'country' => $alpha2Code,
                            ],
                        ],
                    ],
                ];

                if ($price->getType() === Price::TYPE_SUBSCRIPTION) {
                    $purchaseBody['subscriptions'] = [
                        ['plan_code' => $price->getId()],
                    ];
                } else {
                    $purchaseBody['line_items'] = [
                        [
                            'item_code' => $price->getId(),
                            'type' => 'charge',
                            'quantity' => 1,
                        ],
                    ];
                }

                $preview = $this->getProvider()->previewPurchase($purchaseBody);
                $invoice = $preview->getChargeInvoice();

                if (!$invoice || $invoice->getTax() === null || $invoice->getTax() <= 0) {
                    continue;
                }

                $taxInfo = $invoice->getTaxInfo();
                $rate = $taxInfo ? $taxInfo->getRate() : null;

                if ($rate === null || $rate <= 0) {
                    $subtotal = $invoice->getSubtotal();
                    $rate = $subtotal > 0 ? $invoice->getTax() / $subtotal : 0;
                }

                if ($rate <= 0) {
                    continue;
                }

                $alpha3Code = CountryCodeConverterService::alpha2ToAlpha3($alpha2Code);

                $priceCurrency = new PriceCurrency();
                $priceCurrency->setId($price->getId() . '-' . strtolower($alpha2Code));
                $priceCurrency->setAmount(round($baseAmount * (1 + $rate), 2));
                $priceCurrency->setCurrency($baseCurrency);
                $priceCurrency->setCountry($alpha3Code);

                if ($trialAmount > 0) {
                    $priceCurrency->setTrialPriceAmount(round($trialAmount * (1 + $rate), 2));
                }

                $price->addCurrency($priceCurrency);
            } catch (Throwable $e) {
                // Product not taxable or country not configured — skip
            }
        }
    }

    /**
     * @param array $params
     * @return Customer[]
     * @throws \Exception
     */
    public function getCustomers(array $params): array
    {
        $response = $this->getProvider()->listAccounts([
            'params' => $params
        ]);

        $result = [];
        foreach ($response as $item) {
            if (!$item instanceof \Recurly\Resources\Account) {
                continue;
            }

            if ($item->getState() !== 'active') {
                continue;
            }

            $result[$item->getId()] = $this->buildCustomerResource($item);
        }

        return $result;
    }

    /**
     * @param string $customerId
     * @return Customer
     * @throws \Exception
     */
    public function getCustomer(string $customerId): Customer
    {
        $response = $this->getProvider()->getAccount($customerId);

        if (!$response instanceof \Recurly\Resources\Account) {
            throw new NotFound('Customer not found');
        }

        return $this->buildCustomerResource($response);
    }

    /**
     * @param array $data
     * @return Customer
     * @throws \Exception
     */
    public function createCustomer(array $data): Customer
    {
        $code = $data['code'] ?? md5($data['email']);

        $response = $this->getProvider()->createAccount([
            'email' => $data['email'],
            'code' => $code
        ]);

        return $this->buildCustomerResource($response);
    }

    /**
     * @param string $customerId
     * @param array $data
     * @return Customer
     * @throws \Exception
     */
    public function updateCustomer(string $customerId, array $data): Customer
    {
        $response = $this->getProvider()->updateAccount($customerId, $data);

        return $this->buildCustomerResource($response);
    }

    /**
     * @param string $customerId
     * @return array
     * @throws \Exception
     */
    public function getSubscriptions(string $customerId): array
    {
        $response = $this->getProvider()->listAccountSubscriptions('code-' . $customerId);

        $subscriptions = [];

        try {
            /** @var \Recurly\Resources\Subscription $item */
            foreach ($response as $item) {
                $subscriptions[] = $this->buildSubscriptionResource($item);
            }
        } catch (NotFound $e) {}

        return $subscriptions;
    }

    /**
     * @param array $data
     * @return \Recurly\Resources\Subscription
     */
    public function subscriptionCreationProcess(array $data): \Recurly\Resources\Subscription
    {
        if (isset($data['currency'])) {
            $currency = $data['currency'];
        } else {
            $currency = "USD";
        }
        $subscription = [
            'plan_code' => $data['price_id'],
            'account' => [
                'code' => $data['customer_id'],
                'email' => $data['email'],
                'billing_info' => [
                    'token_id' => $data['token_id']
                ]
            ],
            'currency' => $currency
        ];

        if (isset($data['three_d_secure_action_result_token_id'])) {
            $subscription['account']['billing_info']['three_d_secure_action_result_token_id'] = $data['three_d_secure_action_result_token_id'];
        }

        return $this->getProvider()->createSubscription($subscription);
    }

    /**
     * @param string $subscriptionId
     * @return Subscription
     * @throws \Exception
     */
    public function cancelSubscription(string $subscriptionId, bool $force = false): Subscription
    {
        $response = $this->getProvider()->cancelSubscription($subscriptionId);

        return $this->buildSubscriptionResource($response);
    }

    /**
     * @param $providerResponse
     * @return Customer
     * @throws \Exception
     */
    public function buildCustomerResource($providerResponse): Customer
    {
        if (!$providerResponse instanceof \Recurly\Resources\Account) {
            throw new \Exception('Invalid data object for build customer resource, must be \Recurly\Resources\Account');
        }

        $customer = new Customer();
        $customer->setId($providerResponse->getId()); // recurly puts id in the id field! IT`S GREAT
        $customer->setEmail($providerResponse->getEmail());
        $customer->setIsActive($providerResponse->getState() === 'active');
        $customer->setProvider(PurchasesClient::PAYMENT_SERVICE_RECURLY);

        try {
            $customer->setProviderResponse(json_decode($providerResponse->getResponse()->getRawResponse()));
        } catch (\TypeError $e) {}

        return $customer;
    }

    public function buildSubscriptionResource($providerResponse): Subscription
    {
        if (!$providerResponse instanceof \Recurly\Resources\Subscription) {
            throw new \Exception('Invalid data object for build subscription resource, must be \Recurly\Resources\Subscription');
        }

        $subscription = new Subscription();
        $subscription->setTransactionId($providerResponse->getId());
        $subscription->setPlanName($providerResponse->getPlan()->getCode());
        $subscription->setEmail($providerResponse->getAccount()->getEmail());
        $subscription->setCurrency($providerResponse->getCurrency());
        $subscription->setAmount($providerResponse->getUnitAmount());
        $subscription->setCustomerId($providerResponse->getAccount()->getCode()); // recurly puts id in the code field! WTF????
        $subscription->setCreatedAt($providerResponse->getCreatedAt());
        $subscription->setTrialStartAt($providerResponse->getTrialStartedAt());
        $subscription->setTrialEndAt($providerResponse->getTrialEndsAt());
        $subscription->setExpireAt($providerResponse->getExpiresAt());
        $subscription->setCanceledAt($providerResponse->getCanceledAt());
        $subscription->setState($providerResponse->getState());
        $subscription->setIsActive(in_array($providerResponse->getState(), ['active', 'in_trial', 'canceled']));
        $subscription->setProvider(PurchasesClient::PAYMENT_SERVICE_RECURLY);

        try {
            $subscription->setProviderResponse(json_decode($providerResponse->getResponse()->getRawResponse(), true));
        } catch (\TypeError $e) {}

        return $subscription;
    }

    protected function getCredentialsId(): ?string
    {
        return $this->publicKey;
    }

    protected function getPurchaseClientType(): string
    {
        return self::PAYMENT_SERVICE_RECURLY;
    }

    public function getSubscription(string $subscriptionId)
    {
        return $this->provider->getSubscription($subscriptionId);
    }

    public function reactivate(string $subscriptionId): bool
    {
        $response = $this->provider->reactivateSubscription($subscriptionId);
        if($response->getState() == 'active'){
            return true;
        }
        return false;
    }

    public function changePlan(string $subscriptionId, string $planCode): bool
    {
        $change_create = [
            "plan_code" => $planCode,
            "timeframe" => "bill_date"
        ];
        $change = $this->provider->createSubscriptionChange($subscriptionId, $change_create);
        return true;
    }
}
