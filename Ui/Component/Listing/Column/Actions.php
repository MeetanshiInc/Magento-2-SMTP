<?php

namespace Meetanshi\SMTP\Ui\Component\Listing\Column;

use Magento\Framework\App\ObjectManager;
use Magento\Framework\Escaper;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

class Actions extends Column
{
    /**
     * @var UrlInterface
     */
    private $urlBuilder;

    /**
     * @var Escaper
     */
    private $escaper;

    /**
     * Actions constructor.
     * @param ContextInterface $context
     * @param UiComponentFactory $uiComponentFactory
     * @param UrlInterface $urlBuilder
     * @param array $components
     * @param array $data
     * @param Escaper|null $escaper
     */
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        UrlInterface $urlBuilder,
        array $components = [],
        array $data = [],
        ?Escaper $escaper = null
    ) {
        parent::__construct($context, $uiComponentFactory, $components, $data);

        $this->urlBuilder = $urlBuilder;
        $this->escaper = $escaper ?: ObjectManager::getInstance()->get(Escaper::class);
    }

    /**
     * Prepare Data Source
     *
     * @param array $dataSource
     * @return array
     */
    public function prepareDataSource(array $dataSource)
    {
        if (isset($dataSource['data']['items'])) {
            foreach ($dataSource['data']['items'] as & $item) {

                if (isset($item['id'])) {
                    $subject = $item['subject'];

                    if (stripos($subject, '=?utf-8?b?') !== false) {
                        $output = str_ireplace('=?utf-8?B?', '', $subject);
                        $output = str_replace('==?=', '', $output);
                        $output = mb_convert_encoding($output, 'UTF-8', 'BASE64');
                    } else {
                        $output = $subject;
                    }
                    $item['subject'] = $output;

                    $item[$this->getData('name')] = [
                        'view' => [
                            'label' => __('View'),
                        ],
                        'resend' => [
                            'href' => $this->urlBuilder->getUrl('mt_smtp/smtp/email', ['id' => $item['id']]),
                            'label' => __('Resend'),
                            'confirm' => [
                                'title' => __('Resend Email'),
                                'message' => __('Are you sure you want to resend the email <strong>"%1"</strong>?', $this->escaper->escapeHtml($item['subject'])),
                            ],
                        ],
                        'delete' => [
                            'href' => $this->urlBuilder->getUrl('mt_smtp/smtp/delete', ['id' => $item['id']]),
                            'label' => __('Delete'),
                            'confirm' => [
                                'title' => __('Delete Log'),
                                'message' => __('Are you sure you want to delete this log?'),
                            ],
                        ],
                    ];
                }
            }
        }

        return $dataSource;
    }
}
