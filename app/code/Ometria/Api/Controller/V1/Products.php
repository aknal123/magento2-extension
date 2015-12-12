<?php
namespace Ometria\Api\Controller\V1;
use Ometria\Api\Helper\Format\V1\Products as Helper;
class Products extends \Magento\Framework\App\Action\Action
{
    protected $resultJsonFactory;
    protected $apiHelperServiceFilterable;
    protected $productRepository;
    protected $urlModel;
    protected $attributes;
    protected $helperCategory;
    protected $response;
    protected $productCollection;
    protected $helperOmetriaApiFilter;
    protected $searchCriteria;
    protected $dataObjectProcessor;            
    protected $storeManager;
    
	public function __construct(
		\Magento\Framework\App\Action\Context $context,
		\Magento\Framework\Controller\Result\JsonFactory $resultJsonFactory,
		\Ometria\Api\Helper\Service\Filterable\Service\Product $apiHelperServiceFilterable,
		\Magento\Catalog\Api\ProductRepositoryInterface $productRepository,
		\Magento\Catalog\Model\Resource\Product\Attribute\Collection $attributes,
		\Ometria\Api\Helper\Category $helperCategory,
		\Magento\Framework\App\ResponseInterface $response,
		\Ometria\Api\Helper\Filter\V1\Service $helperOmetriaApiFilter,	
		\Magento\Framework\Api\SearchCriteriaInterface $searchCriteria,			
		\Magento\Catalog\Model\Resource\Product\Collection $productCollection,
		\Magento\Framework\Reflection\DataObjectProcessor $dataObjectProcessor,
		\Magento\Store\Model\StoreManagerInterface $storeManager
	) {
		parent::__construct($context);
		$this->resultJsonFactory          = $resultJsonFactory;
		$this->apiHelperServiceFilterable = $apiHelperServiceFilterable;
		$this->productRepository          = $productRepository;
		$this->attributes                 = $attributes;
		$this->helperCategory             = $helperCategory;
		$this->response                   = $response;
		$this->productCollection          = $productCollection;
		$this->helperOmetriaApiFilter     = $helperOmetriaApiFilter;	
		$this->searchCriteria             = $searchCriteria;	
		$this->dataObjectProcessor        = $dataObjectProcessor;	
		$this->storeManager               = $storeManager;	
	}
	
	protected function getArrayKey($array, $key)
	{
	    return array_key_exists($key, $array) ? $array[$key] : null;
	}
	
	protected function getCustomAttribute($array, $key)
	{
	    $attributes = $this->getArrayKey($array, 'custom_attributes');
	    if(!$attributes) { return; }
	    
	    $item       = array_filter($attributes, function($item) use ($key){
	        return $item['attribute_code'] === $key;
	    });
	    $item = array_shift($item);
	    
	    if($item)
	    {
	        return $item['value'];
	    }
	    return null;
	}
	
	protected function serializeItem($item)
	{
        $tmp = Helper::getBlankArray();

        $tmp['id']          = $this->getArrayKey($item, 'id');
        $tmp['title']       = $this->getArrayKey($item, 'name');
        $tmp['sku']         = $this->getArrayKey($item, 'sku');
        $tmp['price']       = $this->getArrayKey($item, 'price');
        $tmp['url']         = $this->getArrayKey($item, 'url');
        $tmp['image_url']   = $this->getCustomAttribute($item,'image');
        $tmp['attributes']  = [];
        $tmp['is_active']   = $this->getArrayKey($item, 'status') !== \Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_DISABLED;
        $tmp['stores']      = [$this->getArrayKey($item, 'store_id')];	
        
        //add attributes
        $attributes = $this->getArrayKey($item, 'custom_attributes');        
        $attributes = $attributes ? $attributes : [];
        foreach($attributes as $attribute)
        {
            $full_attribute       = $this->attributes
                ->addFieldToFilter('attribute_code', $attribute['attribute_code'])
                ->getFirstItem();        
            
            $tmp['attributes'][] = [
                'type'=>$attribute['attribute_code'],
                'value'=>$attribute['value'],
                'label'=>$full_attribute->getFrontend()->getLabel()
            ];
        }
        
        $categories_as_attributes = $this->helperCategory
            ->getOmetriaAttributeFromCategoryIds(
                $this->getArrayKey($item, 'category_ids'));
        foreach($categories_as_attributes as $category)
        {
            $tmp['attributes'][] = $category;
        }                
        return $tmp;
	}
    public function execute()
    {      
        $searchCriteria = $this->helperOmetriaApiFilter
            ->applyFilertsToSearchCriteria($this->searchCriteria);
                   
        $collection = $this->productCollection->addAttributeToSelect('*');
        
        foreach ($searchCriteria->getFilterGroups() as $group) {
            $this->addFilterGroupToCollection($group, $collection);
        }

        $page_size = $this->getRequest()->getParam(\Ometria\Api\Helper\Filter\V1\Service::PARAM_PAGE_SIZE);
        if($page_size)
        {                            
            $collection->setPageSize($page_size);
        }            
        
        $current_page = $this->getRequest()->getParam(\Ometria\Api\Helper\Filter\V1\Service::PARAM_CURRENT_PAGE);
        if($current_page)
        {
            $collection->setCurPage($current_page);
        }
        
        $items      = array_map(function($item){
            return $this->dataObjectProcessor->buildOutputDataArray($item, 
                'Magento\Catalog\Api\Data\ProductInterface');
        }, $collection->getItems());
        
        $items = array_values($items);
        $result = $this->resultJsonFactory->create();
        return $result->setData($items);
		
		//old repository based code
        //$items = $this->apiHelperServiceFilterable
        //        ->createResponse($this->productRepository, 'Magento\Catalog\Api\Data\ProductInterface');
        //
        //$items_result = [];       
        //foreach($items as $item)
        //{
        //    $items_result[] = $this->serializeItem($item);;
        //}


    }  
    
    /**
    * Repository interface does not support store filtering
    */    
    protected function addFilterGroupToCollection(
        \Magento\Framework\Api\Search\FilterGroup $filterGroup,
        \Magento\Catalog\Model\Resource\Product\Collection $collection
    ) {
        $fields = [];
        foreach ($filterGroup->getFilters() as $filter) {
            if($filter->getField() === 'store_id')
            {
                foreach($filter->getValue() as $store_id)
                {
                    $store = $this->storeManager->getStore($store_id);
                    $collection->addStoreFilter($store);
                }                
                continue;
            }

            $condition = $filter->getConditionType() ? $filter->getConditionType() : 'eq';
            $fields[] = ['attribute' => $filter->getField(), $condition => $filter->getValue()];
        }
        if(count($fields) > 1)
        {
            throw new \Exception("Can't handle multiple OR filters");
        }
        if ($fields) {
            $attribute = $fields[0]['attribute'];
            unset($fields[0]['attribute']);
            $filter = $fields[0];
            $collection->addFieldToFilter($attribute, $filter);
        }        
    }        
}