<?php
/**
 * Pimcore
 *
 * This source file is available under two different licenses:
 * - GNU General Public License version 3 (GPLv3)
 * - Pimcore Enterprise License (PEL)
 * Full copyright and license information is available in
 * LICENSE.md which is distributed with this source code.
 *
 * @category   Pimcore
 * @package    Document
 *
 * @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.org)
 * @license    http://www.pimcore.org/license     GPLv3 and PEL
 */

namespace Pimcore\Model\Document;

use Pimcore\Document\Renderer\DocumentRendererInterface;
use Pimcore\Event\DocumentEvents;
use Pimcore\Event\Model\DocumentEvent;
use Pimcore\Exception\MissingDependencyException;
use Pimcore\Model;
use Pimcore\Model\Document;
use Pimcore\Model\Element;
use Pimcore\Tool\Serialize;
use Symfony\Component\HttpFoundation\Request;

/**
 * @method \Pimcore\Model\Document\Service\Dao getDao()
 */
class Service extends Model\Element\Service
{
    /**
     * @var Model\User
     */
    protected $_user;
    /**
     * @var array
     */
    protected $_copyRecursiveIds;

    /**
     * @var Document[]
     */
    protected $nearestPathCache;

    /**
     * @param null $user
     */
    public function __construct($user = null)
    {
        $this->_user = $user;
    }

    /**
     * Renders a document outside of a view with support for legacy documents
     *
     * Parameter order was kept for BC (useLayout before query and options).
     *
     * @static
     *
     * @param Document\PageSnippet $document
     * @param array $attributes
     * @param bool $useLayout
     * @param array $query
     * @param array $options
     *
     * @return string
     */
    public static function render(Document\PageSnippet $document, array $attributes = [], $useLayout = false, array $query = [], array $options = []): string
    {
        $container = \Pimcore::getContainer();

        /** @var DocumentRendererInterface $renderer */
        $renderer = null;

        if ($document->doRenderWithLegacyStack()) {
            $serviceId = 'pimcore.legacy.document.renderer';
            if (!$container->has($serviceId)) {
                throw new MissingDependencyException(sprintf(
                    'Document %d (%s) is expected to be rendered with the legacy renderer, but legacy renderer does not exist as service "%s"',
                    $document->getId(),
                    $document->getFullPath(),
                    $serviceId
                ));
            }

            $renderer = $container->get($serviceId);
        } else {
            $renderer = $container->get('pimcore.document.renderer');
        }

        // keep useLayout compatibility
        $attributes['_useLayout'] = $useLayout;

        return $renderer->render($document, $attributes, $query, $options);
    }

    /**
     * Save document and all child documents
     *
     * @param     $document
     * @param int $collectGarbageAfterIteration
     * @param int $saved
     */
    public static function saveRecursive($document, $collectGarbageAfterIteration = 25, &$saved = 0)
    {
        if ($document instanceof Document) {
            $document->save();
            $saved++;
            if ($saved % $collectGarbageAfterIteration === 0) {
                \Pimcore::collectGarbage();
            }
        }

        foreach ($document->getChildren() as $child) {
            if (!$child->hasChilds()) {
                $child->save();
                $saved++;
                if ($saved % $collectGarbageAfterIteration === 0) {
                    \Pimcore::collectGarbage();
                }
            }
            if ($child->hasChilds()) {
                self::saveRecursive($child, $collectGarbageAfterIteration, $saved);
            }
        }
    }

    /**
     * @param  Document $target
     * @param  Document $source
     *
     * @return Document copied document
     */
    public function copyRecursive($target, $source)
    {

        // avoid recursion
        if (!$this->_copyRecursiveIds) {
            $this->_copyRecursiveIds = [];
        }
        if (in_array($source->getId(), $this->_copyRecursiveIds)) {
            return;
        }

        if (method_exists($source, 'getElements')) {
            $source->getElements();
        }

        $source->getProperties();

        $new = clone $source;
        $new->id = null;
        $new->setChildren(null);
        $new->setKey(Element\Service::getSaveCopyName('document', $new->getKey(), $target));
        $new->setParentId($target->getId());
        $new->setUserOwner($this->_user->getId());
        $new->setUserModification($this->_user->getId());
        $new->setDao(null);
        $new->setLocked(false);
        $new->setCreationDate(time());
        if (method_exists($new, 'setPrettyUrl')) {
            $new->setPrettyUrl(null);
        }

        $new->save();

        // add to store
        $this->_copyRecursiveIds[] = $new->getId();

        foreach ($source->getChildren() as $child) {
            $this->copyRecursive($new, $child);
        }

        $this->updateChilds($target, $new);

        // triggers actions after the complete document cloning
        \Pimcore::getEventDispatcher()->dispatch(DocumentEvents::POST_COPY, new DocumentEvent($new, [
            'base_element' => $source // the element used to make a copy
        ]));

        return $new;
    }

    /**
     * @param $target
     * @param $source
     * @param bool $enableInheritance
     * @param bool $resetIndex
     *
     * @return Document
     *
     * @throws \Exception
     */
    public function copyAsChild($target, $source, $enableInheritance = false, $resetIndex = false)
    {
        if (method_exists($source, 'getElements')) {
            $source->getElements();
        }

        $source->getProperties();

        $new = clone $source;
        $new->id = null;
        $new->setChilds(null);
        $new->setKey(Element\Service::getSaveCopyName('document', $new->getKey(), $target));
        $new->setParentId($target->getId());
        $new->setUserOwner($this->_user->getId());
        $new->setUserModification($this->_user->getId());
        $new->setDao(null);
        $new->setLocked(false);
        $new->setCreationDate(time());

        if ($resetIndex) {
            // this needs to be after $new->setParentId($target->getId()); -> dependency!
            $new->setIndex($new->getDao()->getNextIndex());
        }

        if (method_exists($new, 'setPrettyUrl')) {
            $new->setPrettyUrl(null);
        }

        if ($enableInheritance && ($new instanceof Document\PageSnippet)) {
            $new->setElements([]);
            $new->setContentMasterDocumentId($source->getId());
        }

        $new->save();

        $this->updateChilds($target, $new);

        // triggers actions after the complete document cloning
        \Pimcore::getEventDispatcher()->dispatch(DocumentEvents::POST_COPY, new DocumentEvent($new, [
            'base_element' => $source // the element used to make a copy
        ]));

        return $new;
    }

    /**
     * @param $target
     * @param $source
     *
     * @return mixed
     *
     * @throws \Exception
     */
    public function copyContents($target, $source)
    {

        // check if the type is the same
        if (get_class($source) != get_class($target)) {
            throw new \Exception('Source and target have to be the same type');
        }

        if ($source instanceof Document\PageSnippet) {
            $target->setElements($source->getElements());

            $target->setTemplate($source->getTemplate());
            $target->setAction($source->getAction());
            $target->setController($source->getController());

            if ($source instanceof Document\Page) {
                $target->setTitle($source->getTitle());
                $target->setDescription($source->getDescription());
            }
        } elseif ($source instanceof Document\Link) {
            $target->setInternalType($source->getInternalType());
            $target->setInternal($source->getInternal());
            $target->setDirect($source->getDirect());
            $target->setLinktype($source->getLinktype());
            $target->setTarget($source->getTarget());
            $target->setParameters($source->getParameters());
            $target->setAnchor($source->getAnchor());
            $target->setTitle($source->getTitle());
            $target->setAccesskey($source->getAccesskey());
            $target->setRel($source->getRel());
            $target->setTabindex($source->getTabindex());
        }

        $target->setUserModification($this->_user->getId());
        $target->setProperties($source->getProperties());
        $target->save();

        return $target;
    }

    /**
     * @param Document $document
     *
     * @return array
     */
    public static function gridDocumentData($document)
    {
        $data = Element\Service::gridElementData($document);

        if ($document instanceof Document\Page) {
            $data['title'] = $document->getTitle();
            $data['description'] = $document->getDescription();
        } else {
            $data['title'] = '';
            $data['description'] = '';
            $data['name'] = '';
        }

        return $data;
    }

    /**
     * @static
     *
     * @param $doc
     *
     * @return mixed
     */
    public static function loadAllDocumentFields($doc)
    {
        $doc->getProperties();

        if ($doc instanceof Document\PageSnippet) {
            foreach ($doc->getElements() as $name => $data) {
                if (method_exists($data, 'load')) {
                    $data->load();
                }
            }
        }

        return $doc;
    }

    /**
     * @static
     *
     * @param $path
     * @param $type
     *
     * @return bool
     */
    public static function pathExists($path, $type = null)
    {
        $path = Element\Service::correctPath($path);

        try {
            $document = new Document();
            // validate path
            if (\Pimcore\Tool::isValidPath($path)) {
                $document->getDao()->getByPath($path);

                return true;
            }
        } catch (\Exception $e) {
        }

        return false;
    }

    /**
     * @param $type
     *
     * @return bool
     */
    public static function isValidType($type)
    {
        return in_array($type, Document::getTypes());
    }

    /**
     * Rewrites id from source to target, $rewriteConfig contains
     * array(
     *  "document" => array(
     *      SOURCE_ID => TARGET_ID,
     *      SOURCE_ID => TARGET_ID
     *  ),
     *  "object" => array(...),
     *  "asset" => array(...)
     * )
     *
     * @param $document
     * @param $rewriteConfig
     * @param array $params
     *
     * @return Document
     */
    public static function rewriteIds($document, $rewriteConfig, $params = [])
    {

        // rewriting elements only for snippets and pages
        if ($document instanceof Document\PageSnippet) {
            if (array_key_exists('enableInheritance', $params) && $params['enableInheritance']) {
                $elements = $document->getElements();
                $changedElements = [];
                $contentMaster = $document->getContentMasterDocument();
                if ($contentMaster instanceof Document\PageSnippet) {
                    $contentMasterElements = $contentMaster->getElements();
                    foreach ($contentMasterElements as $contentMasterElement) {
                        if (method_exists($contentMasterElement, 'rewriteIds')) {
                            $element = clone $contentMasterElement;
                            $element->rewriteIds($rewriteConfig);

                            if (Serialize::serialize($element) != Serialize::serialize($contentMasterElement)) {
                                $changedElements[] = $element;
                            }
                        }
                    }
                }

                if (count($changedElements) > 0) {
                    $elements = $changedElements;
                }
            } else {
                $elements = $document->getElements();
                foreach ($elements as &$element) {
                    if (method_exists($element, 'rewriteIds')) {
                        $element->rewriteIds($rewriteConfig);
                    }
                }
            }

            $document->setElements($elements);
        } elseif ($document instanceof Document\Hardlink) {
            if (array_key_exists('document', $rewriteConfig) && $document->getSourceId() && array_key_exists((int) $document->getSourceId(), $rewriteConfig['document'])) {
                $document->setSourceId($rewriteConfig['document'][(int) $document->getSourceId()]);
            }
        } elseif ($document instanceof Document\Link) {
            if (array_key_exists('document', $rewriteConfig) && $document->getLinktype() == 'internal' && $document->getInternalType() == 'document' && array_key_exists((int) $document->getInternal(), $rewriteConfig['document'])) {
                $document->setInternal($rewriteConfig['document'][(int) $document->getInternal()]);
            }
        }

        // rewriting properties
        $properties = $document->getProperties();
        foreach ($properties as &$property) {
            $property->rewriteIds($rewriteConfig);
        }
        $document->setProperties($properties);

        return $document;
    }

    /**
     * @param $url
     *
     * @return Document
     */
    public static function getByUrl($url)
    {
        $urlParts = parse_url($url);
        if ($urlParts['path']) {
            $document = Document::getByPath($urlParts['path']);

            // search for a page in a site
            if (!$document) {
                $sitesList = new Model\Site\Listing();
                $sitesObjects = $sitesList->load();

                foreach ($sitesObjects as $site) {
                    if ($site->getRootDocument() && (in_array($urlParts['host'], $site->getDomains()) || $site->getMainDomain() == $urlParts['host'])) {
                        if ($document = Document::getByPath($site->getRootDocument() . $urlParts['path'])) {
                            break;
                        }
                    }
                }
            }
        }
        //TODO: $document is not definied here, shouldn't be null returned here?
        return $document;
    }

    /**
     * @param $item
     * @param int $nr
     *
     * @return mixed|string
     *
     * @throws \Exception
     */
    public static function getUniqueKey($item, $nr = 0)
    {
        $list = new Listing();
        $list->setUnpublished(true);
        $key = Element\Service::getValidKey($item->getKey(), 'document');
        if (!$key) {
            throw new \Exception('No item key set.');
        }
        if ($nr) {
            $key = $key . '_' . $nr;
        }

        $parent = $item->getParent();
        if (!$parent) {
            throw new \Exception('You have to set a parent document to determine a unique Key');
        }

        if (!$item->getId()) {
            $list->setCondition('parentId = ? AND `key` = ? ', [$parent->getId(), $key]);
        } else {
            $list->setCondition('parentId = ? AND `key` = ? AND id != ? ', [$parent->getId(), $key, $item->getId()]);
        }
        $check = $list->loadIdList();
        if (!empty($check)) {
            $nr++;
            $key = self::getUniqueKey($item, $nr);
        }

        return $key;
    }

    /**
     * Get the nearest document by path. Used to match nearest document for a static route.
     *
     * @param string|Request $path
     * @param bool $ignoreHardlinks
     * @param array $types
     *
     * @return Document|Document\PageSnippet|null
     */
    public function getNearestDocumentByPath($path, $ignoreHardlinks = false, $types = [])
    {
        if ($path instanceof Request) {
            $path = urldecode($path->getPathInfo());
        }

        $cacheKey = $ignoreHardlinks . implode('-', $types);
        $document = null;

        if (isset($this->nearestPathCache[$cacheKey])) {
            $document = $this->nearestPathCache[$cacheKey];
        } else {
            $paths    = ['/'];
            $tmpPaths = [];

            $pathParts = explode('/', $path);
            foreach ($pathParts as $pathPart) {
                $tmpPaths[] = $pathPart;

                $t = implode('/', $tmpPaths);
                if (!empty($t)) {
                    $paths[] = $t;
                }
            }

            $paths = array_reverse($paths);
            foreach ($paths as $p) {
                if ($document = Document::getByPath($p)) {
                    if (empty($types) || in_array($document->getType(), $types)) {
                        $document = $this->nearestPathCache[$cacheKey] = $document;
                        break;
                    }
                } elseif (Model\Site::isSiteRequest()) {
                    // also check for a pretty url in a site
                    $site = Model\Site::getCurrentSite();

                    // undo the changed made by the site detection in self::match()
                    $originalPath = preg_replace('@^' . $site->getRootPath() . '@', '', $p);

                    $sitePrettyDocId = $this->getDao()->getDocumentIdByPrettyUrlInSite($site, $originalPath);
                    if ($sitePrettyDocId) {
                        if ($sitePrettyDoc = Document::getById($sitePrettyDocId)) {
                            $document = $this->nearestPathCache[$cacheKey] = $sitePrettyDoc;
                            break;
                        }
                    }
                }
            }
        }

        if ($document) {
            if (!$ignoreHardlinks) {
                if ($document instanceof Document\Hardlink) {
                    if ($hardLinkedDocument = Document\Hardlink\Service::getNearestChildByPath($document, $path)) {
                        $document = $hardLinkedDocument;
                    } else {
                        $document = Document\Hardlink\Service::wrap($document);
                    }
                }
            }

            return $document;
        }

        return null;
    }
}
