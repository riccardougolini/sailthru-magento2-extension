<?php
namespace Sailthru\MageSail\Observer;

use Magento\Catalog\Helper\Product as ProductHelper;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Item;
use Sailthru\MageSail\Cookie\Hid as SailthruCookie;
use Sailthru\MageSail\Helper\ClientManager;
use Sailthru\MageSail\Helper\Order as SailthruOrder;
use Sailthru\MageSail\Helper\ProductData as SailthruProduct;
use Sailthru\MageSail\Helper\Settings as SailthruSettings;
use Sailthru\MageSail\Logger;
use Sailthru\MageSail\Model\Purchase;

class OrderSave implements ObserverInterface {

    /** @var ProductHelper  */
    private $productHelper;

    /** @var ClientManager  */
    private $sailthruClient;

    /** @var SailthruSettings  */
    private $sailthruSettings;

    /** @var SailthruCookie  */
    private $sailthruCookie;

    /** @var SailthruProduct  */
    private $sailthruProduct;

    /** @var SailthruOrder */
    private $sailthruOrder;

    /** @var  Logger */
    private $logger;

    public function __construct(
        ProductHelper $productHelper,
        ClientManager $clientManager,
        SailthruSettings $sailthruSettings,
        SailthruCookie $sailthruCookie,
        SailthruProduct $sailthruProduct,
        SailthruOrder $sailthruOrder,
        Logger $logger
    ) {
        $this->productHelper = $productHelper;
        $this->sailthruClient = $clientManager;
        $this->sailthruSettings = $sailthruSettings;
        $this->sailthruCookie = $sailthruCookie;
        $this->sailthruProduct = $sailthruProduct;
        $this->sailthruOrder = $sailthruOrder;
        $this->logger = $logger;
    }

    public function execute(Observer $observer)
    {
        /** @var Order $order */
        $order = $observer->getOrder();
        $storeId = $order->getStoreId();
        $this->sailthruClient = $this->sailthruClient->getClient(true, $storeId);
        $orderData = $this->build($order);
        try {
            $this->sailthruClient->apiPost("purchase", $orderData);
        } catch (\Sailthru_Client_Exception $e) {
            $this->logger->err("Error sync'ing purchase #{$order->getIncrementId()} - ({$e->getCode()}) {$e->getMessage()}");
        }
    }

    protected function build(Order $order)
    {
        return [
            'email'       => $email = $order->getCustomerEmail(),
            'items'       => $this->processItems($order),
            'adjustments' => $adjustments = $this->processAdjustments($order),
            'vars'        => $this->getOrderVars($order, $adjustments),
            'message_id'  => $this->sailthruCookie->getBid(),
            'tenders'     => $this->processTenders($order),
        ];
    }

    /**
     * Prepare data on items in cart or order.
     *
     * @param Order $order
     * @return array
     */
    protected function processItems(Order $order)
    {
        /** @var \Magento\Sales\Model\Order\Item[] $items */
        $items = $order->getAllVisibleItems();
        $bundleIds = $this->getIdsOfType($items, "bundle");
        $configurableIds = $this->getIdsOfType($items, "configurable");

        $data = [];
        foreach ($items as $item) {
            $product = $item->getProduct();
            if ($product->getStoreId() != $order->getStoreId()) {
                $product->setStoreId($order->getStoreId());
            }

            $i = new \Sailthru\MageSail\Model\Item();
            $i->setTitle($item->getName());
            if ($item->getProduct()->getTypeId() == 'configurable') {
                $options = $item->getProductOptions();
                $sku = $options['simple_sku'];
                $i->setId($sku)
                    ->setUrl($this->sailthruProduct->getProductUrlBySku($sku, $order->getStoreId()))
                    ->setVars($this->sailthruOrder->getItemOptions($item));
            } else if ($this->isTrueSimpleItem($item, $configurableIds, $bundleIds)) {
                $i
                    ->setId($item->getSku())
                    ->setUrl($this->sailthruProduct->getProductUrl($product));
            }

            $i->setPrice($item->getPrice());
            $i->setQuantity($item->getQtyOrdered());
            $i->setImages([
                'full' => ['url' => $this->sailthruProduct->getBaseImageUrl($product)],
            ]);

            $i->setTags([$this->sailthruProduct->getTags($product)]);
            $data[] = $i;
        }

        return $data;
    }

    private function isTrueSimpleItem(Item $item, $configurableIds, $bundleIds)
    {
        $parent = $item->getParentItem();
        return (!$parent || !(in_array($parent->getProductId(), $configurableIds) || in_array($parent->getProductId(), $bundleIds));
    }

    /**
     * Get order adjustments
     * @param Order $order
     * @return array
     */
    public function processAdjustments(Order $order)
    {
        $adjustments = [];
        if ($shipCost = $order->getShippingAmount()) {
            $adjustments[] = [
                'title' => 'Shipping',
                'price' => $shipCost*100,
            ];
        }
        if ($discount = $order->getDiscountAmount()) {
            $adjustments[] = [
                'title' => 'Discount',
                'price' => (($discount > 0) ? $discount*-1 : $discount)*100,
            ];
        }
        if ($tax = $order->getTaxAmount()) {
            $adjustments[] = [
                'title' => 'Tax',
                'price' => $tax*100,
            ];
        }
        return $adjustments;
    }

    /**
     * Get payment information
     * @param  Order         $order
     * @return string|array
     */
    public function processTenders(Order $order)
    {
        if ($order->getPayment()) {
            $tenders = [
                [
                    'title' => $order->getPayment()->getCcType(),
                    'price' => $order->getPayment()->getBaseAmountOrdered()
                ]
            ];
            if ($tenders[0]['title'] == null) {
                return '';
            }
            return $tenders;
        }
        return '';
    }

    /**
     * Get Sailthru order object vars
     * @param Order $order
     * @param array $adjustments
     *
     * @return array
     */
    public function getOrderVars( Order $order, $adjustments)
    {
        $vars = [];
        foreach ($adjustments as $adj) {
            $vars[$adj['title']] =  $adj['price'];
        }
        $vars['orderId'] = "#".strval($order->getIncrementId());
        return $vars;
    }

    /**
     * @param Item[] $items
     * @param string $productType
     *
     * @return array
     */
    private function getIdsOfType($items, $productType) {
        $items = array_values(array_filter(
            $items, function(Item $item) use ($productType) { return $item->getProductType() == $productType;
        }));
        $itemIds = array_map(
            function(Item $item) {
                return $item->getProductId();
            },
            $items
        );
        return $itemIds;
    }
}