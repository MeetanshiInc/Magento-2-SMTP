<?php

/**
 * Copyright 2015 Adobe
 * All Rights Reserved.
 * Copyright 2025 Meetanshi
 * All Rights Reserved.
 */

namespace Meetanshi\SMTP\Mail;

use Laminas\Mail\AddressList;
use Laminas\Mail\Exception\InvalidArgumentException as LaminasInvalidArgumentException;
use Laminas\Mime\Message as LaminasMimeMessage;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Mail\Address;
use Magento\Framework\Mail\AddressFactory;
use Magento\Framework\Mail\EmailMessageInterface;
use Magento\Framework\Mail\Exception\InvalidArgumentException;
use Magento\Framework\Mail\Message;
use Magento\Framework\Mail\MimeMessageInterface;
use Magento\Framework\Mail\MimeMessageInterfaceFactory;
use Psr\Log\LoggerInterface;

/**
 * Magento Framework Email message compatible across Magento 2 versions.
 *
 * Supports both Laminas Mail (Magento <= 2.4.7.x) and Symfony Mailer (Magento >= 2.4.8)
 * by detecting whether Symfony Mailer classes are actually available at runtime.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveParameterList)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 */
class EmailMessage extends Message implements EmailMessageInterface
{
    /**
     * @var MimeMessageInterfaceFactory
     */
    private $mimeMessageFactory;

    /**
     * @var AddressFactory
     */
    private $addressFactory;

    /**
     * @var LoggerInterface|null
     */
    private $logger;

    /**
     * @var LaminasMimeMessage|object
     */
    private $message;

    /**
     * @var bool
     */
    private $isSymfonyMailer;

    /**
     * Constructor
     *
     * @param MimeMessageInterface $body
     * @param array $to
     * @param MimeMessageInterfaceFactory $mimeMessageFactory
     * @param AddressFactory $addressFactory
     * @param array|null $from
     * @param array|null $cc
     * @param array|null $bcc
     * @param array|null $replyTo
     * @param Address|null $sender
     * @param string|null $subject
     * @param string|null $encoding
     * @param LoggerInterface|null $logger
     * @throws InvalidArgumentException
     */
    public function __construct(
        MimeMessageInterface $body,
        array $to,
        MimeMessageInterfaceFactory $mimeMessageFactory,
        AddressFactory $addressFactory,
        ?array $from = null,
        ?array $cc = null,
        ?array $bcc = null,
        ?array $replyTo = null,
        ?Address $sender = null,
        ?string $subject = '',
        ?string $encoding = 'utf-8',
        ?LoggerInterface $logger = null
    ) {
        parent::__construct($encoding);
        $this->mimeMessageFactory = $mimeMessageFactory;
        $this->addressFactory = $addressFactory;
        $this->logger = $logger ?: ObjectManager::getInstance()->get(LoggerInterface::class);
        $this->isSymfonyMailer = self::detectSymfonyMailer();

        if ($this->isSymfonyMailer) {
            $this->initSymfony($body, $to, $from, $cc, $bcc, $replyTo, $sender, $subject);
        } else {
            $this->initLaminas($body, $to, $from, $cc, $bcc, $replyTo, $sender, $subject);
        }
    }

    /**
     * Detect if Symfony Mailer is available (Magento >= 2.4.8)
     *
     * This checks for the actual presence of Symfony Mailer classes rather than
     * relying on Magento version numbers, making it forward-compatible.
     *
     * @return bool
     */
    public static function detectSymfonyMailer(): bool
    {
        return class_exists(\Symfony\Component\Mailer\Mailer::class)
            && class_exists(\Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport::class);
    }

    /**
     * Initialize message using Symfony Mailer (Magento >= 2.4.8)
     *
     * @param MimeMessageInterface $body
     * @param array $to
     * @param array|null $from
     * @param array|null $cc
     * @param array|null $bcc
     * @param array|null $replyTo
     * @param Address|null $sender
     * @param string|null $subject
     * @throws InvalidArgumentException
     */
    private function initSymfony(
        MimeMessageInterface $body,
        array $to,
        ?array $from,
        ?array $cc,
        ?array $bcc,
        ?array $replyTo,
        ?Address $sender,
        ?string $subject
    ): void {
        // Symfony path: $body should provide getMimeMessage() in future Magento versions
        $this->message = $body->getMimeMessage();
        $this->setBody($this->message);

        if (!empty($subject)) {
            $this->message->getHeaders()->addTextHeader('Subject', $subject);
        }

        if ($sender) {
            $symfonyAddress = new \Symfony\Component\Mime\Address(
                $this->sanitiseEmail($sender->getEmail()),
                $sender->getName()
            );
            $this->message->getHeaders()->addMailboxHeader('Sender', $symfonyAddress);
        }

        $this->setRecipientsSymfony($to, 'To');
        $this->setRecipientsSymfony($replyTo, 'Reply-To');
        $this->setRecipientsSymfony($from, 'From');
        $this->setRecipientsSymfony($cc, 'Cc');
        $this->setRecipientsSymfony($bcc, 'Bcc');
    }

    /**
     * Initialize message using Laminas Mail (Magento <= 2.4.7.x)
     *
     * @param MimeMessageInterface $body
     * @param array $to
     * @param array|null $from
     * @param array|null $cc
     * @param array|null $bcc
     * @param array|null $replyTo
     * @param Address|null $sender
     * @param string|null $subject
     * @throws InvalidArgumentException
     */
    private function initLaminas(
        MimeMessageInterface $body,
        array $to,
        ?array $from,
        ?array $cc,
        ?array $bcc,
        ?array $replyTo,
        ?Address $sender,
        ?string $subject
    ): void {
        $this->message = new LaminasMimeMessage();
        $this->message->setParts($body->getParts());
        $this->zendMessage->setBody($this->message);

        if ($subject) {
            $this->zendMessage->setSubject($subject);
        }
        if ($sender) {
            $this->zendMessage->setSender($sender->getEmail(), $sender->getName());
        }
        if (count($to) < 1) {
            throw new InvalidArgumentException('Email message must have at least one addressee');
        }
        if ($to) {
            $this->zendMessage->setTo($this->convertAddressArrayToAddressList($to));
        }
        if ($replyTo) {
            $this->zendMessage->setReplyTo($this->convertAddressArrayToAddressList($replyTo));
        }
        if ($from) {
            $this->zendMessage->setFrom($this->convertAddressArrayToAddressList($from));
        }
        if ($cc) {
            $this->zendMessage->setCc($this->convertAddressArrayToAddressList($cc));
        }
        if ($bcc) {
            $this->zendMessage->setBcc($this->convertAddressArrayToAddressList($bcc));
        }
    }

    /**
     * Set recipients for Symfony Mailer
     *
     * @param array|null $addresses
     * @param string $headerName
     * @throws InvalidArgumentException
     */
    private function setRecipientsSymfony(?array $addresses, string $headerName): void
    {
        if ($headerName === 'To' && (empty($addresses) || count($addresses) < 1)) {
            throw new InvalidArgumentException('Email message must have at least one addressee');
        }
        if (!$addresses) {
            return;
        }
        $recipients = [];
        foreach ($addresses as $address) {
            try {
                if ($address instanceof Address) {
                    $recipients[] = new \Symfony\Component\Mime\Address(
                        $this->sanitiseEmail($address->getEmail()),
                        $address->getName() ?? ''
                    );
                } else {
                    $recipients[] = new \Symfony\Component\Mime\Address(
                        $this->sanitiseEmail($address['email']),
                        $address['name'] ?? ''
                    );
                }
            } catch (\Exception $e) {
                $this->logger->warning(
                    'Could not add an invalid email address to the mailing queue',
                    ['exception' => $e]
                );
                continue;
            }
        }
        $this->message->getHeaders()->addMailboxListHeader($headerName, $recipients);
    }

    /**
     * Convert address array to Laminas AddressList
     *
     * @param array|null $addresses
     * @return AddressList
     */
    private function convertAddressArrayToAddressList(?array $addresses): AddressList
    {
        $laminasAddressList = new AddressList();
        if (!$addresses) {
            return $laminasAddressList;
        }
        foreach ($addresses as $address) {
            try {
                if ($address instanceof Address) {
                    $laminasAddressList->add($address->getEmail(), $address->getName());
                } else {
                    $laminasAddressList->add($address['email'], $address['name'] ?? '');
                }
            } catch (LaminasInvalidArgumentException $e) {
                $this->logger->warning(
                    'Could not add an invalid email address to the mailing queue',
                    ['exception' => $e]
                );
                continue;
            }
        }
        return $laminasAddressList;
    }

    /**
     * Sanitise email address
     *
     * @param ?string $email
     * @return ?string
     * @throws InvalidArgumentException
     */
    private function sanitiseEmail(?string $email): ?string
    {
        if (!empty($email) && str_starts_with($email, '=?')) {
            $decodedValue = iconv_mime_decode($email, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
            if (str_contains($decodedValue, ' ')) {
                throw new InvalidArgumentException('Invalid email format');
            }
            return $decodedValue;
        }
        return $email;
    }

    /**
     * @inheritDoc
     */
    public function getEncoding(): string
    {
        if ($this->isSymfonyMailer) {
            return $this->message->getHeaders()->getHeaderBody('Content-Transfer-Encoding') ?? 'utf-8';
        }
        return $this->zendMessage->getEncoding();
    }

    /**
     * @inheritDoc
     */
    public function getHeaders(): array
    {
        if ($this->isSymfonyMailer) {
            return $this->message->getHeaders()->toArray();
        }
        return $this->zendMessage->getHeaders()->toArray();
    }

    /**
     * @inheritDoc
     */
    public function getFrom(): ?array
    {
        if ($this->isSymfonyMailer) {
            $header = $this->message->getHeaders()->get('From');
            return $header ? $this->convertSymfonyAddressListToAddressArray($header->getAddresses()) : null;
        }
        return $this->convertAddressListToAddressArray($this->zendMessage->getFrom());
    }

    /**
     * @inheritDoc
     */
    public function getTo(): array
    {
        if ($this->isSymfonyMailer) {
            $header = $this->message->getHeaders()->get('To');
            return $header ? $this->convertSymfonyAddressListToAddressArray($header->getAddresses()) : [];
        }
        return $this->convertAddressListToAddressArray($this->zendMessage->getTo());
    }

    /**
     * @inheritDoc
     */
    public function getCc(): ?array
    {
        if ($this->isSymfonyMailer) {
            $header = $this->message->getHeaders()->get('Cc');
            return $header ? $this->convertSymfonyAddressListToAddressArray($header->getAddresses()) : null;
        }
        return $this->convertAddressListToAddressArray($this->zendMessage->getCc());
    }

    /**
     * @inheritDoc
     */
    public function getBcc(): ?array
    {
        if ($this->isSymfonyMailer) {
            $header = $this->message->getHeaders()->get('Bcc');
            return $header ? $this->convertSymfonyAddressListToAddressArray($header->getAddresses()) : null;
        }
        return $this->convertAddressListToAddressArray($this->zendMessage->getBcc());
    }

    /**
     * @inheritDoc
     */
    public function getReplyTo(): ?array
    {
        if ($this->isSymfonyMailer) {
            $header = $this->message->getHeaders()->get('Reply-To');
            return $header ? $this->convertSymfonyAddressListToAddressArray($header->getAddresses()) : null;
        }
        return $this->convertAddressListToAddressArray($this->zendMessage->getReplyTo());
    }

    /**
     * @inheritDoc
     */
    public function getSender(): ?Address
    {
        if ($this->isSymfonyMailer) {
            $senderHeader = $this->message->getHeaders()->get('Sender');
            if (!$senderHeader || !$senderAddress = $senderHeader->getAddress()) {
                return null;
            }
            return $this->addressFactory->create([
                'email' => $senderAddress->getAddress(),
                'name' => $senderAddress->getName(),
            ]);
        }
        if (!$laminasSender = $this->zendMessage->getSender()) {
            return null;
        }
        return $this->addressFactory->create([
            'email' => $laminasSender->getEmail(),
            'name' => $laminasSender->getName(),
        ]);
    }

    /**
     * @inheritDoc
     */
    public function getMessageBody(): MimeMessageInterface
    {
        if ($this->isSymfonyMailer) {
            $parts = [];
            if ($this->message->getBody() instanceof \Symfony\Component\Mime\Part\TextPart) {
                $parts[] = $this->message->getBody();
            }
            return $this->mimeMessageFactory->create(['parts' => $parts]);
        }
        return $this->mimeMessageFactory->create(['parts' => $this->message->getParts()]);
    }

    /**
     * @inheritDoc
     */
    public function getBodyText(): string
    {
        if ($this->isSymfonyMailer) {
            return $this->message->getTextBody() ?? '';
        }
        return $this->zendMessage->getBodyText();
    }

    /**
     * Get Symfony Message (only available when Symfony Mailer is active)
     *
     * @return object
     */
    public function getSymfonyMessage()
    {
        return $this->message;
    }

    /**
     * @inheritDoc
     */
    public function getBodyHtml(): string
    {
        if ($this->isSymfonyMailer) {
            return $this->message->getHtmlBody() ?? '';
        }
        return $this->zendMessage->getBodyText();
    }

    /**
     * @inheritDoc
     */
    public function toString(): string
    {
        if ($this->isSymfonyMailer) {
            return $this->message->toString();
        }
        return $this->zendMessage->toString();
    }

    /**
     * Whether this instance is using Symfony Mailer
     *
     * @return bool
     */
    public function isUsingSymfonyMailer(): bool
    {
        return $this->isSymfonyMailer;
    }

    /**
     * Convert Symfony address list to Address array
     *
     * @param array $addressList
     * @return array
     */
    private function convertSymfonyAddressListToAddressArray(array $addressList): array
    {
        return array_map(function ($address) {
            return $this->addressFactory->create([
                'email' => $this->sanitiseEmail($address->getAddress()),
                'name' => $address->getName(),
            ]);
        }, $addressList);
    }

    /**
     * Convert Laminas AddressList to Address array
     *
     * @param AddressList $addressList
     * @return array
     */
    private function convertAddressListToAddressArray(?AddressList $addressList): array
    {
        if (!$addressList) {
            return [];
        }
        $arrayList = [];
        foreach ($addressList as $address) {
            $arrayList[] = $this->addressFactory->create([
                'email' => $address->getEmail(),
                'name' => $address->getName(),
            ]);
        }
        return $arrayList;
    }
}
