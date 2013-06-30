<?php

namespace Elastica;

use Elastica\Document;
use Elastica\Exception\RuntimeException;
use Elastica\Exception\InvalidException;
use Elastica\Exception\NotFoundException;
use Elastica\Exception\ResponseException;
use Elastica\Type\Mapping;

/**
 * Elastica type object
 *
 * elasticsearch has for every types as a substructure. This object
 * represents a type inside a context
 * The hierarchy is as following: client -> index -> type -> document
 *
 * @category Xodoa
 * @package  Elastica
 * @author   Nicolas Ruflin <spam@ruflin.com>
 */
class Type implements SearchableInterface
{
    /**
     * Index
     *
     * @var \Elastica\Index Index object
     */
    protected $_index = null;

    /**
     * Type name
     *
     * @var string Type name
     */
    protected $_name = '';

    /**
     * @var array|string A callable that serializes an object passed to it
     */
    protected $_serializer;

    /**
     * Creates a new type object inside the given index
     *
     * @param \Elastica\Index $index Index Object
     * @param string         $name  Type name
     */
    public function __construct(Index $index, $name)
    {
        $this->_index = $index;
        $this->_name = $name;
    }

    /**
     * Adds the given document to the search index
     *
     * @param  \Elastica\Document $doc Document with data
     * @return \Elastica\Response
     */
    public function addDocument(Document $doc)
    {
        $path = urlencode($doc->getId());

        $type = Request::PUT;

        // If id is empty, POST has to be used to automatically create id
        if (empty($path)) {
            $type = Request::POST;
        }

        $options = $doc->getOptions(
            array(
                'version',
                'version_type',
                'routing',
                'percolate',
                'parent',
                'ttl',
                'timestamp',
                'op_type',
                'consistency',
                'replication',
                'refresh',
                'timeout',
            )
        );

        $response = $this->request($path, $type, $doc->getData(), $options);

        $data = $response->getData();
        // set autogenerated id to document
        if (($doc->isAutoPopulate()
            || $this->getIndex()->getClient()->getConfigValue(array('document', 'autoPopulate'), false))
            && $response->isOk()
        ) {
            if (!$doc->hasId()) {
                if (isset($data['_id'])) {
                    $doc->setId($data['_id']);
                }
            }
            if (isset($data['_version'])) {
                $doc->setVersion($data['_version']);
            }
        }

        return $response;
    }

    /**
     * @param $object
     * @param Document $doc
     * @return Response
     * @throws Exception\RuntimeException
     */
    public function addObject($object, Document $doc = null)
    {
        if (!isset($this->_serializer)) {
            throw new RuntimeException('No serializer defined');
        }

        $data = call_user_func($this->_serializer, $object);
        if (!$doc) {
            $doc = new Document();
        }
        $doc->setData($data);

        return $this->addDocument($doc);
    }

    /**
     * Update document, using update script. Requires elasticsearch >= 0.19.0
     *
     * @param  \Elastica\Document|\Elastica\Script                   $data Document with update data
     * @throws \Elastica\Exception\InvalidException
     * @return \Elastica\Response
     * @link http://www.elasticsearch.org/guide/reference/api/update.html
     */
    public function updateDocument($data)
    {
    	if(!($data instanceof Document) && !($data instanceof Script)){
    		throw new \InvalidArgumentException("Data should be a Document or Script");
    	}
    	
        if (!$data->hasId()) {
            throw new InvalidException('Document or Script id is not set');
        }

        return $this->getIndex()->getClient()->updateDocument(
            $data->getId(),
            $data,
            $this->getIndex()->getName(),
            $this->getName()
        );
    }

    /**
     * Uses _bulk to send documents to the server
     *
     * @param  array|\Elastica\Document[] $docs Array of Elastica\Document
     * @return \Elastica\Bulk\ResponseSet
     * @link http://www.elasticsearch.org/guide/reference/api/bulk.html
     */
    public function addDocuments(array $docs)
    {
        foreach ($docs as $doc) {
            $doc->setType($this->getName());
        }

        return $this->getIndex()->addDocuments($docs);
    }

    /**
     * Uses _bulk to send documents to the server
     *
     * @param objects[] $objects
     * @return \Elastica\Bulk\ResponseSet
     * @link http://www.elasticsearch.org/guide/reference/api/bulk.html
     */
    public function addObjects(array $objects)
    {
        if (!isset($this->_serializer)) {
            throw new RuntimeException('No serializer defined');
        }

        $docs = array();
        foreach ($objects as $object) {
            $data = call_user_func($this->_serializer, $object);
            $doc = new Document();
            $doc->setData($data);
            $doc->setType($this->getName());
            $docs[] = $doc;
        }

        return $this->getIndex()->addDocuments($docs);
    }

    /**
     * Get the document from search index
     *
     * @param  string                               $id      Document id
     * @param  array                                $options Options for the get request.
     * @throws \Elastica\Exception\NotFoundException
     * @return \Elastica\Document
     */
    public function getDocument($id, $options = array())
    {
        $path = urlencode($id);

        try {
            $result = $this->request($path, Request::GET, array(), $options)->getData();
        } catch (ResponseException $e) {
            throw new NotFoundException('doc id ' . $id . ' not found');
        }

        if (empty($result['exists'])) {
            throw new NotFoundException('doc id ' . $id . ' not found');
        }

        $data = isset($result['_source']) ? $result['_source'] : array();
        $document = new Document($id, $data, $this->getName(), $this->getIndex());
        $document->setVersion($result['_version']);

        return $document;
    }

    /**
     * @param string $id
     * @param array|string $data
     * @return Document
     */
    public function createDocument($id = '', $data = array())
    {
        $document = new Document($id, $data);
        $document->setType($this);

        return $document;
    }

    /**
     * Returns the type name
     *
     * @return string Type name
     */
    public function getName()
    {
        return $this->_name;
    }

    /**
     * Sets value type mapping for this type
     *
     * @param  \Elastica\Type\Mapping|array $mapping Elastica\Type\MappingType object or property array with all mappings
     * @return \Elastica\Response
     */
    public function setMapping($mapping)
    {
        $mapping = Mapping::create($mapping);
        $mapping->setType($this);

        return $mapping->send();
    }

    /**
     * Returns current mapping for the given type
     *
     * @return array Current mapping
     */
    public function getMapping()
    {
        $path = '_mapping';

        $response = $this->request($path, Request::GET);

        return $response->getData();
    }

    /**
     * Create search object
     *
     * @param  string|array|\Elastica\Query $query   Array with all query data inside or a Elastica\Query object
     * @param  int|array                   $options OPTIONAL Limit or associative array of options (option=>value)
     * @return \Elastica\Search
     */
    public function createSearch($query = '', $options = null)
    {
        $search = new Search($this->getIndex()->getClient());
        $search->addIndex($this->getIndex());
        $search->addType($this);
        $search->setOptionsAndQuery($options, $query);

        return $search;
    }

    /**
     * Do a search on this type
     *
     * @param  string|array|\Elastica\Query $query   Array with all query data inside or a Elastica\Query object
     * @param  int|array                   $options OPTIONAL Limit or associative array of options (option=>value)
     * @return \Elastica\ResultSet          ResultSet with all results inside
     * @see \Elastica\SearchableInterface::search
     */
    public function search($query = '', $options = null)
    {
        $search = $this->createSearch($query, $options);

        return $search->search();
    }

    /**
     * Count docs by query
     *
     * @param  string|array|\Elastica\Query $query Array with all query data inside or a Elastica\Query object
     * @return int                         number of documents matching the query
     * @see \Elastica\SearchableInterface::count
     */
    public function count($query = '')
    {
        $search = $this->createSearch($query);

        return $search->count();
    }

    /**
     * Returns index client
     *
     * @return \Elastica\Index Index object
     */
    public function getIndex()
    {
        return $this->_index;
    }

    /**
     * @param \Elastica\Document $document
     * @return \Elastica\Response
     */
    public function deleteDocument(Document $document)
    {
        $options = $document->getOptions(
            array(
                'version',
                'version_type',
                'routing',
                'parent',
                'replication',
                'consistency',
                'refresh',
                'timeout'
            )
        );
        return $this->deleteById($document->getId(), $options);
    }

    /**
     * Deletes an entry by its unique identifier
     *
     * @param  int|string               $id Document id
     * @param array $options
     * @throws \InvalidArgumentException
     * @throws \Elastica\Exception\NotFoundException
     * @return \Elastica\Response        Response object
     * @link http://www.elasticsearch.org/guide/reference/api/delete.html
     */
    public function deleteById($id, array $options = array())
    {
        if (empty($id) || !trim($id)) {
            throw new \InvalidArgumentException();
        }

        $id = urlencode($id);

        $response = $this->request($id, Request::DELETE, array(), $options);

        $responseData = $response->getData();

        if (isset($responseData['found']) && false == $responseData['found']) {
            throw new NotFoundException('Doc id ' . $id . ' not found and can not be deleted');
        }

        return $response;
    }

    /**
     * Deletes the given list of ids from this type
     *
     * @param  array             $ids
     * @return \Elastica\Response Response object
     */
    public function deleteIds(array $ids)
    {
        return $this->getIndex()->getClient()->deleteIds($ids, $this->getIndex(), $this);
    }

    /**
     * Deletes entries in the db based on a query
     *
     * @param  \Elastica\Query|string $query Query object
     * @return \Elastica\Response
     * @link http://www.elasticsearch.org/guide/reference/api/delete-by-query.html
     */
    public function deleteByQuery($query)
    {
        $query = Query::create($query);

        return $this->request('_query', Request::DELETE, $query->getQuery());
    }

    /**
     * Deletes the index type.
     *
     * @return \Elastica\Response
     */
    public function delete()
    {
        $response = $this->request('', Request::DELETE);

        return $response;
    }

    /**
     * More like this query based on the given object
     *
     * The id in the given object has to be set
     *
     * @param  \Elastica\Document           $doc    Document to query for similar objects
     * @param  array                       $params OPTIONAL Additional arguments for the query
     * @param  string|array|\Elastica\Query $query  OPTIONAL Query to filter the moreLikeThis results
     * @return \Elastica\ResultSet          ResultSet with all results inside
     * @link http://www.elasticsearch.org/guide/reference/api/more-like-this.html
     */
    public function moreLikeThis(Document $doc, $params = array(), $query = array())
    {
        $path = $doc->getId() . '/_mlt';

        $query = Query::create($query);

        $response = $this->request($path, Request::GET, $query->toArray(), $params);

        return new ResultSet($response, $query);
    }

    /**
     * Makes calls to the elasticsearch server based on this type
     *
     * @param  string            $path   Path to call
     * @param  string            $method Rest method to use (GET, POST, DELETE, PUT)
     * @param  array             $data   OPTIONAL Arguments as array
     * @param  array             $query  OPTIONAL Query params
     * @return \Elastica\Response Response object
     */
    public function request($path, $method, $data = array(), array $query = array())
    {
        $path = $this->getName() . '/' . $path;

        return $this->getIndex()->request($path, $method, $data, $query);
    }

    /**
     * Sets the serializer callable used in addObject
     * @see \Elastica\Type::addObject
     *
     * @param array|string $serializer  @see \Elastica\Type::_serializer
     */
    public function setSerializer($serializer)
    {
        $this->_serializer = $serializer;
    }
}
