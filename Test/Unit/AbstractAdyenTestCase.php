<?php declare(strict_types=1);

/**
 * Adyen Payment module (https://www.adyen.com/)
 *
 * Copyright (c) 2022 Adyen BV (https://www.adyen.com/)
 * See LICENSE.txt for license details.
 *
 * Author: Adyen <magento@adyen.com>
 */
namespace Adyen\Payment\Test\Unit;

use Adyen\Payment\Api\Data\OrderPaymentInterface;
use Adyen\Payment\Model\Notification;
use Adyen\Payment\Model\ResourceModel\Order\Payment\Collection;
use Adyen\Payment\Model\ResourceModel\Order\Payment\CollectionFactory as OrderPaymentCollectionFactory;
use Magento\Sales\Model\Order as MagentoOrder;
use Magento\Sales\Model\ResourceModel\Order\Status\Collection as OrderStatusCollection;
use Magento\Sales\Model\ResourceModel\Order\Status\CollectionFactory as OrderStatusCollectionFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

abstract class AbstractAdyenTestCase extends TestCase
{
    /**
     * Create a mock with a mix of methods that already exist and others that do not exist.
     * If conditions are requireed so that MockBuilder does not set $this->emptyMethodsArray = 1
     * This was done since setMethods is deprecated
     */
    protected function createMockWithMethods(string $originalClassName, array $existingMethods, array $nonExistingMethods): MockObject
    {
        $mockBuilder = $this->getMockBuilder($originalClassName)->disableOriginalConstructor();

        if (!empty($existingMethods)) {
            $mockBuilder = $mockBuilder->onlyMethods($existingMethods);
        }

        if (!empty($nonExistingMethods)) {
            $mockBuilder = $mockBuilder->addMethods($nonExistingMethods);
        }

        return $mockBuilder->getMock();
    }

    /**
     * @psalm-template RealInstanceType of object
     *
     * @psalm-param class-string<RealInstanceType> $originalClassName
     *
     * @psalm-return MockObject&RealInstanceType
     */
    protected function createGeneratedMock(
        string $originalClassName,
        array $existingMethods = [],
        array $nonExistingMethods = []
    ): MockObject {
        $mockBuilder = $this->getMockBuilder($originalClassName);

        if (!empty($existingMethods)) {
            $mockBuilder = $mockBuilder->onlyMethods($existingMethods);
        }

        if (!empty($nonExistingMethods)) {
            $mockBuilder = $mockBuilder->addMethods($nonExistingMethods);
        }

        return $mockBuilder->disableOriginalConstructor()
            ->disableOriginalClone()
            ->disableArgumentCloning()
            ->getMock();
    }

    protected function createOrder(?string $status = null)
    {
        $orderPaymentMock = $this->createConfiguredMock(MagentoOrder\Payment::class, ['getMethod' => 'adyen_cc']);

        return $this->createConfiguredMock(MagentoOrder::class, [
            'getStatus' => $status,
            'getPayment' => $orderPaymentMock,
        ]);
    }

    protected function createWebhook(
        ?string $originalReference = null,
        ?string $pspReference = null,
        ?int $value = 1000
    ) {
        return $this->createConfiguredMock(Notification::class, [
            'getAmountValue' => $value,
            'getEventCode' => 'AUTHORISATION',
            'getAmountCurrency' => 'EUR',
            'getOriginalReference' => $originalReference,
            'getPspreference' => $pspReference
        ]);
    }

    protected function createOrderStatusCollection($state): MockObject
    {
        $orderStatus = $this->createMockWithMethods(MagentoOrder\Status::class, [], ['getState']);
        $orderStatus->method('getState')->willReturn($state);

        $orderStatusCollection = $this->createConfiguredMock(OrderStatusCollection::class, []);
        $orderStatusCollection->method('addFieldToFilter')->willReturn($orderStatusCollection);
        $orderStatusCollection->method('joinStates')->willReturn($orderStatusCollection);
        $orderStatusCollection->method('addStateFilter')->willReturn($orderStatusCollection);
        $orderStatusCollection->method('getFirstItem')->willReturn($orderStatus);

        $orderStatusCollectionFactory = $this->createGeneratedMock(OrderStatusCollectionFactory::class, ['create']);
        $orderStatusCollectionFactory->method('create')->willReturn($orderStatusCollection);

        return $orderStatusCollectionFactory;
    }

    protected function createAdyenOrderPaymentCollection(?int $entityId = null): MockObject
    {
        $adyenOrderPayment = $this->createConfiguredMock(OrderPaymentInterface::class, ['getEntityId' => $entityId]);

        $adyenOrderPaymentCollection = $this->createConfiguredMock(Collection::class, [
            'getFirstItem' => $adyenOrderPayment
        ]);
        $adyenOrderPaymentCollection->method('addFieldToFilter')->willReturn($adyenOrderPaymentCollection);

        $adyenOrderPaymentCollectionFactory = $this->createGeneratedMock(OrderPaymentCollectionFactory::class, ['create']);
        $adyenOrderPaymentCollectionFactory->method('create')->willReturn($adyenOrderPaymentCollection);

        return $adyenOrderPaymentCollectionFactory;
    }

    protected function invokeMethod(&$object, $methodName, array $parameters = [])
    {
        $reflection = new \ReflectionClass(get_class($object));
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);

        return $method->invokeArgs($object, $parameters);
    }
}
