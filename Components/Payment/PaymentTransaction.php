<?php

namespace Shopen\AppBundle\Components\Payment;


class PaymentTransaction
{
    /**
     * @var string
     */
    private $id;

    /**
     * @var string
     */
    private $orderId;

    /**
     * @var float
     */
    private $sum;

    /**
     * @var string $currency ISO код валюты платежа
     */
    private $currency;

    /**
     * @var int
     */
    private $type;

    /**
     * @var \DateTime
     */
    private $date;

    /**
     * @var float
     */
    private $tip;


    /**
     * PaymentTransaction constructor.
     */
    public function __construct()
    {
        $this->currency = null;
    }


    /**
     * @return string
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * @param string $id
     * @return $this
     */
    public function setId($id)
    {
        $this->id = $id;

        return $this;
    }

    /**
     * @return string
     */
    public function getOrderId()
    {
        return $this->orderId;
    }

    /**
     * @param string $orderId
     * @return $this
     */
    public function setOrderId($orderId)
    {
        $this->orderId = $orderId;

        return $this;
    }

    /**
     * @return float
     */
    public function getSum()
    {
        return $this->sum;
    }

    /**
     * @param float $sum
     * @return $this
     */
    public function setSum($sum)
    {
        $this->sum = $sum;

        return $this;
    }

    /**
     * @return string
     */
    public function getCurrency()
    {
        return $this->currency;
    }

    /**
     * @param string $currency
     * @return PaymentTransaction
     */
    public function setCurrency($currency)
    {
        $this->currency = $currency;
        return $this;
    }



    /**
     * @return int
     */
    public function getType()
    {
        return $this->type;
    }

    /**
     * @param int $type
     * @return $this
     */
    public function setType($type)
    {
        $this->type = $type;

        return $this;
    }

    /**
     * @return \DateTime
     */
    public function getDate()
    {
        return $this->date;
    }

    /**
     * @param \DateTime $date
     * @return $this
     */
    public function setDate($date)
    {
        $this->date = $date;

        return $this;
    }

    /**
     * @return float
     */
    public function getTip()
    {
        return $this->tip;
    }

    /**
     * @param float $tip
     * @return $this
     */
    public function setTip($tip)
    {
        $this->tip = $tip;

        return $this;
    }
}
