<?php
/**
 *
 * Adyen Payment module (https://www.adyen.com/)
 *
 * Copyright (c) 2025 Adyen N.V. (https://www.adyen.com/)
 * See LICENSE.txt for license details.
 *
 * Author: Adyen <magento@adyen.com>
 */

namespace Adyen\Payment\Gateway\Http\Client;

use Adyen\AdyenException;
use Adyen\Client;
use Adyen\Model\Checkout\PaymentCancelRequest;
use Adyen\Payment\Helper\Data;
use Adyen\Payment\Helper\Idempotency;
use Adyen\Payment\Helper\PlatformInfo;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Payment\Gateway\Http\ClientInterface;
use Magento\Payment\Gateway\Http\TransferInterface;

class TransactionCancel implements ClientInterface
{
    /**
     * @param Data $adyenHelper
     * @param Idempotency $idempotencyHelper
     * @param PlatformInfo $platformInfo
     */
    public function __construct(
        private readonly Data $adyenHelper,
        private readonly Idempotency $idempotencyHelper,
        private readonly PlatformInfo $platformInfo
    ) { }

    /**
     * @param TransferInterface $transferObject
     * @return array
     * @throws AdyenException
     * @throws NoSuchEntityException
     */
    public function placeRequest(TransferInterface $transferObject): array
    {
        $requests = $transferObject->getBody();
        $headers = $transferObject->getHeaders();
        $clientConfig = $transferObject->getClientConfig();

        $client = $this->adyenHelper->initializeAdyenClientWithClientConfig($clientConfig);
        $service = $this->adyenHelper->initializeModificationsApi($client);
        $responseCollection = [];

        foreach ($requests as $request) {
            $idempotencyKey = $this->idempotencyHelper->generateIdempotencyKey(
                $request,
                $headers['idempotencyExtraData'] ?? null
            );
            $requestOptions['idempotencyKey'] = $idempotencyKey;
            $requestOptions['headers'] = $headers;
            $this->adyenHelper->logRequest($request, Client::API_CHECKOUT_VERSION, '/cancels');
            $request['applicationInfo'] = $this->platformInfo->buildApplicationInfo($client);
            $paymentCancelRequest = new PaymentCancelRequest($request);

            try {
                $response = $service->cancelAuthorisedPaymentByPspReference(
                    $request['paymentPspReference'],
                    $paymentCancelRequest,
                    $requestOptions
                );
                $responseData = $response->toArray();
                $this->adyenHelper->logResponse($responseData);
            } catch (AdyenException $e) {
                $responseData['error'] = $e->getMessage();
                $this->adyenHelper->logAdyenException($e);
            }

            $responseCollection[] = $responseData;
        }

        return $responseCollection;
    }
}
