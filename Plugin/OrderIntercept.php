<?php
/*
Purchase sync + Email Override
- Sync purchase
- Sendend normal email
*/ 

namespace Sailthru\MageSail\Plugin;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Helper\Image;
use Magento\Catalog\Helper\Product;
use Magento\Catalog\Model\Product\Media\Config;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable as ConfigProduct;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order as OrderResource;
use Magento\Sales\Model\Email\Sender\OrderSender;
use Magento\Sales\Model\Order\Email\Sender\OrderSender\Interceptor; // generated in var/generation. http://alanstorm.com/magento_2_object_manager_plugin_system
use Sailthru\MageSail\Helper\Api;

class OrderIntercept
{

    public function __construct(Api $sailthru, ProductRepositoryInterface $productRepo, Image $imageHelper, Config $mediaConfig, Product $productHelper, OrderResource $orderResource, ConfigProduct $cpModel)
    {
        $this->sailthru      = $sailthru;
        $this->productRepo   = $productRepo;
        $this->imageHelper   = $imageHelper;
        $this->mediaConfig   = $mediaConfig;
        $this->productHelper = $productHelper;
        $this->orderResource = $orderResource;
        $this->cpModel       = $cpModel;
    }

    public function aroundSend(Interceptor $subject, callable $proceed, Order $order, $syncVar=false )
    {
        $alreadyOrdered = $order->getEmailSent();
        if(!$alreadyOrdered){
            try {
                $this->_sendOrder($order);
            } catch (\Exception $e){
                $this->sailthru->logger($e);
                throw new \Exception($e);
            } 
        }
        if ($alreadyOrdered or !$this->sailthru->getOrderOverride()) {
            $val = $proceed($order);
            return $val;
        }
    }


    protected function _sendOrder(Order $order)
    {
        try{
            $this->sailthru->client->_eventType = 'placeOrder';
            $data = [
                    'email'       => $email = $order->getCustomerEmail(),
                    'items'       => $this->_getItems($order->getAllVisibleItems()),
                    'adjustments' => $adjustments = $this->_getAdjustments($order),
                    'vars'        => $this->getOrderVars($order, $adjustments), 
                    'message_id'  => $this->sailthru->getBlastId(),
                    'tenders'     => $this->_getTenders($order)
            ];
            if ($template = $this->sailthru->getOrderOverride()){
                $data['send_template'] = $template;
            }
            $response = $this->sailthru->client->apiPost('purchase', $data);
            if (array_key_exists('error', $response)){
                throw new \Exception($response['error']);
            } 
            elseif($template){
                $order->setEmailSent(true);
                $this->orderResource->saveAttribute($order, ['send_email', 'email_sent']);
            }
            $hid = $this->sailthru->hid->get();
            if (!$hid){
                $response = $this->sailthru->client->apiGet('user', [ 'id' => $email, 'fields' => ['keys'=>1]]);
                if (isset($response['keys']['cookie'])){
                    $this->sailthru->hid->set($response['keys']['cookie']);
                }
            }
        } catch (\Exception $e) {
            $this->sailthru->logger($e);
            throw new \Exception($e->getMessage());
        }
    }

    /**
     * Prepare data on items in cart or order.
     *
     * @return type
     */
    protected function _getItems($items)
    {
        try {
            $data = array();
            $configurableSkus = array();
            foreach($items as $item) {
                $product = $item->getProduct();
                $_item = array();
                $_item['vars'] = array();
                if ($item->getProduct()->getTypeId() == 'configurable') {
                    $parentIds[] = $item->getParentItemId();
                    $options = $item->getProductOptions();
                    $_item['id'] = $options['simple_sku'];
                    $_item['title'] = $options['simple_name'];
                    $_item['vars'] = $this->_getVars($options);
                    $configurableSkus[] = $options['simple_sku'];
                } elseif (!in_array($item->getSku(),$configurableSkus) && $item->getProductType() != 'bundle') {
                    $_item['id'] = $item->getSku();
                    $_item['title'] = $item->getName();
                } else {
                    $_item['id'] = null;
                }
                if ($_item['id']) {
                    $_item['qty'] = $item->getQtyOrdered();
                    $_item['url'] = $item->getProduct()->getProductUrl();
                    $_item['image']=$this->productHelper->getSmallImageUrl($product);
                    $_item['price'] = $item->getPrice() * 100;
                    if ($tags = $this->_getTags($product)) {
                        $_item['tags'] = $tags;
                    }
                    $data[] = $_item;
                }
            }
            return $data;
        } catch (\Exception $e) {
             $this->sailthru->logger($e);
             throw $e;
            return false;
        }
    }
    /**
     * Get order adjustments
     * @param Mage_Sales_Model_Order $order
     * @return array
     */
    protected function _getAdjustments(Order $order)
    {
        $adjustments = [];
        if ($shipCost = $order->getShippingAmount()){
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
     * @param Mage_Sales_Model_Order $order
     * @return mixed
     */
    protected function _getTenders(Order $order)
    {
        if ($order->getPayment()) {
           $tenders = array(
                        array(
                          'title' => $order->getPayment()->getCcType(),
                          'price' => $order->getPayment()->getBaseAmountOrdered()
                           )
                       );
            if ($tenders[0]['title'] == null) {
                return '';
            }
            return $tenders;
        } else {
            return '';
        }
    }
    /**
     * Get product meta keywords
     * @param string $productId
     * @return string
     */
    protected function _getTags($product)
    {
        // return Mage::getResourceModel('catalog/product')->getAttributeRawValue($productId, 'meta_keyword', $this->_storeId);
        return $product->getData('meta_keyword');
    }
    
    
    /**
     *
     * @param array $options
     * @return array
     */
    protected function _getVars($options)
    {
        $vars = array();
        if (array_key_exists('attributes_info', $options)) {
            foreach($options['attributes_info'] as $attribute) {
                $vars[$attribute['label']] = $attribute['value'];
            }
        }
        return $vars;
    }

    protected function getOrderVars($order, $adjustments){
        $vars = [];
        foreach ($adjustments as $adj) {
            $vars[$adj['title']] =  $adj['price'];
        }
        $vars['orderId'] = $order->getId();
        return $vars;
    }



}