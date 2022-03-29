<?php

namespace Meetanshi\SMTP\Setup;

use Magento\Framework\Setup\SchemaSetupInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\DB\Ddl\Table;
use \Magento\Framework\Setup\InstallSchemaInterface;
use \Magento\Framework\DB\Adapter\AdapterInterface;

/**
 * Class InstallSchema
 * @package Meetanshi\SMTP\Setup
 */
class InstallSchema implements InstallSchemaInterface
{

    /**
     * @param SchemaSetupInterface $setup
     * @param ModuleContextInterface $context
     * @throws \Zend_Db_Exception
     */
    public function install(SchemaSetupInterface $setup, ModuleContextInterface $context)
    {
        $setup->startSetup();
        $context->getVersion();
        $conn = $setup->getConnection();
        $tableName = $setup->getTable('mt_email_logs');
        $fullTextIntex = array('subject', 'recipient', 'cc', 'bcc');
        if ($conn->isTableExists($tableName) != true) {

            $table = $conn->newTable($tableName)
                ->addColumn(
                    'id',
                    Table::TYPE_INTEGER,
                    null,
                    ['identity' => true, 'unsigned' => true, 'nullable' => false, 'primary' => true])
                ->addColumn(
                    'subject',
                    Table::TYPE_TEXT,
                    255,
                    ['nullable' => false, 'default' => ''])
                ->addColumn(
                    'email_content',
                    Table::TYPE_TEXT,
                    '64k',
                    [])
                ->addColumn(
                    'status',
                    Table::TYPE_SMALLINT,
                    null,
                    ['nullbale' => false])
                ->addColumn(
                    'created_at',
                    Table::TYPE_TIMESTAMP,
                    null,
                    ['nullable' => false, 'default' => Table::TIMESTAMP_INIT],
                    'Created At')
                ->addColumn(
                    'sender',
                    Table::TYPE_TEXT,
                    255,
                    ['nullbale' => false, 'default' => ''])
                ->addColumn(
                    'recipient',
                    Table::TYPE_TEXT,
                    255,
                    ['nullbale' => false, 'default' => ''])
                ->addColumn(
                    'cc',
                    Table::TYPE_TEXT,
                    255,
                    ['nullbale' => false, 'default' => ''])
                ->addColumn(
                    'bcc',
                    Table::TYPE_TEXT,
                    255,
                    ['nullbale' => false, 'default' => ''])
                ->setOption('charset', 'utf8');

                $conn->createTable($table);


            $conn->addIndex(
                $tableName,
                $setup->getIdxName($tableName, $fullTextIntex, AdapterInterface::INDEX_TYPE_FULLTEXT),
                $fullTextIntex,
                AdapterInterface::INDEX_TYPE_FULLTEXT
            );

        }
        $setup->endSetup();
    }
}
