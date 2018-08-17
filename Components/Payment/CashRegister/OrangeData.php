<?php

namespace Shopen\AppBundle\Components\Payment\CashRegister;

use Shopen\AppBundle\Components\Payment\Libs\OrangeDataClient;
use Shopen\AppBundle\Components\Payment\CashRegisterInterface;
use Shopen\AppBundle\Components\Payment\PaymentDataBag;
use Shopen\AppBundle\Components\Payment\PaidItem;

class OrangeData implements CashRegisterInterface
{
    /**
     * @var OrangeDataClient
     */
    private $client;

    /**
     * @var PaymentDataBag
     */
    private $paymentData;

    /**
     * @var string
     */
    private $error;

    /**
     * OrangeData constructor.
     *
     * @param OrangeDataClient $orangeDataClient
     */
    public function __construct(OrangeDataClient $orangeDataClient)
    {
        $this->client = $orangeDataClient;
        $this->error = null;
    }

    /**
     * @param PaymentDataBag $paymentData
     * @param PaidItem[] $paidItems
     * @param string $customerContact
     * @return $this|CashRegisterInterface
     * @throws \Exception
     */
    public function createBill(PaymentDataBag $paymentData, $paidItems, $customerContact)
    {
        $this->paymentData = $paymentData;

        /**
         * Приход.
         * Упрощенная доход, УСН доход.
         */
        $this->client->create_order($this->paymentData->getOrderId(), 1, $customerContact, 1);

        foreach ($paidItems as $item) {
            if ('DELIVERY' == strtoupper($item->getSku())) {
                /**
                 * НДС 18%, тип — услуга
                 */
                $tax = 1;
                $paymentSubjectType = 4;
            } else {
                /**
                 * НДС 18%, тип — товар
                 */
                $tax = 1;
                $paymentSubjectType = 1;
            }
            $price = floatval($item->getPrice()->getValue());
            $count = floatval($item->getCount());
            $name = mb_substr($item->getName(), 0, 64);
            /**
             * Тип оплаты «Полный расчёт»
             */
            $this->client->add_position_to_order($count, $price, $tax, $name, 4, $paymentSubjectType);
        }

        $amount = floatval($this->paymentData->getPrice()->getValue());
        /**
         * Сумма по чеку электронными, 1081
         */
        $this->client->add_payment_to_order(2, $amount);

        return $this;
    }

    /**
     * @return bool
     */
    public function printBill()
    {
        $this->error = null;
        try {
            $status = $this->client->send_order();
            if (true === $status) {
                return true;
            }
            $this->error = $status;
        } catch (\Exception $e) {
            $this->error = $e->getMessage();
        }

        return false;
    }

    /**
     * @return mixed
     */
    public function checkBill()
    {
        try {
            $status = $this->client->get_order_status($this->paymentData->getOrderId());
        } catch (\Exception $e) {
            return false;
        }
        return $status;
    }

    /**
     * @return string
     */
    public function getError() {
        return $this->error;
    }

}