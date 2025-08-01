<?php
/**
 *
 * Adyen Payment module (https://www.adyen.com/)
 *
 * Copyright (c) 2022 Adyen BV (https://www.adyen.com/)
 * See LICENSE.txt for license details.
 *
 * Author: Adyen <magento@adyen.com>
 */

namespace Adyen\Payment\Helper;

use Adyen\AdyenException;
use Adyen\Payment\Model\Config\Source\CcType;
use Adyen\Payment\Model\Ui\AdyenCcConfigProvider;
use Adyen\Payment\Model\Ui\AdyenPayByLinkConfigProvider;
use Adyen\Util\Uuid;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

class Requests extends AbstractHelper
{
    const MERCHANT_ACCOUNT = 'merchantAccount';
    const SHOPPER_REFERENCE = 'shopperReference';
    const RECURRING_DETAIL_REFERENCE = 'recurringDetailReference';
    const DONATION_PAYMENT_METHOD_CODE_MAPPING = [
        'ideal' => 'sepadirectdebit',
        'storedPaymentMethods' => 'scheme',
        'googlepay' => 'scheme',
        'paywithgoogle' => 'scheme',
        'applepay' => 'scheme'
    ];

    public function __construct(
        Context $context,
        protected readonly Data $adyenHelper,
        protected readonly Config $adyenConfig,
        protected readonly Address $addressHelper,
        protected readonly StateData $stateData,
        protected readonly Vault $vaultHelper,
        protected readonly ChargedCurrency $chargedCurrency,
        protected readonly PaymentMethods $paymentMethodsHelper,
        protected readonly Locale $localeHelper
    ) {
        parent::__construct($context);
    }

    /**
     * @param $request
     * @param $paymentMethod
     * @param $storeId
     * @return mixed
     * @throws NoSuchEntityException
     */
    public function buildMerchantAccountData($paymentMethod, $storeId, $request = [])
    {
        // Retrieve merchant account
        $merchantAccount = $this->adyenHelper->getAdyenMerchantAccount($paymentMethod, $storeId);

        // Assign merchant account to request object
        $request[self::MERCHANT_ACCOUNT] = $merchantAccount;

        return $request;
    }

    /**
     * @param int $customerId
     * @param $billingAddress
     * @param $storeId
     * @param \Magento\Sales\Model\Order\Payment\|null $payment
     * @param null $additionalData
     * @param array $request
     * @return array
     * @return array
     */
    public function buildCustomerData(
        $billingAddress,
        $storeId,
        $customerId = 0,
        $payment = null,
        $additionalData = null,
        $request = []
    ) {
        $request['shopperReference'] = $this->getShopperReference($customerId, $payment->getOrder()->getIncrementId());

        // In case of virtual product and guest checkout there is a workaround to get the guest's email address
        if (!empty($additionalData['guestEmail'])) {
            $request['shopperEmail'] = $additionalData['guestEmail'];
        }

        if (!empty($billingAddress)) {
            if ($customerEmail = $billingAddress->getEmail()) {
                $request['shopperEmail'] = $customerEmail;
            }

            // /paymentLinks is not accepting "telephoneNumber" - FOC-47179
            if (
                $payment->getMethodInstance()->getCode() != AdyenPayByLinkConfigProvider::CODE &&
                !is_null($billingAddress->getTelephone())
            ) {
                $request['telephoneNumber'] = trim((string) $billingAddress->getTelephone());
            }

            if ($firstName = $billingAddress->getFirstname()) {
                $request['shopperName']['firstName'] = $firstName;
            }

            if ($lastName = $billingAddress->getLastname()) {
                $request['shopperName']['lastName'] = $lastName;
            }

            if ($countryId = $billingAddress->getCountryId()) {
                $request['countryCode'] = $this->addressHelper->getAdyenCountryCode($countryId);
            }

            $request['shopperLocale'] = $this->localeHelper->getStoreLocale($storeId);
        }

        return $request;
    }

    /**
     * @param $request
     * @param $ipAddress
     * @return mixed
     */
    public function buildCustomerIpData($ipAddress, $request = [])
    {
        $request['shopperIP'] = $ipAddress;

        return $request;
    }

    /**
     * @param $billingAddress
     * @param $shippingAddress
     * @param $storeId
     * @param array $request
     */
    public function buildAddressData($billingAddress, $shippingAddress, $storeId, $request = [])
    {
        if ($billingAddress) {
            // Billing address defaults
            $requestBillingDefaults = [
                "street" => "N/A",
                "postalCode" => '',
                "city" => "N/A",
                "houseNumberOrName" => '',
                "country" => "ZZ"
            ];

            // Save the defaults for later to compare if anything has changed
            $requestBilling = $requestBillingDefaults;

            $houseNumberStreetLine = $this->adyenConfig->getAdyenAbstractConfigData(
                Config::XML_HOUSE_NUMBER_STREET_LINE,
                $storeId
            );

            $customerStreetLinesEnabled = $this->adyenHelper->getCustomerStreetLinesEnabled($storeId);

            $address = $this->addressHelper->getStreetAndHouseNumberFromAddress(
                $billingAddress,
                $houseNumberStreetLine,
                $customerStreetLinesEnabled
            );

            if (!empty($address["name"])) {
                $requestBilling["street"] = $address["name"];
            }

            if (!empty($address["house_number"])) {
                $requestBilling["houseNumberOrName"] = $address["house_number"];
            }

            if (!empty($billingAddress->getCity())) {
                $requestBilling["city"] = $billingAddress->getCity();
            }

            if (!empty($billingAddress->getRegionCode())) {
                $requestBilling["stateOrProvince"] = $billingAddress->getRegionCode();
            }

            if (!empty($billingAddress->getCountryId())) {
                $requestBilling["country"] = $this->addressHelper->getAdyenCountryCode(
                    $billingAddress->getCountryId()
                );
            }

            if (!empty($billingAddress->getPostcode())) {
                $requestBilling["postalCode"] = $billingAddress->getPostcode();
                if ($billingAddress->getCountryId() == "BR") {
                    $requestBilling["postalCode"] = preg_replace(
                        '/[^\d]/',
                        '',
                        (string) $requestBilling["postalCode"]
                    );
                }
            }

            // If nothing is changed which means delivery address is not filled
            if ($requestBilling !== $requestBillingDefaults) {
                $request['billingAddress'] = $requestBilling;
            }
        }

        if ($shippingAddress) {
            // Delivery address defaults
            $requestDeliveryDefaults = [
                "street" => "N/A",
                "postalCode" => '',
                "city" => "N/A",
                "houseNumberOrName" => '',
                "country" => "ZZ"
            ];

            // Save the defaults for later to compare if anything has changed
            $requestDelivery = $requestDeliveryDefaults;

            // Parse address into street and house number where possible
            $address = $this->addressHelper->getStreetAndHouseNumberFromAddress(
                $shippingAddress,
                $houseNumberStreetLine,
                $customerStreetLinesEnabled
            );

            if (!empty($address['name'])) {
                $requestDelivery["street"] = $address["name"];
            }

            if (!empty($address["house_number"])) {
                $requestDelivery["houseNumberOrName"] = $address["house_number"];
            }

            if (!empty($shippingAddress->getCity())) {
                $requestDelivery["city"] = $shippingAddress->getCity();
            }

            if (!empty($shippingAddress->getRegionCode())) {
                $requestDelivery["stateOrProvince"] = $shippingAddress->getRegionCode();
            }

            if (!empty($shippingAddress->getCountryId())) {
                $requestDelivery["country"] = $this->addressHelper->getAdyenCountryCode(
                    $shippingAddress->getCountryId()
                );
            }

            if (!empty($shippingAddress->getPostcode())) {
                $requestDelivery["postalCode"] = $shippingAddress->getPostcode();
                if ($shippingAddress->getCountryId() == "BR") {
                    $requestDelivery["postalCode"] = preg_replace(
                        '/[^\d]/',
                        '',
                        (string) $requestDelivery["postalCode"]
                    );
                }
            }

            // If nothing is changed which means delivery address is not filled
            if ($requestDelivery !== $requestDeliveryDefaults) {
                $request['deliveryAddress'] = $requestDelivery;
            }
        }

        return $request;
    }

    /**
     * @param array $request
     * @param $amount
     * @param $currencyCode
     * @param $reference
     * @return array
     */
    public function buildPaymentData($amount, $currencyCode, $reference, array $request = [])
    {
        $request['amount'] = [
            'currency' => $currencyCode,
            'value' => $this->adyenHelper->formatAmount($amount, $currencyCode)
        ];

        $request["reference"] = $reference;

        return $request;
    }

    /**
     * @param array $request
     * @return array
     */
    public function buildBrowserData(array $request = []): array
    {
        $userAgent = $this->_request->getServer('HTTP_USER_AGENT');
        $acceptHeader = $this->_request->getServer('HTTP_ACCEPT');

        if (!empty($userAgent)) {
            $request['browserInfo']['userAgent'] = $userAgent;
        }

        if (!empty($acceptHeader)) {
            $request['browserInfo']['acceptHeader'] = $acceptHeader;
        }

        return $request;
    }

    /**
     * Build the recurring data when payment is done using a card
     *
     * @param int $storeId
     * @param $payment
     * @return array
     */
    public function buildCardRecurringData(int $storeId, $payment): array
    {
        $request = [];

        if (!$this->vaultHelper->getPaymentMethodRecurringActive(AdyenCcConfigProvider::CODE, $storeId)) {
            return $request;
        }

        $storePaymentMethod = false;

        // Initialize the request body with the current state data
        // Multishipping checkout uses the cc_number field for state data
        $stateData = $this->stateData->getStateData($payment->getOrder()->getQuoteId()) ?:
            (json_decode((string) $payment->getCcNumber(), true) ?: []);

        // If PayByLink
        // Else, if option to store token exists, get the value from the checkbox
        if ($payment->getMethod() === AdyenPayByLinkConfigProvider::CODE) {
            $request['storePaymentMethodMode'] = 'askForConsent';
        } elseif (array_key_exists('storePaymentMethod', $stateData)) {
            $storePaymentMethod = $stateData['storePaymentMethod'];
            $request['storePaymentMethod'] = $storePaymentMethod;
        }

        $storedPaymentMethodId = $this->stateData->getStoredPaymentMethodIdFromStateData($stateData);

        if ($storePaymentMethod || isset($storedPaymentMethodId)) {
            $recurringProcessingModel = $payment->getAdditionalInformation('recurringProcessingModel');

            if (isset($recurringProcessingModel)) {
                $request['recurringProcessingModel'] = $recurringProcessingModel;
            } else {
                $request['recurringProcessingModel'] = $this->vaultHelper->getPaymentMethodRecurringProcessingModel(
                    $payment->getMethod(),
                    $storeId
                );
            }
        }

        return $request;
    }

    /**
     * Build the recurring data to be sent in case of an Adyen Tokenized payment.
     * Model will be fetched according to the type (card/other pm) of the original payment
     *
     * @param int $storeId
     * @param $payment
     * @return array
     */
    public function buildAdyenTokenizedPaymentRecurringData(int $storeId, $payment): array
    {
        $request = [];

        $recurringProcessingModel = $payment->getAdditionalInformation('recurringProcessingModel');

        if (isset($recurringProcessingModel)) {
            $request['recurringProcessingModel'] = $recurringProcessingModel;
        } else {
            if (in_array($payment->getAdditionalInformation('cc_type'), CcType::ALLOWED_TYPES)) {
                $recurringProcessingModel = $this->vaultHelper->getPaymentMethodRecurringProcessingModel(
                    AdyenCcConfigProvider::CODE,
                    $storeId
                );
                $request['recurringProcessingModel'] = $recurringProcessingModel;
            } else {
                $request['recurringProcessingModel'] =
                    $this->vaultHelper->getPaymentMethodRecurringProcessingModel($payment->getMethod(), $storeId);
            }
        }

        return $request;
    }

    /**
     * @throws AdyenException
     * @throws LocalizedException
     */
    public function buildDonationData($payment, int $storeId): array
    {
        $order = $payment->getOrder();
        $paymentMethodInstance = $payment->getMethodInstance();

        $donationToken = $payment->getAdditionalInformation('donationToken');
        $donationCampaignId = $payment->getAdditionalInformation('donationCampaignId');
        $pspReference = $payment->getAdditionalInformation('pspReference');
        $payload = $payment->getAdditionalInformation('donationPayload');

        if (!$donationToken || !is_array($payload)) {
            throw new LocalizedException(__('Donation failed!'));
        }

        $orderAmountCurrency = $this->chargedCurrency->getOrderAmountCurrency($order, false);
        $currencyCode = $orderAmountCurrency->getCurrencyCode();

        if ($payload['amount']['currency'] !== $currencyCode) {
            throw new LocalizedException(__('Donation failed!'));
        }

        $payload['donationToken'] = $donationToken;
        $payload['donationCampaignId'] = $donationCampaignId;
        $payload['donationOriginalPspReference'] = $pspReference;

        if ($payment->getMethod() === AdyenCcConfigProvider::CODE) {
            $paymentMethodCode = 'scheme';
        } elseif ($this->paymentMethodsHelper->isAlternativePaymentMethod($paymentMethodInstance)) {
            $paymentMethodCode = $this->paymentMethodsHelper->getAlternativePaymentMethodTxVariant($paymentMethodInstance);
        } else {
            throw new LocalizedException(__('Donation failed!'));
        }

        $shopperReference = $order->getCustomerId()
            ? $this->adyenHelper->padShopperReference($order->getCustomerId())
            : $order->getIncrementId() . Uuid::generateV4();

        return [
            'amount' => $payload['amount'],
            'reference' => Uuid::generateV4(),
            'shopperReference' => $shopperReference,
            'paymentMethod' => [
                'type' => $paymentMethodCode
            ],
            'donationToken' => $payload['donationToken'],
            'donationCampaignId' => $payload['donationCampaignId'],
            'donationOriginalPspReference' => $payload['donationOriginalPspReference'],
            'returnUrl' => $payload['returnUrl'],
            'merchantAccount' => $this->adyenHelper->getAdyenMerchantAccount('adyen_giving', $storeId),
        ];
    }

    /**
     * @param string|null $customerId
     * @param string $orderIncrementId
     * @return string
     */
    public function getShopperReference($customerId, $orderIncrementId): string
    {
        if ($customerId) {
            $shopperReference = $this->adyenHelper->padShopperReference($customerId);
        } else {
            $uuid = Uuid::generateV4();
            $guestCustomerId = $orderIncrementId . $uuid;
            $shopperReference = $guestCustomerId;
        }

        return $shopperReference;
    }
}
