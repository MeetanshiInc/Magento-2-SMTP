<?php

namespace Meetanshi\SMTP\Model;

use Exception;
use Magento\Framework\App\Area;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\DataObject;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Model\AbstractModel;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use Magento\Store\Model\Store;
use Meetanshi\SMTP\Helper\Data;
use Meetanshi\SMTP\Mail\Rse\Mail;
use Meetanshi\SMTP\Model\ResourceModel\Logs as ResLogs;
use Meetanshi\SMTP\Model\Source\Status;

class Logs extends AbstractModel
{
    /**
     * @var TransportBuilder
     */
    protected $_transportBuilder;

    /**
     * @var Mail
     */
    protected $mailResource;

    /**
     * @var Data
     */
    protected $helper;

    /**
     * Log constructor.
     * @param Context $context
     * @param Registry $registry
     * @param TransportBuilder $transportBuilder
     * @param Mail $mailResource
     * @param Data $helper
     * @param AbstractResource|null $resource
     * @param AbstractDb|null $resourceCollection
     * @param array $data
     */
    public function __construct(
        Context $context,
        Registry $registry,
        TransportBuilder $transportBuilder,
        Mail $mailResource,
        Data $helper,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct($context, $registry, $resource, $resourceCollection, $data);
        $this->_transportBuilder = $transportBuilder;
        $this->mailResource = $mailResource;
        $this->helper = $helper;
    }

    /**
     * @return void
     */
    public function _construct()
    {
        $this->_init(ResLogs::class);
    }

    /**
     * Save email logs
     *
     * Supports both Symfony Message (Magento >= 2.4.8) and Laminas Message (Magento <= 2.4.7.x)
     *
     * @param object $message
     * @param bool $status
     */
    public function saveLog($message, $status)
    {
        if ($message instanceof \Laminas\Mail\Message) {
            $this->saveLogLaminas($message);
        } else {
            // Symfony Message (Magento >= 2.4.8)
            $this->saveLogSymfony($message);
        }

        $this->setStatus($status)->save();
    }

    /**
     * Save log from Symfony Message
     *
     * @param object $message
     */
    private function saveLogSymfony($message): void
    {
        $headers = $message->getHeaders()->toArray();

        if (isset($headers[0])) {
            $this->setSubject(str_replace('Subject: ', '', $headers[0]));
        }

        if (isset($headers[2])) {
            $sender = $headers[2];
            $sender = str_replace('From: ', '', $sender);
            preg_match('/^(.*?)(?:\s*<[^>]+>)?$/', $sender, $senderMatch);
            $name = !empty($senderMatch[1]) ? trim($senderMatch[1]) : trim($sender);
            preg_match('/<(.+?)>/', $sender, $emailMatch);
            $email = !empty($emailMatch[1]) ? $emailMatch[1] : '';
            $this->setName($name);
            $this->setSender($email);
        }

        if (isset($headers[1])) {
            $recipient = $headers[1];
            preg_match('/<(.+?)>/', $recipient, $recipientMatch);
            $this->setRecipient(!empty($recipientMatch[1]) ? $recipientMatch[1] : str_replace('To: ', '', trim($recipient)));
        }

        if (isset($headers[3])) {
            $bcc = str_replace('Bcc: ', '', $headers[3]);
            $bccEmails = array_map('trim', explode(',', $bcc));
            $this->setBcc(implode(',', $bccEmails));
        }

        $content = $message->getBody()->bodyToString();
        $this->setEmailContent($content);
    }

    /**
     * Save log from Laminas Message
     *
     * @param \Laminas\Mail\Message $message
     */
    private function saveLogLaminas(\Laminas\Mail\Message $message): void
    {
        if ($message->getSubject()) {
            $this->setSubject($message->getSubject());
        }

        $from = $message->getFrom();
        if (!empty($from)) {
            $from->rewind();
            $current = $from->current();
            if ($current) {
                $this->setSender($current->getName() . ' <' . $current->getEmail() . '>');
            }
        }

        $toArr = [];
        foreach ($message->getTo() as $toAddr) {
            $toArr[] = $toAddr->getEmail();
        }
        $this->setRecipient(implode(',', $toArr));

        $ccArr = [];
        foreach ($message->getCc() as $ccAddr) {
            $ccArr[] = $ccAddr->getEmail();
        }
        $this->setCc(implode(',', $ccArr));

        $bccArr = [];
        foreach ($message->getBcc() as $bccAddr) {
            $bccArr[] = $bccAddr->getEmail();
        }
        $this->setBcc(implode(',', $bccArr));

        $messageBody = quoted_printable_decode($message->getBodyText());
        $content = htmlspecialchars($messageBody);
        $this->setEmailContent($content);
    }

    /**
     * @return bool
     */
    public function resendEmail()
    {
        $data = $this->getData();
        $data['email_content'] = htmlspecialchars_decode($data['email_content']);

        $dataObject = new DataObject();
        $dataObject->setData($data);

        $sender = [
            'name' => trim($data['name'] ?? ''),
            'email' => trim($data['sender'] ?? ''),
        ];
        $recipient = $this->extractEmailInfo($data['recipient']);
        foreach ($recipient as $name => $email) {
            $this->_transportBuilder->addTo($email);
        }

        if (!empty($data['cc'])) {
            $ccEmails = $this->extractEmailInfo($data['cc']);
            foreach ($ccEmails as $name => $email) {
                $name = trim($name ?? '');
                $this->_transportBuilder->addCc($email, $name);
            }
        }

        if (!empty($data['bcc'])) {
            $bccEmails = explode(',', $data['bcc'] ?? '');
            foreach ($bccEmails as $email) {
                $email = trim($email);
                if ($email) {
                    $this->_transportBuilder->addBcc($email);
                }
            }
        }

        $this->mailResource->setSmtpOptions(Store::DEFAULT_STORE_ID, ['ignore_log' => true]);

        try {
            $this->_transportBuilder
                ->setTemplateIdentifier('mt_resend_email_template')
                ->setTemplateOptions(['area' => Area::AREA_FRONTEND, 'store' => Store::DEFAULT_STORE_ID])
                ->setTemplateVars($data)
                ->setFrom($sender);

            $this->_transportBuilder->getTransport()
                ->sendMessage();

            $this->setStatus(Status::STATUS_SUCCESS)
                ->save();
        } catch (Exception $e) {
            $this->_logger->critical($e->getMessage());
            return false;
        }

        return true;
    }

    /**
     * @param $emailList
     * @return array
     */
    protected function extractEmailInfo($emailList)
    {
        $data = [];
        if (strpos($emailList, ' <') !== false) {
            $emails = explode(' <', $emailList);
            $name = '';
            if (!empty($emails)) {
                $name = $emails[0];
            }
            $email = trim($emails[1] ?? '', '>');
            $data[$name] = $email;
        } else {
            $emails = explode(',', $emailList);
            foreach ($emails as $email) {
                $email = trim($email);
                if ($email) {
                    $data[] = $email;
                }
            }
        }

        return $data;
    }
}
