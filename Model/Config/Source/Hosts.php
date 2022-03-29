<?php
/**
 * Created by PhpStorm.
 * User: Meetanshi
 * Date: 01-09-2021
 * Time: 05:35 PM
 */

namespace Meetanshi\SMTP\Model\Config\Source;

/**
 * Class Hosts
 * @package Meetanshi\SMTP\Model\Config\Source
 */
class Hosts
{
    /**
     * @return array
     */
    public function toOptionArray()
    {
        $options = [
            [
                'value' => 'Gmail',
                'label' => __('Gmail')
            ],
            [
                'value' => 'amazon_ses',
                'label' => __('Amazon Ses')
            ],
            [
                'value' => 'mandrill',
                'label' => __('Mandrill')
            ]
        ];

        return $options;
    }
}