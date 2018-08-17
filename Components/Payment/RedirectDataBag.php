<?php

namespace Shopen\AppBundle\Components\Payment;


class RedirectDataBag
{
    const METHOD_GET = 'get';

    const METHOD_POST = 'post';

    /**
     * @var string
     */
    private $url;

    /**
     * @var array
     */
    private $params = [];

    /**
     * @var string
     */
    private $method = self::METHOD_GET;

    /**
     * @return string
     */
    public function getUrl()
    {
        return $this->url;
    }

    /**
     * @param string $url
     * @return $this
     */
    public function setUrl($url)
    {
        $this->url = $url;

        return $this;
    }

    /**
     * @return array
     */
    public function getParams()
    {
        return $this->params;
    }

    /**
     * @param array $params
     * @return $this
     */
    public function setParams($params)
    {
        $this->params = $params;

        return $this;
    }

    /**
     * @return string
     */
    public function getMethod()
    {
        return $this->method;
    }

    /**
     * @param string $method
     * @return $this
     */
    public function setMethod($method)
    {
        $this->method = $method;

        return $this;
    }
}
