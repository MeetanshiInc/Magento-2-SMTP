<?php

namespace Meetanshi\SMTP\Helper;

use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\ScopeInterface;

class Data extends AbstractData
{
    public const CONFIG_MODULE_PATH = 'smtp';
    public const CONFIG_GROUP_SMTP = 'configuration_option';
    public const DEVELOP_GROUP_SMTP = 'developer';

    /**
     * @param string $code
     * @param null $storeId
     * @return mixed
     */
    public function getSmtpConfig($code = '', $storeId = null)
    {
        $code = ($code !== '') ? '/' . $code : '';

        return $this->getModuleConfig(self::CONFIG_GROUP_SMTP . $code, $storeId);
    }

    /**
     * @param string $code
     * @param null $storeId
     * @return mixed
     */
    public function getDeveloperConfig($code = '', $storeId = null)
    {
        $code = ($code !== '') ? '/' . $code : '';

        return $this->getModuleConfig(self::DEVELOP_GROUP_SMTP . $code, $storeId);
    }

    /**
     * @param null $storeId
     * @param bool $decrypt
     * @return array|mixed|string
     */
    public function getPassword($storeId = null, $decrypt = true)
    {
        if ($storeId || $storeId = $this->_request->getParam('store')) {
            $password = $this->getSmtpConfig('password', $storeId);
        } elseif ($websiteCode = $this->_request->getParam('website')) {
            $passwordPath = self::CONFIG_MODULE_PATH . '/' . self::CONFIG_GROUP_SMTP . '/password';
            $password = $this->getConfigValue($passwordPath, $websiteCode, ScopeInterface::SCOPE_WEBSITE);
        } else {
            $password = $this->getSmtpConfig('password');
        }

        if ($decrypt) {
            $encryptor = $this->getObject(EncryptorInterface::class);

            return $encryptor->decrypt($password);
        }

        return $password;
    }

    /**
     * Get the Block List Emails as a clean list
     *
     * Addresses can be separated by commas, semicolons, spaces or new lines. Matching is not case-sensitive.
     *
     * @param int|string|null $storeId
     * @return string[]
     */
    public function getBlocklistEmails($storeId = null): array
    {
        $value = strtolower((string) $this->getConfigGeneral('blocklist_emails', $storeId));

        return array_values(array_unique(preg_split('/[\s,;]+/', $value, -1, PREG_SPLIT_NO_EMPTY)));
    }

    /**
     * Is the email address in the Block List Emails
     *
     * @param string $email
     * @param int|string|null $storeId
     * @return bool
     */
    public function isBlockedEmail($email, $storeId = null): bool
    {
        return in_array(strtolower(trim((string) $email)), $this->getBlocklistEmails($storeId), true);
    }

    /**
     * Get a saved SMTP option for the scope open in the admin (store / website / default)
     *
     * @param string $code
     * @return string
     */
    public function getSavedSmtpConfig($code)
    {
        if ($storeId = $this->_request->getParam('store')) {
            $value = $this->getSmtpConfig($code, $storeId);
        } elseif ($websiteCode = $this->_request->getParam('website')) {
            $path = self::CONFIG_MODULE_PATH . '/' . self::CONFIG_GROUP_SMTP . '/' . $code;
            $value = $this->getConfigValue($path, $websiteCode, ScopeInterface::SCOPE_WEBSITE);
        } else {
            $value = $this->getSmtpConfig($code);
        }

        return trim((string) $value);
    }

    /**
     * @return int
     * @throws LocalizedException
     */
    public function getScopeId()
    {
        $scope = $this->_request->getParam(ScopeInterface::SCOPE_STORE) ?: $this->storeManager->getStore()->getId();

        if ($website = $this->_request->getParam(ScopeInterface::SCOPE_WEBSITE)) {
            $scope = $this->storeManager->getWebsite($website)->getDefaultStore()->getId();
        }

        return $scope;
    }
}
