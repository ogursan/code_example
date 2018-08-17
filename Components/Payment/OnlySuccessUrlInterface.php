<?php

namespace Shopen\AppBundle\Components\Payment;

/**
 * Интерфейс платёжной системы, умеющей использовать только один адрес
 * для уведомления пользователя как об успешной оплате, так и о неудачной.
 *
 * Для такой системы нельзя задать раздельные successUrl и failUrl,
 * пользователь будет перенаправлен на один и тот же адрес, независимо от результата операции оплаты.
 *
 * @package Shopen\AppBundle\Components\Payment
 */
interface OnlySuccessUrlInterface
{

}
