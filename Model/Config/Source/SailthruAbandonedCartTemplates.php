<?php
 
namespace Sailthru\MageSail\Model\Config\Source;

use Sailthru\MageSail\Helper\Api;
use Sailthru\MageSail\Helper\Templates;

class SailthruAbandonedCartTemplates extends AbstractSource
{
    /** @var Templates  */
    private $sailthruTemplates;

    public function __construct(Api $apiHelper, Templates $sailthruTemplates)
    {
        parent::__construct($apiHelper);
        $this->sailthruTemplates = $sailthruTemplates;
    }

    /** @inheritdoc */
    protected function getDisplayData()
    {
        $data = $this->sailthruTemplates->getSailthruTemplates();
        $tpl_options = [];
        
        if (!isset($data["templates"]))
            return $tpl_options;

        foreach ($data["templates"] as $tpl) {
            $tpl_options[] = [
                'value' => $tpl['name'],
                'label' => $tpl['name'],
            ];
        }
        
        return $tpl_options;
    }
}
