<?php

namespace AlgoWeb\PODataLaravel\Serialisers;

use POData\Common\Messages;
use POData\Common\ODataConstants;
use POData\Common\ODataException;
use POData\IService;
use POData\ObjectModel\IObjectSerialiser;
use POData\ObjectModel\ODataEntry;
use POData\ObjectModel\ODataFeed;
use POData\ObjectModel\ODataLink;
use POData\ObjectModel\ODataMediaLink;
use POData\ObjectModel\ODataNavigationPropertyInfo;
use POData\ObjectModel\ODataProperty;
use POData\ObjectModel\ODataPropertyContent;
use POData\ObjectModel\ODataURL;
use POData\ObjectModel\ODataURLCollection;
use POData\Providers\Metadata\ResourceEntityType;
use POData\Providers\Metadata\ResourceProperty;
use POData\Providers\Metadata\ResourcePropertyKind;
use POData\Providers\Metadata\ResourceSet;
use POData\Providers\Metadata\ResourceSetWrapper;
use POData\Providers\Metadata\ResourceType;
use POData\Providers\Metadata\Type\Binary;
use POData\Providers\Metadata\Type\Boolean;
use POData\Providers\Metadata\Type\DateTime;
use POData\Providers\Metadata\Type\IType;
use POData\Providers\Metadata\Type\StringType;
use POData\Providers\Query\QueryType;
use POData\UriProcessor\QueryProcessor\ExpandProjectionParser\ExpandedProjectionNode;
use POData\UriProcessor\QueryProcessor\ExpandProjectionParser\ProjectionNode;
use POData\UriProcessor\RequestDescription;
use POData\UriProcessor\SegmentStack;

class IronicSerialiser implements IObjectSerialiser
{
    /**
     * The service implementation.
     *
     * @var IService
     */
    protected $service;

    /**
     * Request description instance describes OData request the
     * the client has submitted and result of the request.
     *
     * @var RequestDescription
     */
    protected $request;

    /**
     * Collection of complex type instances used for cycle detection.
     *
     * @var array
     */
    protected $complexTypeInstanceCollection;

    /**
     * Absolute service Uri.
     *
     * @var string
     */
    protected $absoluteServiceUri;

    /**
     * Absolute service Uri with slash.
     *
     * @var string
     */
    protected $absoluteServiceUriWithSlash;

    /**
     * Holds reference to segment stack being processed.
     *
     * @var SegmentStack
     */
    protected $stack;

    /**
     * Lightweight stack tracking for recursive descent fill
     */
    private $lightStack = [];

    private $modelSerialiser;

    /**
     * @param IService           $service Reference to the data service instance
     * @param RequestDescription $request Type instance describing the client submitted request
     */
    public function __construct(IService $service, RequestDescription $request = null)
    {
        $this->service = $service;
        $this->request = $request;
        $this->absoluteServiceUri = $service->getHost()->getAbsoluteServiceUri()->getUrlAsString();
        $this->absoluteServiceUriWithSlash = rtrim($this->absoluteServiceUri, '/') . '/';
        $this->stack = new SegmentStack($request);
        $this->complexTypeInstanceCollection = [];
        $this->modelSerialiser = new ModelSerialiser();
    }

    /**
     * Write a top level entry resource.
     *
     * @param mixed $entryObject Reference to the entry object to be written
     *
     * @return ODataEntry
     */
    public function writeTopLevelElement($entryObject)
    {
        if (!isset($entryObject)) {
            array_pop($this->lightStack);
            return null;
        }

        $this->loadStackIfEmpty();

        $stackCount = count($this->lightStack);
        $topOfStack = $this->lightStack[$stackCount-1];
        $resourceType = $this->getService()->getProvidersWrapper()->resolveResourceType($topOfStack[0]);
        $rawProp = $resourceType->getAllProperties();
        $relProp = [];
        $nonRelProp = [];
        foreach ($rawProp as $prop) {
            if ($prop->getResourceType() instanceof ResourceEntityType) {
                $relProp[] = $prop;
            } else {
                $nonRelProp[$prop->getName()] = $prop;
            }
        }

        $resourceSet = $resourceType->getCustomState();
        assert($resourceSet instanceof ResourceSet);
        $title = $resourceType->getName();
        $type = $resourceType->getFullName();

        $relativeUri = $this->getEntryInstanceKey(
            $entryObject,
            $resourceType,
            $resourceSet->getName()
        );
        $absoluteUri = rtrim($this->absoluteServiceUri, '/') . '/' . $relativeUri;

        list($mediaLink, $mediaLinks) = $this->writeMediaData($entryObject, $type, $relativeUri, $resourceType);

        $propertyContent = $this->writePrimitiveProperties($entryObject, $nonRelProp);

        $links = [];
        foreach ($relProp as $prop) {
            $nuLink = new ODataLink();
            $propKind = $prop->getKind();

            assert(
                ResourcePropertyKind::RESOURCESET_REFERENCE == $propKind
                || ResourcePropertyKind::RESOURCE_REFERENCE == $propKind,
                '$propKind != ResourcePropertyKind::RESOURCESET_REFERENCE &&'
                .' $propKind != ResourcePropertyKind::RESOURCE_REFERENCE'
            );
            $propTail = ResourcePropertyKind::RESOURCE_REFERENCE == $propKind ? 'entry' : 'feed';
            $propType = 'application/atom+xml;type='.$propTail;
            $propName = $prop->getName();
            $nuLink->title = $propName;
            $nuLink->name = ODataConstants::ODATA_RELATED_NAMESPACE . $propName;
            $nuLink->url = $relativeUri . '/' . $propName;
            $nuLink->type = $propType;

            $navProp = new ODataNavigationPropertyInfo($prop, $this->shouldExpandSegment($propName));
            if ($navProp->expanded) {
                $this->expandNavigationProperty($entryObject, $prop, $nuLink, $propKind, $propName);
            }

            $links[] = $nuLink;
        }

        $odata = new ODataEntry();
        $odata->resourceSetName = $resourceSet->getName();
        $odata->id = $absoluteUri;
        $odata->title = $title;
        $odata->type = $type;
        $odata->propertyContent = $propertyContent;
        $odata->isMediaLinkEntry = $resourceType->isMediaLinkEntry();
        $odata->editLink = $relativeUri;
        $odata->mediaLink = $mediaLink;
        $odata->mediaLinks = $mediaLinks;
        $odata->links = $links;

        $newCount = count($this->lightStack);
        assert($newCount == $stackCount, "Should have $stackCount elements in stack, have $newCount elements");
        array_pop($this->lightStack);
        return $odata;
    }

    /**
     * Write top level feed element.
     *
     * @param array &$entryObjects Array of entry resources to be written
     *
     * @return ODataFeed
     */
    public function writeTopLevelElements(&$entryObjects)
    {
        assert(is_array($entryObjects), '!is_array($entryObjects)');

        $this->loadStackIfEmpty();
        $setName = $this->getRequest()->getTargetResourceSetWrapper()->getName();

        $title = $this->getRequest()->getContainerName();
        $relativeUri = $this->getRequest()->getIdentifier();
        $absoluteUri = $this->getRequest()->getRequestUrl()->getUrlAsString();

        $selfLink = new ODataLink();
        $selfLink->name = 'self';
        $selfLink->title = $relativeUri;
        $selfLink->url = $relativeUri;

        $odata = new ODataFeed();
        $odata->title = $title;
        $odata->id = $absoluteUri;
        $odata->selfLink = $selfLink;

        if ($this->getRequest()->queryType == QueryType::ENTITIES_WITH_COUNT()) {
            $odata->rowCount = $this->getRequest()->getCountValue();
        }
        foreach ($entryObjects as $entry) {
            $odata->entries[] = $this->writeTopLevelElement($entry);
        }


        if ($this->needNextPageLink(count($entryObjects))) {
            $stackSegment = $setName;
            $lastObject = end($entryObjects);
            $segment = $this->getNextLinkUri($lastObject, $absoluteUri);
            $nextLink = new ODataLink();
            $nextLink->name = ODataConstants::ATOM_LINK_NEXT_ATTRIBUTE_STRING;
            $nextLink->url = rtrim($this->absoluteServiceUri, '/') . '/' . $stackSegment . $segment;
            $odata->nextPageLink = $nextLink;
        }

        return $odata;
    }

    /**
     * Write top level url element.
     *
     * @param mixed $entryObject The entry resource whose url to be written
     *
     * @return ODataURL
     */
    public function writeUrlElement($entryObject)
    {
        $url = new ODataURL();
        if (!is_null($entryObject)) {
            $currentResourceType = $this->getCurrentResourceSetWrapper()->getResourceType();
            $relativeUri = $this->getEntryInstanceKey(
                $entryObject,
                $currentResourceType,
                $this->getCurrentResourceSetWrapper()->getName()
            );

            $url->url = rtrim($this->absoluteServiceUri, '/') . '/' . $relativeUri;
        }

        return $url;
    }

    /**
     * Write top level url collection.
     *
     * @param array $entryObjects Array of entry resources
     *                            whose url to be written
     *
     * @return ODataURLCollection
     */
    public function writeUrlElements($entryObjects)
    {
        $urls = new ODataURLCollection();
        if (!empty($entryObjects)) {
            $i = 0;
            foreach ($entryObjects as $entryObject) {
                $urls->urls[$i] = $this->writeUrlElement($entryObject);
                ++$i;
            }

            if ($i > 0 && $this->needNextPageLink(count($entryObjects))) {
                $stackSegment = $this->getRequest()->getTargetResourceSetWrapper()->getName();
                $lastObject = end($entryObjects);
                $segment = $this->getNextLinkUri($lastObject, $this->getRequest()->getRequestUrl()->getUrlAsString());
                $nextLink = new ODataLink();
                $nextLink->name = ODataConstants::ATOM_LINK_NEXT_ATTRIBUTE_STRING;
                $nextLink->url = rtrim($this->absoluteServiceUri, '/') . '/' . $stackSegment . $segment;
                $urls->nextPageLink = $nextLink;
            }
        }

        if ($this->getRequest()->queryType == QueryType::ENTITIES_WITH_COUNT()) {
            $urls->count = $this->getRequest()->getCountValue();
        }

        return $urls;
    }

    /**
     * Write top level complex resource.
     *
     * @param mixed &$complexValue The complex object to be
     *                                    written
     * @param string $propertyName The name of the
     *                                    complex property
     * @param ResourceType &$resourceType Describes the type of
     *                                    complex object
     *
     * @return ODataPropertyContent
     * @codeCoverageIgnore
     */
    public function writeTopLevelComplexObject(&$complexValue, $propertyName, ResourceType &$resourceType)
    {
        // TODO: Figure out if we need to bother implementing this
    }

    /**
     * Write top level bag resource.
     *
     * @param mixed &$BagValue The bag object to be
     *                                    written
     * @param string $propertyName The name of the
     *                                    bag property
     * @param ResourceType &$resourceType Describes the type of
     *                                    bag object
     * @codeCoverageIgnore
     * @return ODataPropertyContent
     */
    public function writeTopLevelBagObject(&$BagValue, $propertyName, ResourceType &$resourceType)
    {
        // TODO: Figure out if we need to bother implementing this
    }

    /**
     * Write top level primitive value.
     *
     * @param mixed &$primitiveValue The primitve value to be
     *                                            written
     * @param ResourceProperty &$resourceProperty Resource property
     *                                            describing the
     *                                            primitive property
     *                                            to be written
     * @codeCoverageIgnore
     * @return ODataPropertyContent
     */
    public function writeTopLevelPrimitive(&$primitiveValue, ResourceProperty &$resourceProperty = null)
    {
        // TODO: Figure out if we need to bother implementing this
    }

    /**
     * Gets reference to the request submitted by client.
     *
     * @return RequestDescription
     */
    public function getRequest()
    {
        assert(null != $this->request, 'Request not yet set');

        return $this->request;
    }

    /**
     * Sets reference to the request submitted by client.
     *
     * @param RequestDescription $request
     */
    public function setRequest(RequestDescription $request)
    {
        $this->request = $request;
        $this->stack->setRequest($request);
    }

    /**
     * Gets the data service instance.
     *
     * @return IService
     */
    public function getService()
    {
        return $this->service;
    }

    /**
     * Gets the segment stack instance.
     *
     * @return SegmentStack
     */
    public function getStack()
    {
        return $this->stack;
    }

    protected function getEntryInstanceKey($entityInstance, ResourceType $resourceType, $containerName)
    {
        $typeName = $resourceType->getName();
        $keyProperties = $resourceType->getKeyProperties();
        assert(count($keyProperties) != 0, 'count($keyProperties) == 0');
        $keyString = $containerName . '(';
        $comma = null;
        foreach ($keyProperties as $keyName => $resourceProperty) {
            $keyType = $resourceProperty->getInstanceType();
            assert($keyType instanceof IType, '$keyType not instanceof IType');
            $keyName = $resourceProperty->getName();
            $keyValue = $entityInstance->$keyName;
            if (!isset($keyValue)) {
                throw ODataException::createInternalServerError(
                    Messages::badQueryNullKeysAreNotSupported($typeName, $keyName)
                );
            }

            $keyValue = $keyType->convertToOData($keyValue);
            $keyString .= $comma . $keyName . '=' . $keyValue;
            $comma = ',';
        }

        $keyString .= ')';

        return $keyString;
    }

    /**
     * @param $entryObject
     * @param $type
     * @param $relativeUri
     * @param $resourceType
     * @return array
     */
    protected function writeMediaData($entryObject, $type, $relativeUri, ResourceType $resourceType)
    {
        $context = $this->getService()->getOperationContext();
        $streamProviderWrapper = $this->getService()->getStreamProviderWrapper();
        assert(null != $streamProviderWrapper, "Retrieved stream provider must not be null");

        $mediaLink = null;
        if ($resourceType->isMediaLinkEntry()) {
            $eTag = $streamProviderWrapper->getStreamETag2($entryObject, null, $context);
            $mediaLink = new ODataMediaLink($type, '/$value', $relativeUri . '/$value', '*/*', $eTag);
        }
        $mediaLinks = [];
        if ($resourceType->hasNamedStream()) {
            $namedStreams = $resourceType->getAllNamedStreams();
            foreach ($namedStreams as $streamTitle => $resourceStreamInfo) {
                $readUri = $streamProviderWrapper->getReadStreamUri2(
                    $entryObject,
                    $resourceStreamInfo,
                    $context,
                    $relativeUri
                );
                $mediaContentType = $streamProviderWrapper->getStreamContentType2(
                    $entryObject,
                    $resourceStreamInfo,
                    $context
                );
                $eTag = $streamProviderWrapper->getStreamETag2(
                    $entryObject,
                    $resourceStreamInfo,
                    $context
                );

                $nuLink = new ODataMediaLink($streamTitle, $readUri, $readUri, $mediaContentType, $eTag);
                $mediaLinks[] = $nuLink;
            }
        }
        return [$mediaLink, $mediaLinks];
    }

    /**
     * Gets collection of projection nodes under the current node.
     *
     * @return ProjectionNode[]|ExpandedProjectionNode[]|null List of nodes
     *                                                        describing projections for the current segment, If this method returns
     *                                                        null it means no projections are to be applied and the entire resource
     *                                                        for the current segment should be serialized, If it returns non-null
     *                                                        only the properties described by the returned projection segments should
     *                                                        be serialized
     */
    protected function getProjectionNodes()
    {
        $expandedProjectionNode = $this->getCurrentExpandedProjectionNode();
        if (is_null($expandedProjectionNode) || $expandedProjectionNode->canSelectAllProperties()) {
            return null;
        }

        return $expandedProjectionNode->getChildNodes();
    }

    /**
     * Find a 'ExpandedProjectionNode' instance in the projection tree
     * which describes the current segment.
     *
     * @return ExpandedProjectionNode|null
     */
    protected function getCurrentExpandedProjectionNode()
    {
        $expandedProjectionNode = $this->getRequest()->getRootProjectionNode();
        if (is_null($expandedProjectionNode)) {
            return null;
        } else {
            $segmentNames = $this->getStack()->getSegmentNames();
            $depth = count($segmentNames);
            // $depth == 1 means serialization of root entry
            //(the resource identified by resource path) is going on,
            //so control won't get into the below for loop.
            //we will directly return the root node,
            //which is 'ExpandedProjectionNode'
            // for resource identified by resource path.
            if (0 != $depth) {
                for ($i = 1; $i < $depth; ++$i) {
                    $expandedProjectionNode = $expandedProjectionNode->findNode($segmentNames[$i]);
                    assert(!is_null($expandedProjectionNode), 'is_null($expandedProjectionNode)');
                    assert(
                        $expandedProjectionNode instanceof ExpandedProjectionNode,
                        '$expandedProjectionNode not instanceof ExpandedProjectionNode'
                    );
                }
            }
        }

        return $expandedProjectionNode;
    }

    /**
     * Check whether to expand a navigation property or not.
     *
     * @param string $navigationPropertyName Name of naviagtion property in question
     *
     * @return bool True if the given navigation should be
     *              explanded otherwise false
     */
    protected function shouldExpandSegment($navigationPropertyName)
    {
        $expandedProjectionNode = $this->getCurrentExpandedProjectionNode();
        if (is_null($expandedProjectionNode)) {
            return false;
        }

        $expandedProjectionNode = $expandedProjectionNode->findNode($navigationPropertyName);

        // null is a valid input to an instanceof call as of PHP 5.6 - will always return false
        return $expandedProjectionNode instanceof ExpandedProjectionNode;
    }

    /**
     * Wheter next link is needed for the current resource set (feed)
     * being serialized.
     *
     * @param int $resultSetCount Number of entries in the current
     *                            resource set
     *
     * @return bool true if the feed must have a next page link
     */
    protected function needNextPageLink($resultSetCount)
    {
        $currentResourceSet = $this->getCurrentResourceSetWrapper();
        $recursionLevel = count($this->getStack()->getSegmentNames());
        $pageSize = $currentResourceSet->getResourceSetPageSize();

        if (1 == $recursionLevel) {
            //presence of $top option affect next link for root container
            $topValueCount = $this->getRequest()->getTopOptionCount();
            if (!is_null($topValueCount) && ($topValueCount <= $pageSize)) {
                return false;
            }
        }
        return $resultSetCount == $pageSize;
    }

    /**
     * Resource set wrapper for the resource being serialized.
     *
     * @return ResourceSetWrapper
     */
    protected function getCurrentResourceSetWrapper()
    {
        $segmentWrappers = $this->getStack()->getSegmentWrappers();
        $count = count($segmentWrappers);

        return 0 == $count ? $this->getRequest()->getTargetResourceSetWrapper() : $segmentWrappers[$count - 1];
    }

    /**
     * Get next page link from the given entity instance.
     *
     * @param mixed  &$lastObject Last object serialized to be
     *                            used for generating $skiptoken
     * @param string $absoluteUri Absolute response URI
     *
     * @return string for the link for next page
     */
    protected function getNextLinkUri(&$lastObject, $absoluteUri)
    {
        $currentExpandedProjectionNode = $this->getCurrentExpandedProjectionNode();
        $internalOrderByInfo = $currentExpandedProjectionNode->getInternalOrderByInfo();
        $skipToken = $internalOrderByInfo->buildSkipTokenValue($lastObject);
        assert(!is_null($skipToken), '!is_null($skipToken)');
        $skipToken = '?$skip='.$skipToken;
        return $skipToken;
    }

    private function loadStackIfEmpty()
    {
        if (0 == count($this->lightStack)) {
            $typeName = $this->getRequest()->getTargetResourceType()->getName();
            array_push($this->lightStack, [$typeName, $typeName]);
        }
    }

    /**
     * Convert the given primitive value to string.
     * Note: This method will not handle null primitive value.
     *
     * @param IType &$primitiveResourceType        Type of the primitive property
     *                                             whose value need to be converted
     * @param mixed        $primitiveValue         Primitive value to convert
     *
     * @return string
     */
    private function primitiveToString(IType &$type, $primitiveValue)
    {
        if ($type instanceof Boolean) {
            $stringValue = (true === $primitiveValue) ? 'true' : 'false';
        } elseif ($type instanceof Binary) {
            $stringValue = base64_encode($primitiveValue);
        } elseif ($type instanceof DateTime && $primitiveValue instanceof \DateTime) {
            $stringValue = $primitiveValue->format(\DateTime::ATOM);
        } elseif ($type instanceof StringType) {
            $stringValue = utf8_encode($primitiveValue);
        } else {
            $stringValue = strval($primitiveValue);
        }

        return $stringValue;
    }

    /**
     * @param $entryObject
     * @param $nonRelProp
     * @return ODataPropertyContent
     */
    private function writePrimitiveProperties($entryObject, $nonRelProp)
    {
        $propertyContent = new ODataPropertyContent();
        $cereal = $this->modelSerialiser->bulkSerialise($entryObject);
        foreach ($cereal as $corn => $flake) {
            if (!array_key_exists($corn, $nonRelProp)) {
                continue;
            }
            $corn = strval($corn);
            $rType = $nonRelProp[$corn]->getResourceType()->getInstanceType();
            $subProp = new ODataProperty();
            $subProp->name = $corn;
            $subProp->value = isset($flake) ? $this->primitiveToString($rType, $flake) : null;
            $subProp->typeName = $nonRelProp[$corn]->getResourceType()->getFullName();
            $propertyContent->properties[] = $subProp;
        }
        return $propertyContent;
    }

    /**
     * @param $entryObject
     * @param $prop
     * @param $nuLink
     * @param $propKind
     * @param $propName
     */
    private function expandNavigationProperty($entryObject, $prop, $nuLink, $propKind, $propName)
    {
        $nextName = $prop->getResourceType()->getName();
        $nuLink->isExpanded = true;
        $isCollection = ResourcePropertyKind::RESOURCESET_REFERENCE == $propKind;
        $nuLink->isCollection = $isCollection;
        $value = $entryObject->$propName;
        array_push($this->lightStack, [$nextName, $propName]);
        if (!$isCollection) {
            $expandedResult = $this->writeTopLevelElement($value);
        } else {
            $expandedResult = $this->writeTopLevelElements($value);
        }
        $nuLink->expandedResult = $expandedResult;
        if (!isset($nuLink->expandedResult)) {
            $nuLink->isCollection = null;
            $nuLink->isExpanded = null;
        } else {
            if (isset($nuLink->expandedResult->selfLink)) {
                $nuLink->expandedResult->selfLink->title = $propName;
                $nuLink->expandedResult->selfLink->url = $nuLink->url;
                $nuLink->expandedResult->title = $propName;
                $nuLink->expandedResult->id = rtrim($this->absoluteServiceUri, '/') . '/' . $nuLink->url;
            }
        }
    }
}