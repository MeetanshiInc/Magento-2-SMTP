<?php

/**
 * Copyright 2025 Meetanshi
 * All Rights Reserved.
 */

namespace Meetanshi\SMTP\Mail\Rse;

use Laminas\Mail\Message;
use Laminas\Mail\Transport\Smtp;
use Laminas\Mail\Transport\SmtpOptions;
use Meetanshi\SMTP\Helper\Data;

/**
 * Resource mail class for SMTP transport configuration.
 *
 * Handles Laminas SMTP transport creation and message processing.
 * Used by Transport.php for the Laminas Mail path (Magento <= 2.4.7.x).
 */
class Mail
{
    /**
     * @var Data
     */
    protected $smtpHelper;

    /**
     * @var array Is module enable by store
     */
    protected $_moduleEnable = [];

    /**
     * @var array is developer mode
     */
    protected $_developerMode = [];

    /**
     * @var array is enable email log
     */
    protected $_emailLog = [];

    /**
     * @var string message body email
     */
    protected $_message;

    /**
     * @var array option by storeid
     */
    protected $_smtpOptions = [];

    /**
     * @var array
     */
    protected $_returnPath = [];

    /**
     * @var Smtp|null
     */
    protected $_transport;

    /**
     * @var array
     */
    protected $_fromByStore = [];

    /**
     * Mail constructor.
     * @param Data $helper
     */
    public function __construct(Data $helper)
    {
        $this->smtpHelper = $helper;
    }

    /**
     * @param $storeId
     * @param array $options
     *
     * @return $this
     */
    public function setSmtpOptions($storeId, $options = [])
    {
        if (isset($options['return_path'])) {
            $this->_returnPath[$storeId] = $options['return_path'];
            unset($options['return_path']);
        }

        if (isset($options['ignore_log']) && $options['ignore_log']) {
            $this->_emailLog[$storeId] = false;
            unset($options['ignore_log']);
        }

        if (isset($options['force_sent']) && $options['force_sent']) {
            $this->_moduleEnable[$storeId] = true;
            unset($options['force_sent']);
        }

        if (count($options)) {
            $this->_smtpOptions[$storeId] = $options;
        }
        return $this;
    }

    /**
     * Get manually set SMTP options (used by test controller flow)
     *
     * @param int $storeId
     * @return array
     */
    public function getManualSmtpOptions($storeId)
    {
        return $this->_smtpOptions[$storeId] ?? [];
    }

    /**
     * Get or create the Laminas SMTP transport
     *
     * @param int $storeId
     * @return Smtp
     * @throws \RuntimeException
     */
    public function getTransport($storeId)
    {
        if ($this->_transport === null) {
            if (!isset($this->_smtpOptions[$storeId])) {
                $configData = $this->smtpHelper->getSmtpConfig('', $storeId);
                $options = [
                    'host' => $configData['host'] ?? '',
                    'port' => $configData['port'] ?? '',
                ];

                if (!empty($configData['authentication'])) {
                    $options += [
                        'auth' => $configData['authentication'],
                        'username' => $configData['username'] ?? '',
                        'password' => $this->smtpHelper->getPassword($storeId),
                    ];
                }

                if (!empty($configData['protocol'])) {
                    $options['ssl'] = $configData['protocol'];
                }

                $this->_smtpOptions[$storeId] = $options;
            }

            if (empty($this->_smtpOptions[$storeId]['host'])) {
                throw new \RuntimeException(
                    (string) __('A host is necessary for smtp transport, but none was given')
                );
            }

            $options = $this->_smtpOptions[$storeId];

            if (isset($options['auth'])) {
                $options['connection_class'] = $options['auth'];
                $options['connection_config'] = [
                    'username' => $options['username'],
                    'password' => $options['password'],
                ];
                unset($options['auth'], $options['username'], $options['password']);
            }

            if (isset($options['ssl'])) {
                $options['connection_config']['ssl'] = $options['ssl'];
                unset($options['ssl']);
            }

            unset($options['type']);

            $this->_transport = new Smtp(new SmtpOptions($options));
        }

        return $this->_transport;
    }

    /**
     * @param $message
     * @param $storeId
     *
     * @return mixed
     */
    public function processMessage($message, $storeId)
    {
        if (!isset($this->_returnPath[$storeId])) {
            $this->_returnPath[$storeId] = $this->smtpHelper->getSmtpConfig('return_path_email', $storeId);
        }

        if (!$message->getReplyTo()) {
            if (is_string($this->_returnPath[$storeId])) {
                $message->setReplyTo(trim($this->_returnPath[$storeId]), $this->_fromByStore['name'] ?? '');
            }
        }

        if (!empty($this->_fromByStore) &&
            ($message instanceof Message && !$message->getFrom()->count())
        ) {
            $message->setFrom($this->_fromByStore['email'], $this->_fromByStore['name']);
        }

        return $message;
    }

    /**
     * @param $email
     * @param $name
     *
     * @return Mail
     */
    public function setFromByStore($email, $name)
    {
        $this->_fromByStore = [
            'email' => $email,
            'name' => $name,
        ];

        return $this;
    }

    /**
     * @param $storeId
     *
     * @return bool
     */
    public function isModuleEnable($storeId)
    {
        if (!isset($this->_moduleEnable[$storeId])) {
            $this->_moduleEnable[$storeId] = $this->smtpHelper->isEnabled($storeId);
        }

        return $this->_moduleEnable[$storeId];
    }

    /**
     * @param $storeId
     *
     * @return bool|mixed
     */
    public function isDeveloperMode($storeId)
    {
        if (!isset($this->_developerMode[$storeId])) {
            $this->_developerMode[$storeId] = $this->smtpHelper->getDeveloperConfig('developer_mode', $storeId);
        }

        return $this->_developerMode[$storeId];
    }

    /**
     * @param $storeId
     *
     * @return bool|mixed
     */
    public function isEnableEmailLog($storeId)
    {
        $this->_emailLog[$storeId] = $this->smtpHelper->getConfigGeneral('log_email', $storeId);
        return $this->_emailLog[$storeId];
    }
}
