<?php

namespace Shopen\AppBundle\Components\Payment\Libs\Kkb;

require_once dirname(__FILE__) . DIRECTORY_SEPARATOR . 'kkb.utils.php';


/**
 * Adapter for external library
 *
 * Class KkbLib
 * @package Shopen\AppBundle\Components\Payment\Libs\Kkb
 */
class KkbLib
{
    const CURRENCY_KZT = 398;

    private $configFilePath;

    public function __construct(
        $merchantCertificateId,
        $merchantName,
        $privateKeyFn,
        $privateKeyPass,
        $xmlTemplateFn,
        $xmlCommandTemplateFn,
        $publicKeyFn,
        $merchantId
    ) {
        $configText = "MERCHANT_CERTIFICATE_ID = $merchantCertificateId;" . PHP_EOL
            . "MERCHANT_NAME = $merchantName;" . PHP_EOL
            . "PRIVATE_KEY_FN = $privateKeyFn;" . PHP_EOL
            . "PRIVATE_KEY_PASS = $privateKeyPass;" . PHP_EOL
            . "XML_TEMPLATE_FN = $xmlTemplateFn;" . PHP_EOL
            . "XML_COMMAND_TEMPLATE_FN = $xmlCommandTemplateFn;" . PHP_EOL
            . "PUBLIC_KEY_FN = $publicKeyFn;" . PHP_EOL
            . "MERCHANT_ID = $merchantId;";

        $this->configFilePath = @tempnam('/tmp/', 'kkb_');

        $file = fopen($this->configFilePath, 'w+');
        fwrite($file, $configText);
        fclose($file);
    }

    public function __destruct()
    {
        unlink($this->configFilePath);
    }

    /**
     * Return encoded data for sending to Epay system
     *
     * @param int $orderId
     * @param float $amount
     * @return string
     */
    public function processRequest($orderId, $amount)
    {
        return process_request($orderId, self::CURRENCY_KZT, $amount, $this->configFilePath);
    }

    /**
     * @param $response
     * @return array
     */
    public function processResponse($response)
    {
        return process_response($response, $this->configFilePath);
    }

    /**
     * @param $referenceId
     * @param $approvalCode
     * @param $headerId
     * @param $amount
     * @return string
     */
    public function processComplete($referenceId, $approvalCode, $headerId, $amount)
    {
        return process_complete($referenceId, $approvalCode, $headerId, self::CURRENCY_KZT, $amount, $this->configFilePath);
    }
}
