<?php

namespace Shopen\AppBundle\Components\Payment;


interface PaymentReportInterface extends PaymentSystemInterface
{
    /**
     * @param \DateTime $dateFrom
     * @param \DateTime $dateTo
     * @return PaymentTransaction[]
     * @deprecated
     */
    public function getTransactions(\DateTime $dateFrom, \DateTime $dateTo);

    /**
     * Возвращает алиас для процедуры БД
     *
     * @return string
     */
    public function getDbAlias();

    /**
     * Собирает транзакцию по набору данных от платежной системы
     *
     * @param PaymentDataBag $paymentDataBag
     * @return PaymentTransaction
     */
    public function buildTransaction(PaymentDataBag $paymentDataBag);
}
