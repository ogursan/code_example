<?php

namespace Shopen\AppBundle\Components\Payment;

use Symfony\Component\HttpFoundation\Request;


/**
 * Class PaymentRequest
 * @package Shopen\AppBundle\Components\Payment
 */
class PaymentResponse
{
    const STATUS_INVALID_REQUEST = 1;

    const STATUS_ORDER_NOT_EXISTS = 2;

    const STATUS_LESS_SUM = 3;

    const STATUS_MORE_SUM = 4;

    const STATUS_ORDER_EXECUTION_ERROR = 5;

    const STATUS_ALREADY_PAYED = 6;

    const STATUS_NOT_SUCCESS = 7;

    /**
     * @var bool
     */
    protected $success = false;

    /**
     * @var int
     */
    protected $messageCode;

    /**
     * @var string
     */
    protected $message;

    /**
     * @var Request
     */
    protected $request;

    /**
     * @return bool
     */
    public function isSuccess()
    {
        return (bool)$this->success;
    }

    /**
     * @param $success
     * @return $this
     */
    public function setSuccess($success)
    {
        $this->success = (bool)$success;

        return $this;
    }

    /**
     * @return int
     */
    public function getMessageCode()
    {
        return $this->messageCode;
    }

    /**
     * @param $code
     * @return $this
     */
    public function setMessageCode($code)
    {
        $this->messageCode = $code;

        return $this;
    }

    /**
     * @return string
     */
    public function getMessage()
    {
        return $this->message;
    }

    /**
     * @param string $message
     * @return $this
     */
    public function setMessage($message)
    {
        $this->message = $message;

        return $this;
    }

    /**
     * @return Request
     */
    public function getRequest()
    {
        return $this->request;
    }

    /**
     * @param Request $request
     * @return $this
     */
    public function setRequest($request)
    {
        $this->request = $request;

        return $this;
    }
}
