<?php

namespace Meetanshi\SMTP\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;

class Status implements OptionSourceInterface
{
    public const STATUS_SUCCESS = 1;
    public const STATUS_ERROR = 0;
    public const STATUS_DEVELOPER_MODE = 2;
    public const STATUS_BLOCKED = 3;

    /**
     * @return array
     */
    public function toOptionArray()
    {
        return [
            ['value' => self::STATUS_SUCCESS, 'label' => __('Success')],
            ['value' => self::STATUS_ERROR, 'label' => __('Fail')],
            ['value' => self::STATUS_DEVELOPER_MODE, 'label' => __('Not Sent (Developer Mode)')],
            ['value' => self::STATUS_BLOCKED, 'label' => __('Blocked')],
        ];
    }
}
