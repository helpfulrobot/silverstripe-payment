<?php

/**
 * Payment type to support credit-card payments through DPS.
 * 	
 * @package payment
 */
class DPSPayment extends Payment
{
    public static $db = array(
        'TxnRef' => 'Varchar(1024)',
        'TxnType' => "Enum('Purchase,Auth,Complete,Refund,Validate', 'Purchase')",
        'AuthCode' => 'Varchar(22)',
        'MerchantReference' => 'Varchar(64)',
        'DPSHostedRedirectURL' => 'Text',
        
        // This field is stored for only when the payment is made thru merchant-hosted DPS gateway (PxPost);
        'SettlementDate' => 'Date',
        
        // We store the whole raw response xml in case that tracking back the payment is needed in a later stage for whatever the reason.
        'ResponseXML' => "Text",
        'CardNumberTruncated' => 'Varchar(32)', // The first six and two last digits of the CC
        'CardHolderName' => 'Varchar(255)',
        'DateExpiry' => 'Varchar(4)', // four digits (mm/yy)
        'TimeOutDate' => 'SS_Datetime'
    );

    public static $indexes = array(
        'TxnRef' => true,
    );
    
    public static $has_one = array(
        //in case that TxnType is Complete, the DPSPayment could have one Auth DPSPayment
        'AuthPayment' => 'DPSPayment',
        
        //in case that TxnType is Refund, the DPSPayment could have one Refunded DPSPayment
        'RefundedFor' => 'DPSPayment'
    );
    
    private static $input_elements = array(
        'Amount',
        'CardHolderName',
        'CardNumber',
        'BillingId',
        'Cvc2',
        'DateExpiry',
        'DpsBillingId',
        'DpsTxnRef',
        'EnableAddBillCard',
        'InputCurrency',
        'MerchantReference',
        'Opt',
        'PostUsername',
        'PostPassword',
        'TxnType',
        'TxnData1',
        'TxnData2',
        'TxnData3',
        'TxnId',
        'EnableAvsData',
        'AvsAction',
        'AvsPostCode',
        'AvsStreetAddress',
        'DateStart',
        'IssueNumber',
        'Track2',
    );
    
    private static $dpshosted_input_elements = array(
        'PxPayUserId',
        'PxPayKey',
        'AmountInput',
        'CurrencyInput',
        'EmailAddress',
        'EnableAddBillCard',
        'MerchantReference',
        'TxnData1',
        'TxnData2',
        'TxnData3',
        'TxnType',
        'TxnId',
        'UrlFail',
        'UrlSuccess',
    );
    
    public static $default_sort = "ID DESC";

    /**
     * Cached {@link SimpleXMLElement} object, containing ResponseXML. Indexed by Payment record ID.
     * @var SimpleXMLElement|null
     */
    protected $cacheResponseXML = null;

    public function getPaymentFormFields()
    {
        $adapter = $this->getDPSAdapter();
        return $adapter->getPaymentFormFields();
    }

    /**
     * Returns the required fields to add to the order form, when using this payment method. 
     */
    public function getPaymentFormRequirements()
    {
        $adapter = $this->getDPSAdapter();
        return $adapter->getPaymentFormRequirements();
    }
    
    //This function is hooked with OrderForm/E-commerce at the moment, so we need to keep it as it is.
    public function processPayment($data, $form)
    {
        $inputs['Amount'] = $this->Amount->Amount;
        $inputs['InputCurrency'] = $this->Amount->Currency;
        $inputs['TxnData1'] = $this->ID;
        $inputs['TxnType'] = 'Purchase';
        
        $inputs['CardHolderName'] = $data['CardHolderName'];
        $inputs['CardNumber'] = implode('', $data['CardNumber']);
        $inputs['DateExpiry'] = $data['DateExpiry'];
        if (self::$cvn_mode) {
            $inputs['Cvc2'] = $data['Cvc2'] ? $data['Cvc2'] : '';
        }
        
        $adapter = $this->getDPSAdapter();
        $responseFields = $adapter->doPayment($inputs);
        $adapter->ProcessResponse($this, $responseFields);

        if ($this->Status == 'Success') {
            $result = new Payment_Success();
        } else {
            $result = new Payment_Failure();
        }

        return $result;
    }
    
    public function auth($data)
    {
        if (DPSAdapter::$using_transaction) {
            DB::getConn()->transactionStart();
        }
        try {
            $this->TxnType = "Auth";
            $this->write();

            $adapter = $this->getDPSAdapter();
            $inputs = $this->prepareAuthInputs($data);
            $adapter->doPayment($inputs, $this);
            if (DPSAdapter::$using_transaction) {
                DB::getConn()->transactionEnd();
            }
        } catch (Exception $e) {
            if (DPSAdapter::$using_transaction) {
                DB::getConn()->transactionRollback();
            }
            $this->handleError($e);
        }
    }
    
    private function prepareAuthInputs($data)
    {
        //never put this loop after $inputs['AmountInput'] = $this->Amount->Amount;, since it will change it to an array.
        foreach ($data as $element => $value) {
            if (in_array($element, self::$input_elements)) {
                $inputs[$element] = $value;
            }
        }
        $inputs['TxnData1'] = $this->ID;
        $inputs['TxnType'] = $this->TxnType;
        $inputs['Amount'] = $this->Amount->Amount;
        $inputs['InputCurrency'] = $this->Amount->Currency;
        //special element
        $inputs['CardNumber'] = implode('', $data['CardNumber']);
        
        return $inputs;
    }
    
    public function complete()
    {
        if (DPSAdapter::$using_transaction) {
            DB::getConn()->transactionStart();
        }
        try {
            $auth = $this->AuthPayment();
            $this->TxnType = "Complete";
            $this->MerchantReference = "Complete: ".$auth->MerchantReference;
            $this->write();
        
            $adapter = $this->getDPSAdapter();
            $inputs = $this->prepareCompleteInputs();
            $adapter->doPayment($inputs, $this);
            if (DPSAdapter::$using_transaction) {
                DB::getConn()->transactionEnd();
            }
        } catch (Exception $e) {
            if (DPSAdapter::$using_transaction) {
                DB::getConn()->transactionRollback();
            }
            $this->handleError($e);
        }
    }
    
    private function prepareCompleteInputs()
    {
        $auth = $this->AuthPayment();
        $inputs['TxnData1'] = $this->ID;
        $inputs['TxnType'] = $this->TxnType;
        $inputs['Amount'] = $this->Amount->Amount;
        $inputs['InputCurrency'] = $this->Amount->Currency;
        $inputs['DpsTxnRef'] = $auth->TxnRef;
        //$inputs['AuthCode'] = $auth->AuthCode;
        return $inputs;
    }
    
    public function purchase($data)
    {
        if (DPSAdapter::$using_transaction) {
            DB::getConn()->transactionStart();
        }
        try {
            $this->TxnType = "Purchase";
            $this->write();
        
            $adapter = $this->getDPSAdapter();
            $inputs = $this->prepareAuthInputs($data);
            $adapter->doPayment($inputs, $this);
            if (DPSAdapter::$using_transaction) {
                DB::getConn()->transactionEnd();
            }
        } catch (Exception $e) {
            if (DPSAdapter::$using_transaction) {
                DB::getConn()->transactionRollback();
            }
            $this->handleError($e);
        }
    }
    
    public function refund()
    {
        if (DPSAdapter::$using_transaction) {
            DB::getConn()->transactionStart();
        }
        try {
            $refunded = $this->RefundedFor();
            $this->TxnType = "Refund";
            $this->MerchantReference = "Refund for: ".$refunded->MerchantReference;
            $this->write();

            $adapter = $this->getDPSAdapter();
            $inputs = $this->prepareRefundInputs();
            $adapter->doPayment($inputs, $this);
            if (DPSAdapter::$using_transaction) {
                DB::getConn()->transactionEnd();
            }
        } catch (Exception $e) {
            if (DPSAdapter::$using_transaction) {
                DB::getConn()->transactionRollback();
            }
            $this->handleError($e);
            return false;
        }
        
        return true;
    }
    
    private function prepareRefundInputs()
    {
        $refundedFor = $this->RefundedFor();
        $inputs['TxnData1'] = $this->ID;
        $inputs['TxnType'] = $this->TxnType;
        $inputs['Amount'] = $this->Amount->Amount;
        $inputs['InputCurrency'] = $this->Amount->Currency;
        $inputs['DpsTxnRef'] = $refundedFor->TxnRef;
        $inputs['MerchantReference'] = $this->MerchantReference;
        return $inputs;
    }
    
    public function dpshostedPurchase($data = array())
    {
        if (DPSAdapter::$using_transaction) {
            DB::getConn()->transactionStart();
        }
        try {
            $this->TxnType = "Purchase";
            $this->write();
            $adapter = $this->getDPSAdapter();
            $inputs = $this->prepareDPSHostedRequest($data);
            
            return $adapter->doDPSHostedPayment($inputs, $this);
        } catch (Exception $e) {
            if (DPSAdapter::$using_transaction) {
                DB::getConn()->transactionRollback();
            }
            $this->handleError($e);
        }
    }

    public function prepareDPSHostedRequest($data = array())
    {
        //never put this loop after $inputs['AmountInput'] = $amount, since it will change it to an array.
        foreach ($data as $element => $value) {
            if (in_array($element, self::$dpshosted_input_elements)) {
                $inputs[$element] = $value;
            }
        }

        $inputs['TxnData1'] = $this->ID;
        $inputs['TxnType'] = $this->TxnType;
        $amount = (float) ltrim($this->Amount->Amount, '$');
        $inputs['AmountInput'] = $amount;
        $inputs['InputCurrency'] = $this->Amount->Currency;
        $inputs['MerchantReference'] = $this->MerchantReference;
        if (isset($this->TimeOutDate)) {
            $inputs['Opt'] = $this->TimeOutDate;
        }

        $postProcess_url = Director::absoluteBaseURL() ."DPSAdapter/processDPSHostedResponse";
        $inputs['UrlFail'] = $postProcess_url;
        $inputs['UrlSuccess'] = $postProcess_url;

        return $inputs;
    }

    public function payAsRecurring()
    {
        $adapter = $this->getDPSAdapter();
        $inputs = $this->prepareAsRecurringPaymentInputs();
        $adapter->doPayment($inputs, $this);
    }
    
    public function prepareAsRecurringPaymentInputs()
    {
        $reccurringPayment = DataObject::get_by_id('DPSRecurringPayment', $this->RecurringPaymentID);
        $inputs['DpsBillingId'] = $reccurringPayment->DPSBillingID;
        $inputs['TxnData1'] = $this->ID;
        $inputs['TxnType'] = 'Purchase';
        $amount = (float) ltrim($reccurringPayment->Amount->Amount, '$');
        $inputs['Amount'] = $amount;
        $inputs['InputCurrency'] = $reccurringPayment->Amount->Currency;
        $inputs['MerchantReference'] = $reccurringPayment->MerchantReference;
        
        return $inputs;
    }
    
    public function CanComplete()
    {
        $successComplete = $this->successCompletePayment();
        return !($successComplete && $successComplete->ID) && $this->TxnType == 'Auth' && $this->Status = 'Success';
    }
    
    public function successCompletePayment()
    {
        return DataObject::get_one(
            "DPSPayment",
            "\"Status\" = 'Success' AND \"TxnType\" = 'Complete' AND \"AuthPaymentID\" = '".(int)$this->ID."'"
        );
    }
    
    
    public function onAfterWrite()
    {
        if ($this->isChanged('Status') && $this->Status == 'Success') {
            $this->sendReceipt();
        }
        parent::onAfterWrite();
    }
    
    public function sendReceipt()
    {
        $member = $this->PaidBy();
        if ($member->exists() && $member->Email) {
            $from = DPSAdapter::get_receipt_from();
            if ($from) {
                $body =  $this->renderWith($this->ClassName."_receipt");
                $body .= $member->ReceiptMessage();
                $email = new Email($from, $member->Email, "Payment receipt (Ref no. #".$this->ID.")", $body);
                $email->send();
            }
        }
    }

    protected function parsedResponseXML($cached = true)
    {
        if (!$this->cacheResponseXML || !$cached) {
            $this->cacheResponseXML = simplexml_load_string($this->ResponseXML, 'SimpleXMLElement', LIBXML_NOWARNING);
        }
        return $this->cacheResponseXML;
    }

    /**
     * From the ResponseXML, retrieve the AmountSettlement value.
     * CAUTION: Only works for transactions created through PXPay, not PXPost!
     * 
     * @return bool|string
     */
    public function getAmountSettlement()
    {
        $xml = $this->parsedResponseXML();
        return ($xml) ? (string) $xml->AmountSettlement : false;
    }

    /**
     * From the ResponseXML, retrieve the CardName value.
     * @return bool|string
     */
    public function getCardName()
    {
        $xml = $this->parsedResponseXML();
        if (!$xml) {
            return false;
        }
        return ($xml->Transaction) ? (string)$xml->Transaction->CardName : (string)$xml->CardName;
    }

    /**
     * From the ResponseXML, retrieve the CardHolderName value.
     * @return bool|string
     */
    public function getCardHolderName()
    {
        $xml = $this->parsedResponseXML();
        if (!$xml) {
            return false;
        }
        return ($xml->Transaction) ? (string)$xml->Transaction->CardHolderName : (string)$xml->CardHolderName;
    }

    /**
     * From the ResponseXML, retrieve the DateExpiry value.
     * @return bool|string
     */
    public function getDateExpiry()
    {
        $xml = $this->parsedResponseXML();
        if (!$xml) {
            return false;
        }
        return ($xml->Transaction) ? (string)$xml->Transaction->DateExpiry : (string)$xml->DateExpiry;
    }

    /**
     * From the ResponseXML, retrieve the CardNumber value.
     * @return bool|string
     */
    public function getCardNumber()
    {
        $xml = $this->parsedResponseXML();
        if (!$xml) {
            return false;
        }
        return ($xml->Transaction) ? (string)$xml->Transaction->CardNumber : (string)$xml->CardNumber;
    }
    
    protected $dpsAdapter;
    
    public function getDPSAdapter()
    {
        if (!$this->dpsAdapter) {
            $this->dpsAdapter = new DPSAdapter();
        }
        return $this->dpsAdapter;
    }
    
    public function setDPSAdapter($adapter)
    {
        $this->dpsAdapter = $adapter;
    }
}
