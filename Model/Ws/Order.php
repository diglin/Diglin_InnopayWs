<?php
/**
 * Diglin GmbH - Switzerland
 *
 * @author      Sylvain Rayé <support at diglin.com>
 * @category    Diglin
 * @package     Diglin_InnopayWs
 * @copyright   Copyright (c) 2011-2016 Diglin (http://www.diglin.com)
 */

namespace Diglin\InnopayWs\Model\Ws;

use Lyranetwork\Innopay\Helper\Data;
use Lyranetwork\Innopay\Helper\Payment;
use Lyranetwork\Innopay\Model\Api\InnopayApi;
use Lyranetwork\Innopay\Model\Api\Ws\CancelPayment;
use Lyranetwork\Innopay\Model\Api\Ws\CommonRequest;
use Lyranetwork\Innopay\Model\Api\Ws\DuplicatePayment;
use Lyranetwork\Innopay\Model\Api\Ws\GetPaymentDetails;
use Lyranetwork\Innopay\Model\Api\Ws\OrderRequest;
use Lyranetwork\Innopay\Model\Api\Ws\PaymentRequest;
use Lyranetwork\Innopay\Model\Api\Ws\QueryRequest;
use Lyranetwork\Innopay\Model\Api\Ws\ResultException;
use Lyranetwork\Innopay\Model\Api\Ws\WsApi;

use Magento\Sales\Model\Order as MagentoOrder;

/**
 * Class Order
 * @package Diglin\InnopayWs\Model\Ws
 */
class Order
{
    /**
     * @var wsApi
     */
    protected $wsApi;

    /**
     * Order constructor.
     * @param Data $lyraHelperData
     * @param Payment $lyraHelperPayment
     */
    public function __construct(
        Data $lyraHelperData,
        Payment $lyraHelperPayment
    )
    {
        $this->lyraHelperData = $lyraHelperData;
        $this->lyraHelperPayment = $lyraHelperPayment;
    }

    /**
     * @param null $storeId
     * @return wsApi
     */
    private function initWsApi($storeId = null)
    {
        if (!$this->wsApi) {
            $shopId = $this->lyraHelperData->getCommonConfigData('site_id', $storeId);
            $mode = $this->lyraHelperData->getCommonConfigData('ctx_mode', $storeId);
            $keyTest = $this->lyraHelperData->getCommonConfigData('key_test', $storeId);
            $keyProd = $this->lyraHelperData->getCommonConfigData('key_prod', $storeId);

            $this->wsApi = new WsApi(['sni.enabled' => null]);
            $this->wsApi->init($shopId, $mode, $keyTest, $keyProd);
        }

        return $this->wsApi;
    }

    /**
     * @param MagentoOrder $order
     * @param array $expectedStatuses
     * @return \Lyranetwork\Innopay\Model\Api\Ws\getPaymentDetailsResult
     */
    public function getPaymentDetails(MagentoOrder $order, $expectedStatuses = array())
    {
        $this->initWsApi($order->getStoreId());

        /* @var $payment \Magento\Sales\Model\Order\Payment */
        $payment = $order->getPayment();
        $uuid = $payment->getAdditionalInformation(Payment::TRANS_UUID);

        $queryRequest = new QueryRequest();
        $queryRequest->setUuid($uuid);

        $getPaymentDetails = new GetPaymentDetails();
        $getPaymentDetails->setQueryRequest($queryRequest);

        $this->wsApi->setHeaders();
        $getPaymentDetailsResponse = $this->wsApi->getPaymentDetails($getPaymentDetails);

        $getGetPaymentDetailsCommonResponse = $getPaymentDetailsResponse->getGetPaymentDetailsResult()->getCommonResponse();

        $this->wsApi->checkAuthenticity();
        $this->wsApi->checkResult($getGetPaymentDetailsCommonResponse, $expectedStatuses);

        return $getPaymentDetailsResponse->getGetPaymentDetailsResult();
    }

    /**
     * @param MagentoOrder $order
     * @param null $cancelMessage
     * @return \Lyranetwork\Innopay\Model\Api\Ws\cancelPaymentResponse
     */
    public function cancelTransaction(MagentoOrder $order, $cancelMessage = null)
    {
        /* @var $payment \Magento\Sales\Model\Order\Payment */
        $payment = $order->getPayment();

        $this->initWsApi($order->getStoreId());

        $uuid = $payment->getAdditionalInformation(Payment::TRANS_UUID);

        $queryRequest = new QueryRequest();
        $queryRequest->setUuid($uuid);

        $commonRequest = new CommonRequest();
        if ($cancelMessage) {
            $commonRequest->setComment($cancelMessage);
        }

        $cancelPayment = new CancelPayment();
        $cancelPayment->setCommonRequest($commonRequest);
        $cancelPayment->setQueryRequest($queryRequest);

        // Session ID to be reused in further calls
        try {
            $sid = $this->wsApi->getJsessionId();
            if ($sid) {
                $this->wsApi->setJsessionId($sid);
            }
        } catch (\Exception $e) {
        }

        $this->wsApi->setHeaders();
        $cancelPaymentResponse = $this->wsApi->cancelPayment($cancelPayment);

        $this->wsApi->checkAuthenticity();
        $this->wsApi->checkResult($cancelPaymentResponse->getCancelPaymentResult()->getCommonResponse(), ['CANCELLED']);

        return $cancelPaymentResponse;
    }

    /**
     * @param MagentoOrder $order
     * @return \Lyranetwork\Innopay\Model\Api\Ws\duplicatePaymentResult
     * @throws \Exception
     */
    public function duplicateTransaction(MagentoOrder $order, $amountInCents)
    {
        /* @var $payment \Magento\Sales\Model\Order\Payment */
        $payment = $order->getPayment();
        $storeId = $order->getStoreId();
        $incrementId = $order->getIncrementId();
        $currency = InnopayApi::findCurrencyByAlphaCode($order->getOrderCurrencyCode());

        /* @var $paymentMethodInstance \Lyranetwork\Innopay\Model\Method\Innopay */
        $paymentMethodInstance = $payment->getMethodInstance();

        // Get sub-module payment specific param
        $captureDelay = $paymentMethodInstance->getConfigData('capture_delay', $storeId);
        $validationMode = $paymentMethodInstance->getConfigData('validation_mode', $storeId);

        // Get general param
        if (!is_numeric($captureDelay)) {
            $captureDelay = $this->lyraHelperData->getCommonConfigData('capture_delay', $storeId);
        }

        if ($validationMode === '-1') {
            $validationMode = $this->lyraHelperData->getCommonConfigData('validation_mode', $storeId);
        }

        $timestamp = time();

        $this->initWsApi($storeId);

        $uuid = $payment->getAdditionalInformation(Payment::TRANS_UUID);

        $queryRequest = new QueryRequest();
        $queryRequest->setUuid($uuid);

        $commonRequest = new CommonRequest();

        $paymentRequest = new PaymentRequest();
        $paymentRequest->setTransactionId(InnopayApi::generateTransId($timestamp));
        $paymentRequest->setAmount($amountInCents);
        $paymentRequest->setCurrency($currency->getNum());

        if (is_numeric($captureDelay)) {
            $paymentRequest->setExpectedCaptureDate(new \DateTime('@' . strtotime("+$captureDelay days", $timestamp)));
        }

        if ($validationMode !== '') {
            $paymentRequest->setManualValidation($validationMode);
        }

        $orderRequest = new OrderRequest();
        $orderRequest->setOrderId($incrementId);

        $duplicatePayment = new DuplicatePayment();
        $duplicatePayment->setCommonRequest($commonRequest);
        $duplicatePayment->setPaymentRequest($paymentRequest);
        $duplicatePayment->setOrderRequest($orderRequest);
        $duplicatePayment->setQueryRequest($queryRequest);

        // Session ID to be reused in further calls
        try {
            $sid = $this->wsApi->getJsessionId();
            if ($sid) {
                $this->wsApi->setJsessionId($sid);
            }
        } catch (\Exception $e) {}

        $this->wsApi->setHeaders();
        $duplicatePaymentResponse = $this->wsApi->duplicatePayment($duplicatePayment);

        $getDuplicatePaymentResult = $duplicatePaymentResponse->getDuplicatePaymentResult();

        $getDuplicatePaymentResponse = $getDuplicatePaymentResult->getPaymentResponse();
        $getDuplicateCommonResponse = $getDuplicatePaymentResult->getCommonResponse();

        $this->wsApi->checkAuthenticity();

        try {
            $this->wsApi->checkResult(
                $getDuplicateCommonResponse,
                // Let REFUSED commented, we want to trigger an Exception if the transaction is refused on Innopay side to block any further transaction
                ['INITIAL', 'NOT_CREATED', 'AUTHORISED', 'AUTHORISED_TO_VALIDATE', 'WAITING_AUTHORISATION', 'WAITING_AUTHORISATION_TO_VALIDATE' /*, 'REFUSED'*/]
            );
        } catch (ResultException $e) {
            throw new \Exception('The transaction on Innopay has been refused or the transaction status is not expected');
        }

        // check operation type (0: debit, 1 refund)
        $transType = $getDuplicatePaymentResponse->getOperationType();
        if ($transType != 0) {
            throw new \Exception("Unexpected transaction type returned ($transType).");
        }

        return $getDuplicatePaymentResult;
    }
}