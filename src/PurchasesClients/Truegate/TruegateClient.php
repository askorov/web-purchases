<?php

namespace Wowmaking\WebPurchases\PurchasesClients\Truegate;

use InvalidArgumentException;
use LogicException;
use Throwable;
use Wowmaking\WebPurchases\Providers\TruegateProvider;
use Wowmaking\WebPurchases\PurchasesClients\PurchasesClient;
use Wowmaking\WebPurchases\PurchasesClients\WithoutCustomerSupportTrait;
use Wowmaking\WebPurchases\Resources\Entities\Price;
use Wowmaking\WebPurchases\Resources\Entities\PriceCurrency;
use Wowmaking\WebPurchases\Resources\Entities\Subscription;
use Wowmaking\WebPurchases\Services\CountryCodeConverterService;

/**
 * @method TruegateProvider getProvider()
 */
class TruegateClient extends PurchasesClient
{
    use WithoutCustomerSupportTrait;

    private const DEFAULT_COUNTRY_CODE = 'ALL';

    private const STATUS_ACTIVE = 'ACTIVE';
    private const STATUS_TRIAL = 'TRIAL';
    private const STATUS_GRACE_PERIOD = 'GRACE_PERIOD';
    private const STATUS_DUNNING_PERIOD = 'GRACE_PERIOD';
    private const STATUS_UNPAID = 'UNPAID';
    private const STATUS_CANCELLED = 'CANCELLED';
    private const STATUS_INCOMPLETE = 'INCOMPLETE';
    private const STATUS_INCOMPLETE_EXPIRED = 'INCOMPLETE_EXPIRED';

    private const INTERVAL_UNIT_DAYS_MAP = [
        'DAY' => 1,
        'WEEK' => 7,
        'MONTH' => 30,
        'YEAR' => 365,
    ];

    private string $projectId;

    private bool $isSandbox;

    public function __construct(string $projectId, string $secretKey, bool $isSandbox = false)
    {
        $this->projectId = $projectId;
        $this->isSandbox = $isSandbox;

        parent::__construct($secretKey);
    }

    public function getPrices(array $pricesIds = []): array
    {
        $params = ['projectId' => $this->projectId];
        $plans = $this->getProvider()->listPlans($params);

        $prices = [];

        foreach ($plans['items'] as $key => $plan) {
            try {
                if ($plan['state'] !== self::STATUS_ACTIVE) {
                    continue;
                }

                $price = new Price();
                $price->setId($plan['id']);
                $price->setType(Price::TYPE_SUBSCRIPTION);
                $price->setProductName($plan['name']);

                $defaultPriceCurrency = $this->findObjectByCountry(self::DEFAULT_COUNTRY_CODE, $plan['prices']);

                $price->setAmount($defaultPriceCurrency['amount']);
                $price->setCurrency($defaultPriceCurrency['currency']);

                $price->setPeriod((int) $plan['duration'], (string) $plan['durationUnit']);

                foreach ($plan['prices'] as $planPrice) {
                    if ($planPrice['country'] === self::DEFAULT_COUNTRY_CODE) {
                        continue;
                    }

                    $priceCurrency = new PriceCurrency();
                    $priceCurrency->setId((string) $planPrice['id']);
                    $priceCurrency->setAmount($planPrice['amount']);

                    $alpha3CountryCode = is_string($planPrice['country'])
                        ? CountryCodeConverterService::alpha2ToAlpha3($planPrice['country'])
                        : null;
                    $priceCurrency->setCountry($alpha3CountryCode);

                    $priceCurrency->setCurrency($planPrice['currency']);

                    if (isset($plan['trialPrices'])) {
                        $trialObject = $this->findObjectByCountry($planPrice['country'], $plan['trialPrices']);

                        if ($trialObject !== null) {
                            $priceCurrency->setTrialPriceAmount($trialObject['amount']);
                        }
                    }

                    $price->addCurrency($priceCurrency);
                }

                if (isset($plan['trial']) && $plan['trial']) {
                    $intervalUnit = $plan['trial']['durationUnit'];
                    $intervalCount = $plan['trial']['duration'];
                    $intervalDays = self::INTERVAL_UNIT_DAYS_MAP[$intervalUnit] ?? 0;
                    $price->setTrialPeriodDays($intervalDays * $intervalCount);

                    $defaultTrialPricesCurrency = $this->findObjectByCountry(
                        self::DEFAULT_COUNTRY_CODE,
                        $plan['trialPrices'],
                    );
                    $price->setTrialPriceAmount($defaultTrialPricesCurrency['amount']);
                }
                $prices[] = $price;
            } catch (Throwable $throwable) {
                print_r("\n {$throwable->getMessage()}");
                print_r("\n {$throwable->getTraceAsString()}");
            }
        }

        return $prices;
    }

    public function subscriptionCreationProcess(array $data)
    {
        $subscriptionId = $data['subscription_id'] ?? null;
        $externalUserId = $data['external_user_id'] ?? null;
        if (!$subscriptionId || !$externalUserId) {
            throw new InvalidArgumentException('Not all required parameters were passed.');
        }

        $subscriptionData = $this->getSubscription($subscriptionId);

        if ($subscriptionData['externalUserId'] !== $externalUserId) {
            throw new LogicException('Subscription assigned for another customer.');
        }
        $subscriptionData['email'] = $data['email'];
        $subscriptionData['customer_id'] = $data['customer_id'];
        return $subscriptionData;

    }

    public function getSubscriptions(string $customerId): array
    {
        $this->throwNoRealization(__METHOD__);
    }

    public function cancelSubscription(string $subscriptionId, bool $force = false): Subscription
    {
        $params = ['projectId' => $this->projectId, 'subscriptionId' => $subscriptionId, 'isHard' => $force];
        $data = $this->getProvider()->cancelSubscription($params, 'Cancel request.');
        return new Subscription();
    }

    public function buildSubscriptionResource($providerResponse): Subscription
    {
        $subscription = new Subscription();
        $subscription->setTransactionId($providerResponse['subscriptionId']);
        $subscription->setPlanName($providerResponse['subscriptionProductPlanId']);
        $subscription->setEmail($providerResponse['lastTransaction']['transactionDetails']['email'] ?? $providerResponse['email']??null);
        $subscription->setCurrency($providerResponse['lastTransaction']['currency']);
        $subscription->setAmount($providerResponse['lastTransaction']['amount']);
        $subscription->setCustomerId($providerResponse['customer_id']??null);
        $subscription->setCreatedAt($providerResponse['createdAt']);
        $subscription->setExpireAt($providerResponse['nextPaymentDate']);
        $subscription->setState($providerResponse['state']);
        $subscription->setIsActive(
            in_array(
                $providerResponse['state'],
                [self::STATUS_ACTIVE, self::STATUS_TRIAL, self::STATUS_DUNNING_PERIOD, self::STATUS_GRACE_PERIOD, self::STATUS_INCOMPLETE, self::STATUS_CANCELLED],
                true
            )
        );
        $subscription->setProvider(PurchasesClient::PAYMENT_SERVICE_TRUEGATE);
        $subscription->setProviderResponse($providerResponse);

        if ($providerResponse['state'] == self::STATUS_CANCELLED) {
            $subscription->setCanceledAt($providerResponse['updatedAt']);
        }

        if ($providerResponse['state'] == self::STATUS_TRIAL) {
            $subscription->setTrialStartAt($providerResponse['createdAt']);
            if (isset($providerResponse['nextPaymentDate'])) {
                $subscription->setTrialEndAt($providerResponse['nextPaymentDate']);
            }
        }
        return $subscription;
    }

    public function startSubscription(
        string $planId,
        string $idfm,
        string $email,
        string $merchantName,
        ?string $currency,
        ?string $subscriptionPlanCountry,
        ?string $customerIp,
        array $metadata = [],
    ) {
        $params = [
            'projectId' => $this->projectId,
            'subscriptionProductPlanId' => $planId,
            'externalUserId' => $idfm,
            'email' => $email,
            'customPaymentDescriptor' => $merchantName,
            'metadata' => $metadata,
        ];

        if ($currency) {
            $params['currency'] = $currency;
        }

        if (is_string($subscriptionPlanCountry)) {
            $params['subscriptionPlanCountry'] = CountryCodeConverterService::alpha3ToAlpha2($subscriptionPlanCountry);
        }

        if ($customerIp) {
            $params['customerIp'] = $customerIp;
        }

        return $this->getProvider()->startSubscription($params);
    }

    public function startOneTimePayment(
        string $amount,
        string $currency,
        string $idfm,
        string $email,
        string $merchantName,
        ?string $customerIp,
        array $metadata = []
    ) {
        $params = [
            'projectId' => $this->projectId,
            'externalUserId' => $idfm,
            'email' => $email,
            'currency' => $currency,
            'amount' => $amount,
            'customPaymentDescriptor' => $merchantName,
            'metadata' => $metadata
        ];

        if ($customerIp) {
            $params['customerIp'] = $customerIp;
        }

        return $this->getProvider()->startOneTimePayment($params);
    }

    public function oneTimePayment(
        string $transactionId,
        string $amount,
        string $currency,
        string $idfm,
        //string $subscriptionId,
        string $description,
        ?string $customerIp,
        array $metadata = []
    ) {
        $params = [
            'transactionId' => $transactionId,
            'projectId'=> $this->projectId,
            'externalUserId' => $idfm,
            //'subscriptionId' => $subscriptionId,
            'amount' => $amount,
            'currency' => $currency,
            'description' => $description,
            'metadata' => $metadata
        ];

        if ($customerIp) {
            $params['customerIp'] = $customerIp;
        }

        return $this->getProvider()->oneTimePayment($params);
    }

    public function oneTimePaymentWithExternalUserId(string $transactionId, string $amount, string $email, string $currency, string $externalUserId, string $description, array $metadata = [])
    {
        $params = [
            'transactionId' => $transactionId,
            'projectId'=> $this->projectId,
            'externalUserId' => $externalUserId,
            'email' => $email,
            'currency' => $currency,
            'amount' => $amount,
            'description' => $description,
            'metadata' => $metadata
        ];
        return $this->getProvider()->oneTimePaymentWithExternalUserId($params);
    }

    public function loadProvider(): void
    {
        $this->setProvider(new TruegateProvider($this->secretKey, $this->isSandbox));
    }

    public function getSubscription(string $subscriptionId)
    {
        $params = [
            'projectId' => $this->projectId,
            'subscriptionId' => $subscriptionId,
        ];
        return $this->getProvider()->getSubscription($params);
    }

    public function checkOrderStatus(string $transactionId): array
    {
        $params = [
            'projectId' => $this->projectId,
            'transactionId' => $transactionId,
        ];
        return $this->getProvider()->getTransactionDetails($params);
    }

    public function refund(string $orderId)
    {
        $params = [
            'projectId' => $this->projectId,
            'transactionId' => $orderId,
        ];
        return $this->getProvider()->refund($params);
    }

    private function getCustomerIdFromCustomId(string $customId): string
    {
        $customIdParts = explode('|', $customId);

        return $customIdParts[0];
    }

    protected function getPurchaseClientType(): string
    {
        return self::PAYMENT_SERVICE_PAYPAL;
    }

    public function reactivate(string $subscriptionId): bool
    {
        $this->throwNoRealization(__METHOD__);
    }

    public function changePlan(string $subscriptionId, string $planCode): bool
    {
        $this->throwNoRealization(__METHOD__);
    }

    public function truegateChangePlan(string $subscriptionId, string $planCode)
    {
        $params = [
            'projectId' => $this->projectId,
            'subscriptionId' => $subscriptionId,
            'subscriptionProductPlanId' => $planCode,
        ];

        return $this->getProvider()->changePlan($params);
    }

    public function getTransactions($startDate, $endDate, $page)
    {
        return $this->getProvider()->getTransactions($startDate, $endDate, $page);
    }

    public function getDisputes($params)
    {
        return $this->getProvider()->getDistutes($params);
    }

    public function getDisputeDetails($id)
    {
        return $this->getProvider()->getDistuteDetails($id);
    }

    public function getTransactionsBySubscriptionId($subscriptionId)
    {
        $params = [
            'projectId' => $this->projectId,
            'subscriptionId' => $subscriptionId,
        ];
        return $this->getProvider()->getTransactionsBySubscriptionId($params);
    }

    private function findObjectByCountry(string $country, array $rows): ?array
    {
        foreach ($rows as $row) {
            if ($row['country'] === $country) {
                return $row;
            }
        }

        return null;
    }
}
