<?php

/**
 * Copyright 2015 Adobe
 * All Rights Reserved.
 * Copyright 2025 Meetanshi
 * All Rights Reserved.
 */

namespace Meetanshi\SMTP\Mail;

use Laminas\Mail\Message as LaminasMessage;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Exception\MailException;
use Magento\Framework\Mail\EmailMessageInterface;
use Magento\Framework\Mail\TransportInterface;
use Magento\Framework\Phrase;
use Magento\Framework\Registry;
use Magento\Store\Model\ScopeInterface;
use Meetanshi\SMTP\Helper\Data;
use Meetanshi\SMTP\Mail\Rse\Mail;
use Meetanshi\SMTP\Model\LogsFactory;
use Psr\Log\LoggerInterface;

/**
 * Class responsible for sending emails with compatibility across Magento 2 versions.
 *
 * Supports both Laminas Mail (Magento <= 2.4.7.x) and Symfony Mailer (Magento >= 2.4.8)
 * by detecting whether Symfony Mailer classes are actually available at runtime.
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
     * @var object
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
     * @param EncryptorInterface $encryptor
     */
    public function __construct(
        EmailMessageInterface $message,
        ScopeConfigInterface $scopeConfig,
        Mail $resourceMail,
        Registry $registry,
        Data $helper,
        LogsFactory $logFactory,
        LoggerInterface $logger,
        EncryptorInterface $encryptor
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
        $this->isSymfonyMailer = EmailMessage::detectSymfonyMailer();

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
     * Get the transport based on configuration
     *
     * @return object
     */
    private function getTransport()
    {
        if (!isset($this->transport)) {
            if ($this->isSymfonyMailer) {
                // Check if manual SMTP options were set (e.g., from test controller)
                $manualOptions = $this->resourceMail->getManualSmtpOptions($this->storeId);
                if (!empty($manualOptions)) {
                    $this->transport = $this->createSymfonySmtpTransportFromOptions($manualOptions);
                } else {
                    $transportType = $this->isSmtpEnabled ? 'smtp' : $this->scopeConfig->getValue(
                        self::XML_PATH_TRANSPORT,
                        ScopeInterface::SCOPE_STORE,
                        $this->storeId
                    );
                    $this->transport = $transportType === 'smtp'
                        ? $this->createSymfonySmtpTransport()
                        : $this->createSymfonySendmailTransport();
                }
            } else {
                $this->transport = $this->resourceMail->getTransport($this->storeId);
            }
        }
        return $this->transport;
    }

    /**
     * Create SMTP transport for Symfony Mailer from manual options (test controller)
     *
     * @param array $options
     * @return object
     */
    private function createSymfonySmtpTransportFromOptions(array $options)
    {
        $host = $options['host'] ?? 'localhost';
        $port = (int) ($options['port'] ?? 465);
        $username = $options['username'] ?? '';
        $password = $options['password'] ?? '';
        $auth = $options['auth'] ?? 'login';
        $ssl = $options['ssl'] ?? '';

        $tls = null;
        if ($ssl === 'tls') {
            $tls = true;
        } elseif ($ssl === 'ssl') {
            $tls = null;
        } else {
            $tls = false;
        }

        $transport = new \Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport($host, $port, $tls);
        if ($username) {
            $transport->setUsername($username);
        }
        if ($password) {
            $transport->setPassword($password);
        }

        switch ($auth) {
            case 'plain':
                $transport->setAuthenticators([new \Symfony\Component\Mailer\Transport\Smtp\Auth\PlainAuthenticator()]);
                break;
            case 'login':
                $transport->setAuthenticators([new \Symfony\Component\Mailer\Transport\Smtp\Auth\LoginAuthenticator()]);
                break;
            case 'none':
                break;
        }

        return $transport;
    }

    /**
     * Create SMTP transport for Symfony Mailer (Magento >= 2.4.8)
     *
     * @return object
     */
    private function createSymfonySmtpTransport()
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
        $tls = null;
        if ($ssl === 'tls') {
            $tls = true;
        } elseif ($ssl === 'ssl') {
            // Port 465 with SSL: pass null to let Symfony auto-detect (it enables TLS for port 465)
            $tls = null;
        } else {
            $tls = false;
        }

        $transport = new \Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport($host, $port, $tls);
        if ($username) {
            $transport->setUsername($username);
        }
        if ($password) {
            $transport->setPassword($password);
        }

        switch ($auth) {
            case 'plain':
                $transport->setAuthenticators([new \Symfony\Component\Mailer\Transport\Smtp\Auth\PlainAuthenticator()]);
                break;
            case 'login':
                $transport->setAuthenticators([new \Symfony\Component\Mailer\Transport\Smtp\Auth\LoginAuthenticator()]);
                break;
            case 'none':
                break;
            default:
                throw new \InvalidArgumentException('Invalid authentication type: ' . $auth);
        }

        return $transport;
    }

    /**
     * Create Sendmail transport for Symfony Mailer (Magento >= 2.4.8)
     *
     * @return object
     */
    private function createSymfonySendmailTransport()
    {
        $dsn = new \Symfony\Component\Mailer\Transport\Dsn('native', 'default');
        $nativeTransportFactory = new \Symfony\Component\Mailer\Transport\NativeTransportFactory();
        return $nativeTransportFactory->create($dsn);
    }

    /**
     * Set the return path for Symfony Mailer
     *
     * @param object $email Symfony Message instance
     */
    private function setReturnPathSymfony($email): void
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
     * @param LaminasMessage $message
     */
    private function setReturnPathLaminas(LaminasMessage $message): void
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
        $blocklistEmails = explode(
            ',',
            trim(preg_replace('/\s\s+/', '', $this->helper->getConfigGeneral('blocklist_emails')))
        );
        $isBlocked = false;

        if ($this->isSymfonyMailer) {
            $this->sendSymfony($blocklistEmails, $isBlocked);
        } else {
            $this->sendLaminas($blocklistEmails, $isBlocked);
        }
    }

    /**
     * Send using Symfony Mailer (Magento >= 2.4.8)
     *
     * @param array $blocklistEmails
     * @param bool $isBlocked
     * @throws MailException
     */
    private function sendSymfony(array $blocklistEmails, bool $isBlocked): void
    {
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
                        $mailer = new \Symfony\Component\Mailer\Mailer($this->getTransport());
                        $mailer->send($email);
                        $this->emailLog($email, true);
                    } catch (\Symfony\Component\Mailer\Exception\TransportExceptionInterface $e) {
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
            $this->setReturnPathSymfony($email);
            $mailer = new \Symfony\Component\Mailer\Mailer($this->getTransport());
            $mailer->send($email);
        }
    }

    /**
     * Send using Laminas Mail (Magento <= 2.4.7.x)
     *
     * @param array $blocklistEmails
     * @param bool $isBlocked
     * @throws MailException
     */
    private function sendLaminas(array $blocklistEmails, bool $isBlocked): void
    {
        $message = LaminasMessage::fromString($this->message->getRawMessage())->setEncoding('utf-8');

        foreach ($message->getTo() as $address) {
            if (in_array($address->getEmail(), $blocklistEmails, true)) {
                $isBlocked = true;
                break;
            }
        }

        if (!$isBlocked && $this->resourceMail->isModuleEnable($this->storeId)
            && !$this->resourceMail->isDeveloperMode($this->storeId)) {
            try {
                $message->getHeaders()->removeHeader('Content-Disposition');
                $this->setReturnPathLaminas($message);
                $this->getTransport()->send($message);
                $this->emailLog($message, true);
            } catch (\Exception $e) {
                $this->emailLog($message, false);
                throw new MailException(new Phrase($e->getMessage()), $e);
            }
        }
    }

    /**
     * Log email sending status
     *
     * @param object $message LaminasMessage or Symfony Message
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
        return $this->message;
    }
}
