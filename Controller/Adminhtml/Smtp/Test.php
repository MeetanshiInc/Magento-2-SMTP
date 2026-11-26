<?php

namespace Meetanshi\SMTP\Controller\Adminhtml\Smtp;

use Exception;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Email\Model\Template\SenderResolver;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Area;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Store\Model\Store;
use Meetanshi\SMTP\Helper\Data as SmtpData;
use Meetanshi\SMTP\Mail\Rse\Mail;
use Psr\Log\LoggerInterface;

class Test extends Action implements HttpPostActionInterface
{
    /**
     * Authorization level of a basic admin session
     *
     * @see _isAllowed()
     */
    public const ADMIN_RESOURCE = 'Meetanshi_SMTP::smtp';

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var SmtpData
     */
    protected $smtpDataHelper;

    /**
     * @var Mail
     */
    protected $mailResource;

    /**
     * @var TransportBuilder
     */
    protected $_transportBuilder;

    /**
     * @var SenderResolver
     */
    protected $senderResolver;

    /**
     * Test constructor.
     * @param Context $context
     * @param LoggerInterface $logger
     * @param SmtpData $smtpDataHelper
     * @param Mail $mailResource
     * @param TransportBuilder $transportBuilder
     * @param SenderResolver $senderResolver
     */
    public function __construct(
        Context $context,
        LoggerInterface $logger,
        SmtpData $smtpDataHelper,
        Mail $mailResource,
        TransportBuilder $transportBuilder,
        SenderResolver $senderResolver
    ) {
        $this->logger = $logger;
        $this->smtpDataHelper = $smtpDataHelper;
        $this->mailResource = $mailResource;
        $this->_transportBuilder = $transportBuilder;
        $this->senderResolver = $senderResolver;

        parent::__construct($context);
    }

    /**
     * @return ResponseInterface|ResultInterface
     * @throws LocalizedException
     */
    public function execute()
    {
        $result = ['status' => false];

        $params = $this->getRequest()->getParams() + [
            'to' => '', 'host' => '', 'authentication' => '', 'username' => '', 'protocol' => '',
            'port' => '', 'password' => '', 'returnpath' => '',
        ];
        if ($params['to'] && $this->smtpDataHelper->isBlockedEmail($params['to'], Store::DEFAULT_STORE_ID)) {
            $result['content'] = __(
                'The address %1 is in Block List Emails, so the test email was not sent.',
                $params['to']
            );
        } elseif ($params['to'] && $this->mailResource->isDeveloperMode(Store::DEFAULT_STORE_ID)) {
            $result['content'] = __(
                'Developer Mode is enabled, so the test email was not sent. '
                . 'Set Developer Mode to No, save the configuration and try again.'
            );
        } elseif ($params['to'] && $params['password'] === '******' && !$this->isSavedServer($params)) {
            $result['content'] = __(
                'The host or username was changed, so the saved password cannot be used. '
                . 'Please enter the password again and click Test Now.'
            );
        } elseif ($params['to']) {
            $config = [
                'type' => 'smtp',
                'host' => $params['host'],
                'username' => $params['username'],
                'ignore_log' => true,
                'force_sent' => true,
            ];

            if ($params['authentication']) {
                $config['auth'] = $params['authentication'];
            }
            if ($params['protocol']) {
                $config['ssl'] = $params['protocol'];
            }
            if ($params['port']) {
                $config['port'] = $params['port'];
            }
            if ($params['password'] === '******') {
                $config['password'] = $this->smtpDataHelper->getPassword();
            } else {
                $config['password'] = $params['password'];
            }
            if ($params['returnpath']) {
                $config['return_path'] = $params['returnpath'];
            }
            $this->mailResource->setSmtpOptions(Store::DEFAULT_STORE_ID, $config);

            $from = $this->senderResolver->resolve(
                isset($params['from']) ? $params['from'] : $config['username'],
                $this->smtpDataHelper->getScopeId()
            );
            $this->_transportBuilder
                ->setTemplateIdentifier('mt_test_email_template')
                ->setTemplateOptions(['area' => Area::AREA_FRONTEND, 'store' => Store::DEFAULT_STORE_ID])
                ->setTemplateVars([])
                ->setFrom($from)
                ->addTo($params['to']);
            try {
                $this->_transportBuilder->getTransport()->sendMessage();

                $result = [
                    'status' => true,
                    'content' => __('Sent successfully! Please check your email box.'),
                ];
            } catch (Exception $e) {
                $result['content'] = $e->getMessage();
                $this->logger->critical($e);
            }
        } else {
            $result['content'] = __('Test Error');
        }

        return $this->getResponse()->representJson(SmtpData::jsonEncode($result));
    }

    /**
     * Check the host and username are the saved ones, so the saved password is only sent to the saved server
     *
     * @param array $params
     * @return bool
     */
    private function isSavedServer(array $params): bool
    {
        $savedHost = $this->smtpDataHelper->getSavedSmtpConfig('host');
        $savedUser = $this->smtpDataHelper->getSavedSmtpConfig('username');

        return $savedHost !== ''
            && strcasecmp(trim((string) $params['host']), $savedHost) === 0
            && trim((string) $params['username']) === $savedUser;
    }
}
