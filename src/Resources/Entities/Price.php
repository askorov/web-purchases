<?php

namespace Wowmaking\WebPurchases\Resources\Entities;

use Wowmaking\WebPurchases\Enums\PeriodUnitEnum;
use Wowmaking\WebPurchases\Interfaces\ResourcesEntityInterface;

class Price implements ResourcesEntityInterface
{
    private const MONTHS_IN_YEAR = 12;
    private const DAYS_IN_WEEK = 7;

    public const TYPE_SUBSCRIPTION = 'subscription';
    public const TYPE_ONE_TIME = 'one_time';

    public $id;

    /** @var string */
    public $type;

    public $amount;

    public $currency;

    public $period;

    public $trial_period_days;

    public $trial_price_amount;

    public $product_name;

    /** @var bool */
    public $tax_exempt = false;

    public $currencies =[];

    /**
     * @return mixed
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * @param mixed $id
     */
    public function setId($id): void
    {
        $this->id = $id;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): void
    {
        $this->type = $type;
    }

    /**
     * @return mixed
     */
    public function getAmount()
    {
        return $this->amount;
    }

    /**
     * @param mixed $amount
     */
    public function setAmount($amount): void
    {
        $this->amount = $amount;
    }

    /**
     * @return mixed
     */
    public function getCurrency()
    {
        return $this->currency;
    }

    /**
     * @param mixed $currency
     */
    public function setCurrency($currency): void
    {
        $this->currency = $currency;
    }

    /**
     * @return mixed
     */
    public function getTrialPeriodDays()
    {
        return $this->trial_period_days;
    }

    /**
     * @param mixed $trial_period_days
     */
    public function setTrialPeriodDays($trial_period_days): void
    {
        $this->trial_period_days = $trial_period_days;
    }

    /**
     * @return mixed
     */
    public function getTrialPriceAmount()
    {
        return $this->trial_price_amount;
    }

    /**
     * @param mixed $trial_price_amount
     */
    public function setTrialPriceAmount($trial_price_amount): void
    {
        $this->trial_price_amount = $trial_price_amount;
    }

    public function isTaxExempt(): bool
    {
        return $this->tax_exempt;
    }

    public function setTaxExempt(bool $taxExempt): void
    {
        $this->tax_exempt = $taxExempt;
    }

    public function addCurrency($productData) {
        $this->currencies[] = $productData;
    }

    public function getCurrencies(){
        return $this->currencies;
    }



    /**
     * @param int $length
     * @param string $unit
     * @return void
     */
    public function setPeriod(int $length, string $unit): void
    {
        $unit = strtoupper($unit[0]);

        if (!in_array($unit, PeriodUnitEnum::list(), true)) {
            throw new \InvalidArgumentException(sprintf('"%s" is unknown interval unit.', $unit));
        }

        if ($unit === PeriodUnitEnum::MONTH && $length % self::MONTHS_IN_YEAR === 0) {
            $unit = PeriodUnitEnum::YEAR;
            $length /= self::MONTHS_IN_YEAR;
        }

        if ($unit === PeriodUnitEnum::DAY && $length % self::DAYS_IN_WEEK === 0) {
            $unit = PeriodUnitEnum::WEEK;
            $length /= self::DAYS_IN_WEEK;
        }

        $this->period = sprintf('P%d%s', $length, $unit);
    }

    public function getProductName(){
        return $this->product_name;
    }

    public function setProductName($productName){
        $this->product_name = $productName;
    }
}
