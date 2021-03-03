<?php
require_once 'abstract.php';


class Backorder extends Mage_Shell_Abstract
{
    private $table = "ezra_out_of_stock_notification";

    private $db;

    private $data = [];
    private $fileData = [];

    public function run()
    {
        $csvFiles = $this->getFileNames();
        foreach ($csvFiles as $csvFile) {

            $this->fileData = $this->getFileData($csvFile);
            foreach ($this->fileData as $oneRow) {
                $this->data = array_merge($this->data, $oneRow);
                $this->data['product_id'] = $this->getProductId($this->data['stock_code']);
                $this->data['product_url'] = $this->getProductUrl($this->data['stock_code'], $this->data['customer']);
                $this->data['product_name'] = $this->getProductName($this->data['stock_code']);
                $this->data['user_email'] = $this->getUserEmail($this->data['user_email']);
                $this->data['user_name'] = $this->getUserName();
                $this->data['add_time'] = $this->getDate();
                $this->data['last_send_time'] = $this->getLastSendTime();
                $this->data['status'] = $this->getStatus();
                $this->data['need_send_time'] = $this->getNeedSendTime();

                var_dump($this->data);
                $response = $this->insertData($this->data);
                if($response) {
                    echo "\n" . $oneRow['stock_code'] . "was entered to database successfully\n";
                }
            }
            $this->moveFile($csvFile);
        }
    }

    private function connection()
    {
        $__config = new Zend_Config_Xml('app/etc/local.xml');
        $__dbNode = $__config->global->resources->db;
        $__dbPrefix = $__config->global->resources->db->table_prefix;
        $__connectionNode = $__config->global->resources->default_setup->connection;
        $__db = Zend_Db::factory(
            'Pdo_Mysql',
            array(
                'host' => $__connectionNode->host,
                'username' => $__connectionNode->username,
                'password' => $__connectionNode->password,
                'dbname' => $__connectionNode->dbname
            )
        );

        return $__db;
    }

    /** return execution status */
    private function insertData($data)
    {
        $__db = $this->connection();
        $__config = new Zend_Config_Xml('app/etc/local.xml');
        $__dbPrefix = $__config->global->resources->db->table_prefix;

        if ($__db) {
            try {

                $__db->query("INSERT INTO " . $__dbPrefix.$this->table . "
                (product_id, product_url, product_name, user_email,
                user_name, add_time, last_send_time, status, need_send_time)
                VALUES (" . $data['product_id'] . "," . "'" . $data['product_url'] . "'"
                    . "," . "'" . $data['product_name'] . "'" . "," . "'" . $data['user_email'] . "'"
                    . "," . "'" . $data['user_name'] . "'" . "," . "'" . $data['add_time'] . "'"
                    . "," . "null" . ","  . $data['status'] . "," . "null" .")");

                echo "New record created successfully";
                return true;
            } catch (PDOException $e) {
                echo $e->getMessage();
                return false;
            }
        }

    }

    private function getFileNames()
    {
        return glob("var/backorder/*.csv");
    }

    private function parseFile($csvFile)
    {
        $row = 0;
        $data = [];
        if (($handle = fopen($csvFile, 'r')) !== false) {
            while (($csvData = fgetcsv($handle)) !== false) {
                // removing csv column names
                if ($row !== 0) {
                    $data[$row]['stock_code'] = $csvData[0];
                    $data[$row]['user_email'] = $csvData[1];
                    $data[$row]['customer'] = $csvData[2];
                    $data[$row]['order_id'] = $csvData[3];
                }
                $row++;
            }
            fclose($handle);
        }

        return $data;
    }

    private function moveFile($csvFile)
    {
        $fileName = str_replace("var/backorder", "", $csvFile);
        rename("var/backorder/" . $fileName, "var/backorder/archive" . $fileName);
    }

    private function sendEmail($templateId, $recipientEmail, $recipientName, $vars)
    {
        $emailTemplate = Mage::getModel('core/email_template');
        $emailTemplate->loadByCode($templateId);

        $emailTemplateVariables = array();
        $emailTemplateVariables['stock_code'] = $vars['stock_code'];
        $emailTemplateVariables['email'] = $vars['email'];
        $emailTemplateVariables['customer'] = $vars['customer'];
        $emailTemplateVariables['order_id'] = $vars['order_id'];

        $senderName = Mage::getStoreConfig('trans_email/ident_general/name');
        $senderEmail = Mage::getStoreConfig('trans_email/ident_general/email');

        $emailTemplate->setSenderName($senderName);
        $emailTemplate->setSenderEmail($senderEmail);

        $emailTemplate->send($recipientEmail, $recipientName, $emailTemplateVariables);
    }

    private function validateEmail($email, $fileData)
    {
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $email;
        } else {
            $templateId = "backorder-email-validation";
            $recipientEmail = "websupport@starwest-botanicals.com";
            $recipientName = "Sam Ly";
            $vars = array(
                "stock_code" => $fileData['stock_code'],
                "email" => $fileData['user_email'],
                "customer" => $fileData['customer'],
                "order_id" => $fileData["order_id"]
            );
            $this->sendEmail($templateId, $recipientEmail, $recipientName, $vars);
        }

        return 'invalid email';
    }

    protected function getFileData($csvFile)
    {
        return $this->parseFile($csvFile);
    }

    protected function getProductId($sku)
    {
        return Mage::getModel('catalog/product')->getIdBySku($sku);
    }

    protected function getProductUrl($sku, $customerId)
    {
        $_product = Mage::getModel('catalog/product')->loadByAttribute('sku', $sku);

        // Functionality to check if product has associated product (we need to check if it
        // is a part of a grouped product. If it fails, then default value is returned
        if ($_product->getTypeId() != 'grouped') // check if the product is grouped product
        {
            $allGroupedProducts = Mage::getModel('catalog/product')
                ->getCollection()
                ->addAttributeToFilter('type_id', array('eq' => 'grouped'));

            foreach ($allGroupedProducts as $singleGroupedProduct) {
                $associatedProducts[] = $singleGroupedProduct->getTypeInstance(true)->getAssociatedProducts($singleGroupedProduct);

                foreach ($associatedProducts as $singleProduct) {
                    foreach ($singleProduct as $product) {
                        if($product->getSku() == $sku) {
                            $_product = $product;
                            break;
                        }
                    }
                }
            }
        }

        if ($this->getCustomerGroupId($customerId) == 2) {
            $domain = "https://wholesale.starwest-botanicals.com/";
        } else /*if ($this->getCustomerGroupId($customerId) == 4)*/ {
            $domain = "https://www.starwest-botanicals.com/";
        }

        return $domain . $_product->getUrlPath();
    }

    protected function getCustomerGroupId($customerId)
    {
        $customer = Mage::getModel('customer/customer')->load($customerId);
        return $customer->getGroupId();
    }

    protected function getProductName($sku)
    {
        $_product = Mage::getModel('catalog/product')->loadByAttribute('sku', $sku);
        return $_product->getName();
    }

    protected function getUserEmail($email) {

        if ($this->validateEmail($email, $this->fileData)) {
            return $email;
        } else {
            return 'Invalid email';
        }
    }

    protected function getUserName()
    {
        return '';
    }

    protected function getDate()
    {
        $date = new DateTime("now", new DateTimeZone('America/Los_Angeles'));
        return $date->format('Y-m-d H:i:s');
    }

    protected function getLastSendTime()
    {
        return null;
    }

    protected function getStatus()
    {
        return 0;
    }

    protected function getNeedSendTime()
    {
        return null;
    }
}

$shell = new Backorder();
$shell->run();