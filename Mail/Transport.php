<?php
/**
 * Copyright 2015 Adobe
 * All Rights Reserved.
 * Copyright 2025 Meetanshi
 * All Rights Reserved.
 */

namespace Meetanshi\SMTP\Mail;

use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Mail\TransportInterface;
use Magento\Framework\Mail\EmailMessageInterface;
use Magento\Framework\Exception\MailException;
use Magento\Framework\Phrase;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;
use Meetanshi\SMTP\Mail\Rse\Mail;
use Meetanshi\SMTP\Helper\Data;
use Meetanshi\SMTP\Model\LogsFactory;
use Magento\Framework\Registry;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Auth\LoginAuthenticator;
use Symfony\Component\Mailer\Transport\Smtp\Auth\PlainAuthenticator;
use Symfony\Component\Mailer\Transport\NativeTransportFactory;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\TransportInterface as SymfonyTransportInterface;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mime\Message as SymfonyMessage;
use Zend\Mail\Message as ZendMessage;
use Zend\Mail\Transport\TransportInterface as ZendTransportInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use ReflectionClass;

/**
 * Class responsible for sending emails with compatibility across Magento 2 versions
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class Transport implements TransportInterface
{
    /**
     * Configuration paths for SMTP settings
     */
    private const XML_PATH_SENDING_SET_RETURN_PATH = 'system/smtp/set_return_path';
    private const XML_PATH_SENDING_RETURN_PATH_EMAIL = 'system/smtp/return_path_email';
    private const XML_PATH_TRANSPORT = 'system/smtp/transport';
    private const XML_PATH_HOST = 'system/smtp/host';
    private const XML_PATH_PORT = 'system/smtp/port';
    private const XML_PATH_USERNAME = 'system/smtp/username';
    private const XML_PATH_PASSWORD = 'system/smtp/password';
    private const XML_PATH_AUTH = 'system/smtp/auth';
    private const XML_PATH_SSL = 'system/smtp/ssl';
    private const XML_PATH_MT_SMTP_STATUS = 'smtp/general/enabled';
    private const XML_PATH_MT_SMTP_HOST = 'smtp/configuration_option/host';
    private const XML_PATH_MT_SMTP_PORT = 'smtp/configuration_option/port';
    private const XML_PATH_MT_SMTP_PROTOCOL = 'smtp/configuration_option/protocol';
    private const XML_PATH_MT_SMTP_AUTH = 'smtp/configuration_option/authentication';
    private const XML_PATH_MT_SMTP_USERNAME = 'smtp/configuration_option/username';
    private const XML_PATH_MT_SMTP_PASSWORD = 'smtp/configuration_option/password';
    private const XML_PATH_MT_SMTP_RETURN_PATH_EMAIL = 'smtp/configuration_option/return_path_email';

    /**
     * @var int
     */
    private $isSetReturnPath;

    /**
     * @var string|null
     */
    private $returnPathValue;

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var EmailMessageInterface
     */
    private $message;

    /**
     * @var Mail
     */
    private $resourceMail;

    /**
     * @var Registry
     */
    private $registry;

    /**
     * @var Data
     */
    private $helper;

    /**
     * @var LogsFactory
     */
    private $logFactory;

    /**
     * @var int
     */
    private $storeId;

    /**
     * @var SymfonyTransportInterface|ZendTransportInterface
     */
    private $transport;

    /**
     * @var bool
     */
    private $isSymfonyMailer;

    /**
     * @var bool
     */
    private $isSmtpEnabled;

    /**
     * @var EncryptorInterface
     */
    private $encryptor;

    /**
     * Constructor
     *
     * @param EmailMessageInterface $message
     * @param ScopeConfigInterface $scopeConfig
     * @param Mail $resourceMail
     * @param Registry $registry
     * @param Data $helper
     * @param LogsFactory $logFactory
     * @param LoggerInterface $logger
     * @param ProductMetadataInterface|null $productMetadata
     */
    public function __construct(
        EmailMessageInterface $message,
        ScopeConfigInterface $scopeConfig,
        Mail $resourceMail,
        Registry $registry,
        Data $helper,
        LogsFactory $logFactory,
        LoggerInterface $logger,
        EncryptorInterface $encryptor,
        ?ProductMetadataInterface $productMetadata = null
    ) {
        $this->message = $message;
        $this->scopeConfig = $scopeConfig;
        $this->resourceMail = $resourceMail;
        $this->registry = $registry;
        $this->helper = $helper;
        $this->logFactory = $logFactory;
        $this->logger = $logger;
        $this->encryptor = $encryptor;
        $this->storeId = $this->registry->registry('mp_smtp_store_id') ?: 0;
        $this->isSymfonyMailer = $this->isSymfonyMailer($productMetadata);

        $this->isSmtpEnabled = (bool) $scopeConfig->getValue(
            self::XML_PATH_MT_SMTP_STATUS,
            ScopeInterface::SCOPE_STORE,
            $this->storeId
        );
        $this->isSetReturnPath = (int) $scopeConfig->getValue(
            self::XML_PATH_SENDING_SET_RETURN_PATH,
            ScopeInterface::SCOPE_STORE,
            $this->storeId
        );
        $this->returnPathValue = $scopeConfig->getValue(
            $this->isSmtpEnabled ? self::XML_PATH_MT_SMTP_RETURN_PATH_EMAIL : self::XML_PATH_SENDING_RETURN_PATH_EMAIL,
            ScopeInterface::SCOPE_STORE,
            $this->storeId
        );
    }

    /**
     * Determine if Symfony Mailer should be used based on Magento version
     *
     * @param ProductMetadataInterface|null $productMetadata
     * @return bool
     */
    private function isSymfonyMailer(?ProductMetadataInterface $productMetadata = null): bool
    {
        $productMetadata = $productMetadata ?: ObjectManager::getInstance()->get(ProductMetadataInterface::class);
        $version = $productMetadata->getVersion();
        return version_compare($version, '2.4.7', '>=');
    }

    /**
     * Get the transport based on configuration
     *
     * @return SymfonyTransportInterface|ZendTransportInterface
     */
    private function getTransport()
    {
        if (!isset($this->transport)) {
            if ($this->isSymfonyMailer) {
                $transportType = $this->isSmtpEnabled ? 'smtp' : $this->scopeConfig->getValue(
                    self::XML_PATH_TRANSPORT,
                    ScopeInterface::SCOPE_STORE,
                    $this->storeId
                );
                $this->transport = $transportType === 'smtp' ? $this->createSmtpTransport() : $this->createSendmailTransport();
            } else {
                $this->transport = $this->resourceMail->getTransport($this->storeId);
            }
        }
        return $this->transport;
    }

    /**
     * Create SMTP transport for Symfony Mailer
     *
     * @return SymfonyTransportInterface
     */
    private function createSmtpTransport(): SymfonyTransportInterface
    {
        $host = $this->scopeConfig->getValue(
            $this->isSmtpEnabled ? self::XML_PATH_MT_SMTP_HOST : self::XML_PATH_HOST,
            ScopeInterface::SCOPE_STORE,
            $this->storeId
        );
        $port = (int) $this->scopeConfig->getValue(
            $this->isSmtpEnabled ? self::XML_PATH_MT_SMTP_PORT : self::XML_PATH_PORT,
            ScopeInterface::SCOPE_STORE,
            $this->storeId
        );
        $username = $this->scopeConfig->getValue(
            $this->isSmtpEnabled ? self::XML_PATH_MT_SMTP_USERNAME : self::XML_PATH_USERNAME,
            ScopeInterface::SCOPE_STORE,
            $this->storeId
        );
        $password = $this->scopeConfig->getValue(
            $this->isSmtpEnabled ? self::XML_PATH_MT_SMTP_PASSWORD : self::XML_PATH_PASSWORD,
            ScopeInterface::SCOPE_STORE,
            $this->storeId
        );
        $password = $this->isSmtpEnabled ? $this->encryptor->decrypt($password) : $password;
        $auth = $this->scopeConfig->getValue(
            $this->isSmtpEnabled ? self::XML_PATH_MT_SMTP_AUTH : self::XML_PATH_AUTH,
            ScopeInterface::SCOPE_STORE,
            $this->storeId
        );
        $ssl = $this->scopeConfig->getValue(
            $this->isSmtpEnabled ? self::XML_PATH_MT_SMTP_PROTOCOL : self::XML_PATH_SSL,
            ScopeInterface::SCOPE_STORE,
            $this->storeId
        );
        $tls = $ssl === 'tls';

        $transport = new EsmtpTransport($host, $port, $tls);
        if ($username) {
            $transport->setUsername($username);
        }
        if ($password) {
            $transport->setPassword($password);
        }

        switch ($auth) {
            case 'plain':
                $transport->setAuthenticators([new PlainAuthenticator()]);
                break;
            case 'login':
                $transport->setAuthenticators([new LoginAuthenticator()]);
                break;
            case 'none':
                break;
            default:
                throw new \InvalidArgumentException('Invalid authentication type: ' . $auth);
        }

        return $transport;
    }

    /**
     * Create Sendmail transport for Symfony Mailer
     *
     * @return SymfonyTransportInterface
     */
    private function createSendmailTransport(): SymfonyTransportInterface
    {
        $dsn = new Dsn('native', 'default');
        $nativeTransportFactory = new NativeTransportFactory();
        return $nativeTransportFactory->create($dsn);
    }

    /**
     * Set the return path for Symfony Mailer
     *
     * @param SymfonyMessage $email
     */
    private function setReturnPathSymfony(SymfonyMessage $email): void
    {
        if ($this->isSetReturnPath === 2 && $this->returnPathValue) {
            $email->getHeaders()->addMailboxListHeader('Sender', [$this->returnPathValue]);
        } elseif ($this->isSetReturnPath === 1 &&
            !empty($fromAddresses = $email->getHeaders()->get('From')?->getAddresses())) {
            reset($fromAddresses);
            $email->getHeaders()->addMailboxListHeader('Sender', [current($fromAddresses)->getAddress()]);
        }
    }

    /**
     * Set the return path for Laminas Mail
     *
     * @param ZendMessage $message
     */
    private function setReturnPathLaminas(ZendMessage $message): void
    {
        if ($this->isSetReturnPath === 2 && $this->returnPathValue) {
            $message->setSender($this->returnPathValue);
        } elseif ($this->isSetReturnPath === 1 && $from = $message->getFrom()) {
            $from->rewind();
            if ($firstFrom = $from->current()) {
                $message->setSender($firstFrom->getEmail());
            }
        }
    }

    /**
     * Send the email message
     *
     * @throws MailException
     */
    public function sendMessage(): void
    {
        $blocklistEmails = explode(',', trim(preg_replace('/\s\s+/', '', $this->helper->getConfigGeneral('blocklist_emails'))));
        $isBlocked = false;
        if ($this->isSymfonyMailer) {
            $email = $this->message->getSymfonyMessage();
            foreach ($email->getHeaders()->get('To')->getBody() as $address) {
                if (in_array($address->getAddress(), $blocklistEmails, true)) {
                    $isBlocked = true;
                    break;
                }
            }
            if ($this->resourceMail->isModuleEnable($this->storeId)) {
                if (!$this->resourceMail->isDeveloperMode($this->storeId)) {
                    if (!$isBlocked) {
                        try {
                            $this->setReturnPathSymfony($email);
                            $mailer = new Mailer($this->getTransport());
                            $mailer->send($email);
                            $this->emailLog($email, true);
                        } catch (TransportExceptionInterface $e) {
                            $this->emailLog($email, false);
                            $this->logger->error('Transport error: ' . $e->getMessage());
                            throw new MailException(new Phrase('Transport error: Unable to send mail at this time.'), $e);
                        } catch (\Exception $e) {
                            $this->emailLog($email, false);
                            throw new MailException(new Phrase('Unable to send mail. Please try again later.'), $e);
                        }
                    }
                }
            } else {
                $email = $this->message->getSymfonyMessage();
                $this->setReturnPathSymfony($email);
                $mailer = new Mailer($this->getTransport());
                $mailer->send($email);
            }
        } else {
            $message = $this->resourceMail->processMessage($this->message, $this->storeId);
            if ($this->helper->versionCompare('2.2.8')) {
                $message = ZendMessage::fromString($message->getRawMessage())->setEncoding('utf-8');
            }
            foreach ($message->getTo() as $address) {
                if (in_array($address->getEmail(), $blocklistEmails, true)) {
                    $isBlocked = true;
                    break;
                }
            }
            if (!$isBlocked && $this->resourceMail->isModuleEnable($this->storeId) && !$this->resourceMail->isDeveloperMode($this->storeId)) {
                try {
                    if ($this->helper->versionCompare('2.3.3')) {
                        $message->getHeaders()->removeHeader('Content-Disposition');
                    }
                    $this->setReturnPathLaminas($message);
                    $this->getTransport()->send($message);
                    $this->emailLog($message, true);
                } catch (\Exception $e) {
                    $this->emailLog($message, false);
                    throw new MailException(new Phrase($e->getMessage()), $e);
                }
            }
        }
    }

    /**
     * Log email sending status
     *
     * @param ZendMessage|SymfonyMessage $message
     * @param bool $status
     */
    private function emailLog($message, bool $status = true): void
    {
        if ($this->resourceMail->isEnableEmailLog($this->storeId)) {
            $log = $this->logFactory->create();
            try {
                $log->saveLog($message, $status);
            } catch (\Exception $e) {
                $this->logger->critical($e->getMessage());
            }
        }
    }

    /**
     * Get the message
     *
     * @return EmailMessageInterface
     */
    public function getMessage(): EmailMessageInterface
    {
        if ($this->helper->versionCompare('2.2.0')) {
            return $this->message;
        }
        try {
            $reflectionClass = new ReflectionClass($this);
            $messageProperty = $reflectionClass->getProperty('message');
            $messageProperty->setAccessible(true);
            return $messageProperty->getValue($this);
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
            throw new MailException(new Phrase('Unable to retrieve message.'), $e);
        }
    }
}
