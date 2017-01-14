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

use Lyra\Innopay\Helper\Data;
use Lyra\Innopay\Helper\Payment;
use Lyra\Innopay\Model\Api\InnopayApi;
use Lyra\Innopay\Model\Api\Ws\cancelPayment;
use Lyra\Innopay\Model\Api\Ws\commonRequest;
use Lyra\Innopay\Model\Api\Ws\duplicatePayment;
use Lyra\Innopay\Model\Api\Ws\getPaymentDetails;
use Lyra\Innopay\Model\Api\Ws\orderRequest;
use Lyra\Innopay\Model\Api\Ws\paymentRequest;
use Lyra\Innopay\Model\Api\Ws\queryRequest;
use Lyra\Innopay\Model\Api\Ws\wsApi;

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

            $this->wsApi = new wsApi(['sni.enabled' => null]);
            $this->wsApi->init($shopId, $mode, $keyTest, $keyProd);
        }

        return $this->wsApi;
    }

    /**
     * @param MagentoOrder $order
     * @param array $expectedStatuses
     * @return \Lyra\Innopay\Model\Api\Ws\getPaymentDetailsResult
     */
    public function getPaymentDetails(MagentoOrder $order, $expectedStatuses = array())
    {
        $this->initWsApi($order->getStoreId());

        /* @var $payment \Magento\Sales\Model\Order\Payment */
        $payment = $order->getPayment();
        $uuid = $payment->getAdditionalInformation(Payment::TRANS_UUID);

        $queryRequest = new queryRequest();
        $queryRequest->setUuid($uuid);

        $getPaymentDetails = new getPaymentDetails();
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
     * @return \Lyra\Innopay\Model\Api\Ws\cancelPaymentResponse
     */
    public function cancelTransaction(MagentoOrder $order, $cancelMessage = null)
    {
        /* @var $payment \Magento\Sales\Model\Order\Payment */
        $payment = $order->getPayment();

        $this->initWsApi($order->getStoreId());

        $uuid = $payment->getAdditionalInformation(Payment::TRANS_UUID);

        $queryRequest = new queryRequest();
        $queryRequest->setUuid($uuid);

        $commonRequest = new commonRequest();
        if ($cancelMessage) {
            $commonRequest->setComment($cancelMessage);
        }

        $cancelPayment = new cancelPayment();
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
     * @return \Lyra\Innopay\Model\Api\Ws\duplicatePaymentResult
     * @throws \Exception
     */
    public function duplicateTransaction(MagentoOrder $order, $amountInCents)
    {
        /* @var $payment \Magento\Sales\Model\Order\Payment */
        $payment = $order->getPayment();
        $storeId = $order->getStoreId();
        $incrementId = $order->getIncrementId();
        $currency = InnopayApi::findCurrencyByAlphaCode($order->getOrderCurrencyCode());

        /* @var $paymentMethodInstance \Lyra\Innopay\Model\Method\Innopay */
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

        $queryRequest = new queryRequest();
        $queryRequest->setUuid($uuid);

        $commonRequest = new commonRequest();

        $paymentRequest = new paymentRequest();
        $paymentRequest->setTransactionId(InnopayApi::generateTransId($timestamp));
        $paymentRequest->setAmount($amountInCents);
        $paymentRequest->setCurrency($currency->getNum());

        if (is_numeric($captureDelay)) {
            $paymentRequest->setExpectedCaptureDate(new \DateTime('@' . strtotime("+$captureDelay days", $timestamp)));
        }

        if ($validationMode !== '') {
            $paymentRequest->setManualValidation($validationMode);
        }

        $orderRequest = new orderRequest();
        $orderRequest->setOrderId($incrementId);

        $duplicatePayment = new duplicatePayment();
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
        $this->wsApi->checkResult(
            $getDuplicateCommonResponse,
            ['INITIAL', 'NOT_CREATED', 'AUTHORISED', 'AUTHORISED_TO_VALIDATE', 'WAITING_AUTHORISATION', 'WAITING_AUTHORISATION_TO_VALIDATE', 'REFUSED']
        );

        // check operation type (0: debit, 1 refund)
        $transType = $getDuplicatePaymentResponse->getOperationType();
        if ($transType != 0) {
            throw new \Exception("Unexpected transaction type returned ($transType).");
        }

        return $getDuplicatePaymentResult;
    }
}