<?php

class EMS_Pay_Model_Ipn
{
    /**
     * @var EMS_Pay_Model_Response
     */
    protected $_response;

    /**
     * @var Mage_Sales_Model_Order
     */
    protected $_order;

    /**
     * @var EMS_Pay_Helper_Data
     */
    protected $_helper;

    /**
     * @var EMS_Pay_Model_Config
     */
    protected $_config;

    /**
     * Collected debug information
     *
     * @var array
     */
    protected $_debugData = array();

    public function __construct()
    {
        $this->_helper = Mage::helper('ems_pay');
        $this->_config = Mage::getModel('ems_pay/config');
    }

    /**
     * Get ipn notification data, verify request, process order
     *
     * @param array $requestParams
     * @throws Exception
     */
    public function processIpnRequest(array $requestParams)
    {
        $this->_debugData[] = $this->_helper->__('Processing IPN request');
        $this->_debugData['ipn_params'] = $requestParams;
        $this->_response = Mage::getModel('ems_pay/response', $requestParams);

        try {
            $this->_order = null;
            $this->_initOrder();

            $this->_response->validate($this->_order->getPayment()->getMethodInstance());
            $this->_processOrder();

        } catch (Exception $ex) {
            $this->_debugData['exception'] = $this->_formatExceptionForBeingLogged($ex);
            $this->_debug();
            Mage::logException($ex);

            throw $ex;
        }

        $this->_debugData['success'] = $this->_helper->__('IPN request processed');
        $this->_debug();
    }

    /**
     * IPN workflow implementation. Runs corresponding response handler depending on status
     *
     * @throws Exception
     */
    protected function _processOrder()
    {
        try {
            switch ($this->_response->getTransactionStatus()) {
                case EMS_Pay_Model_Response::STATUS_SUCCESS:
                    $this->_registerSuccess(true);
                    break;
                case EMS_Pay_Model_Response::STATUS_WAITING:
                    $this->_registerPaymentReview();
                    break;
                case EMS_Pay_Model_Response::STATUS_FAILURE:
                    $this->_registerFailure();
                    break;
            }
        } catch (Exception $ex) {
            $comment = $this->_createIpnComment($this->_helper->__('Note: %s', $ex->getMessage()));
            $this->_order->addStatusHistoryComment($comment);
            $this->_order->save();
            throw $ex;
        }

    }

    /**
     * Processes successful payment
     *
     * @param bool $skipFraudDetection
     */
    protected function _registerSuccess($skipFraudDetection = false)
    {
        $response = $this->_response;
        $this->_importPaymentInformation();
        $payment = $this->_order->getPayment();
        $payment->setTransactionId($response->getTransactionId());
        $payment->setCurrencyCode($response->getTextCurrencyCode());
        $payment->setPreparedMessage($this->_createIpnComment(''));
        $payment->setIsTransactionClosed(0);

        $payment->getMethodInstance()->addTransactionData($this->_response);

        $payment->registerCaptureNotification(
            $response->getChargeTotal(),
            $skipFraudDetection
        );

        $this->_order->save();

        $version = Mage::getVersion();
        if (version_compare($version, '1.9', '>=')){
            $this->_order->queueNewOrderEmail()
                ->setIsCustomerNotified(true)
                ->save();
        } else {
            $this->_order->sendNewOrderEmail()
                ->setIsCustomerNotified(true)
                ->save();
        }

        /** @var EMS_Pay_Model_InvoiceMailer $invoiceMailer */
        $invoiceMailer = Mage::getModel('ems_pay/invoiceMailer');
        $invoiceMailer->setOrder($this->_order);

        $ids = array();
        $invoices = $this->_order->getInvoiceCollection();
        foreach($invoices as $invoice) {
            if ($invoice) {
                $ids[] = $invoice->getIncrementId();
                $invoiceMailer->setInvoice($invoice);
                $invoiceMailer->sendToQueue();
            }
        }
        $multi = count($ids)>1 ? 's' : '';
        $message = $this->_helper->__('Notified customer about invoice'.$multi.': #%s.', implode(', ', $ids));
        $this->_order->addStatusHistoryComment($message)->save();
    }

    /**
     * Processes failed payment
     */
    protected function _registerFailure()
    {
        $this->_importPaymentInformation();
        $this->_order
            ->registerCancellation($this->_createIpnComment(''), false)
            ->save();
    }

    /**
     * Processes pending payment notification
     */
    protected function _registerPaymentReview()
    {
        $response = $this->_response;
        $this->_importPaymentInformation();
        /** @var Mage_Sales_Model_Order_Payment $payment */
        $payment = $this->_order->getPayment();
        $payment->setTransactionId($response->getTransactionId())
            ->setCurrencyCode($response->getTextCurrencyCode())
            ->setPreparedMessage($this->_createIpnComment(''))
            ->setIsTransactionClosed(0);
        $payment->getMethodInstance()->addTransactionData($this->_response);

        $message = '';
        if ($payment->getMethod() == EMS_Pay_Model_Config::METHOD_KLARNA) {
            $message = $this->_helper->__('Please visit the EMS virtual terminal to approve the payment for Klarna.');
        }
        $this->_order
            ->setState(Mage_Sales_Model_Order::STATE_PAYMENT_REVIEW, true, $this->_createIpnComment($message))
            ->save();
    }

    /**
     * Initializes order object based on data from transaction response
     *
     * @return Mage_Sales_Model_Order
     * @throws Exception
     */
    protected function _initOrder()
    {
        $orderId = $this->_response->getOrderId();
        $this->_order = Mage::getModel('sales/order')->loadByIncrementId($orderId);
        if (!$this->_order->getId()) {
            $message = $this->_helper->__("Order for id %s not found", $orderId);
            $this->_debugData['exception'] = $message;
            $this->_debug();
            Mage::throwException($message);
        }

        //reinitialize config with method code and store id taken from order
        $methodCode = $this->_order->getPayment()->getMethod();
        $this->_config = Mage::getModel('ems_pay/config', array($methodCode, $this->_order->getStoreId()));

        return $this->_order;
    }

    /**
     * @param string $comment
     * @return string
     */
    protected function _createIpnComment($comment = '')
    {
        $status = $this->_response->getTransactionStatus();
        $message = $this->_helper->__('IPN "%s", approval code "%s".', $status, $this->_response->getApprovalCode());
        if ($this->_response->getFailReason()) {
            $message .= ' ' . $this->_response->getFailReason();
        }
        if ($comment) {
            $message .= ' ' . $comment;
        }

        return $message;
    }

    /**
     * Map payment information from transaction response to payment object
     * Returns true if there were changes in information
     *
     * @return bool
     */
    protected function _importPaymentInformation()
    {
        $payment = $this->_order->getPayment();
        $currentInfo = $payment->getAdditionalInformation();
        $data = array(
            EMS_Pay_Model_Info::TRANSACTION_ID => $this->_response->getTransactionId(),
            EMS_Pay_Model_Info::APPROVAL_CODE => $this->_response->getApprovalCode(),
            EMS_Pay_Model_Info::REFNUMBER => $this->_response->getRefNumber(),
            EMS_Pay_Model_Info::IPG_TRANSACTION_ID => $this->_response->getIpgTransactionId(),
            EMS_Pay_Model_Info::ENDPOINT_TRANSACTION_ID => $this->_response->getEndpointTransactionId(),
            EMS_Pay_Model_Info::PROCESSOR_RESPONSE_CODE => $this->_response->getProcessorResponseCode(),
        );

        foreach ($data as $key => $value) {
            $payment->setAdditionalInformation($key, $value);
        }

        return $currentInfo != $data;
    }

    /**
     * Log debug data to file
     */
    protected function _debug()
    {
        if ($this->_config && $this->_config->isDebuggingEnabled()) {
            Mage::getModel('core/log_adapter', $this->_config->getLogFile())->log($this->_debugData);
        }
    }

    /**
     * Formats exception into text message that can be logged
     *
     * @param Exception $ex
     * @return string
     */
    protected function _formatExceptionForBeingLogged(Exception $ex)
    {
        return $ex->getMessage() . ' in ' . $ex->getFile() . ':' . $ex->getLine();
    }
}
