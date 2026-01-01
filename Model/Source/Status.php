<?php

namespace Meetanshi\SMTP\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;

class Status implements OptionSourceInterface
{
    const STATUS_SUCCESS = 1;
    const STATUS_ERROR = 0;

    /**
     * @return array
     */
    public function toOptionArray()
    {
        return [
            ['value' => self::STATUS_SUCCESS, 'label' => __('Success')],
            ['value' => self::STATUS_ERROR, 'label' => __('Fail')],
        ];
    }
}
