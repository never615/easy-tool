<?php

namespace Mallto\Tool\Exception;



use Exception;
use Illuminate\Support\MessageBag;
use Symfony\Component\HttpKernel\Exception\HttpException;

class DataException extends HttpException
{

    protected $data;

    /**
     * DataException constructor.
     * @param null $message
     * @param null $data
     * @param Exception|null $previous
     * @param array $headers
     * @param int $code
     */
    public function __construct($message = null, $data = null, Exception $previous = null, $headers = [], $code = 0)
    {
        parent::__construct(422, $message, $previous, $headers, $code);
    }

    /**
     * 获取数据
     * @return MessageBag
     */
    public function getData()
    {
        return $this->data;
    }

    /**
     * 是否有数据
     * @return bool
     */
    public function hasData()
    {
        return !($this->data == "" || count($this->data) <= 0 || $this->data == null);
    }

}
