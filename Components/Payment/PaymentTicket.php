<?php
namespace Shopen\AppBundle\Components\Payment;


class PaymentTicket
{
    /**
     * @var string
     */
    private $id;

    /**
     * @var int
     */
    private $cartId;

    /**
     * @var float
     */
    private $sum;

    /**
     * @var int
     */
    private $contract;

    /**
     * @var string
     */
    private $paymentSystem;

    public function getId()
    {
        return $this->id;
    }

    public function setId($id)
    {
        $this->id = $id;
    }

    public function getSum()
    {
        return $this->sum;
    }

    public function setSum($sum)
    {
        $this->sum = $sum;
    }

    public function getCartId()
    {
        return $this->cartId;
    }

    public function setCartId($id)
    {
        $this->cartId = $id;
    }

    public function getPaymentSystem()
    {
        return $this->paymentSystem;
    }

    public function setPaymentSystem($paymentSystem)
    {
        $this->paymentSystem = $paymentSystem;
    }

    public function getContract()
    {
        return $this->contract;
    }

    public function setContract($contract)
    {
        $this->contract = $contract;
    }
}