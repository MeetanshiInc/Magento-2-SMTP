<?php

namespace Meetanshi\SMTP\Controller\Adminhtml\Smtp;

use Magento\Backend\App\Action;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Ui\Component\MassAction\Filter;
use Meetanshi\SMTP\Model\ResourceModel\Logs\CollectionFactory;

class MassResend extends Action
{
    /**
     * Authorization level of a basic admin session
     *
     * @see _isAllowed()
     */
    public const ADMIN_RESOURCE = 'Meetanshi_SMTP::log';
    /**
     * @var Filter
     */
    protected $filter;

    /**
     * @var CollectionFactory
     */
    protected $emailLog;

    /**
     * @var LogFactory
     */
    protected $logFactory;

    /**
     * MassResend constructor.
     * @param Filter $filter
     * @param Action\Context $context
     * @param CollectionFactory $emailLog
     */
    public function __construct(
        Filter $filter,
        Action\Context $context,
        CollectionFactory $emailLog
    ) {
        $this->filter = $filter;
        $this->emailLog = $emailLog;

        parent::__construct($context);
    }

    /**
     * @return $this|ResponseInterface|ResultInterface
     * @throws LocalizedException
     */
    public function execute()
    {
        $collection = $this->filter->getCollection($this->emailLog->create());
        if ($collection->getNewEmptyItem()->isDeveloperModeOn()) {
            $this->messageManager->addErrorMessage(__('Developer Mode is enabled, so emails are not sent. Set Developer Mode to No to resend emails.'));
            return $this->resultFactory->create(ResultFactory::TYPE_REDIRECT)->setPath('mt_smtp/smtp/log');
        }
        $resend = 0;

        foreach ($collection->getItems() as $item) {
            if ($item->resendEmail()) {
                $resend++;
            } else {
                $this->messageManager->addErrorMessage(
                    __('We can\'t process your request for email log #%1', $item->getId())
                );
            }
        }

        if ($resend) {
            $this->messageManager->addSuccessMessage(
                __('A total of %1 mail(s) have been sent.', $resend)
            );
        }

        $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);

        return $resultRedirect->setPath('mt_smtp/smtp/log');
    }
}
