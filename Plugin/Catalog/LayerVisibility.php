<?php
/**
 * Products in hidden categories leave category listings and search results.
 *
 * HOW MAGENTO LISTS PRODUCTS. A category page and a search result page both get their
 * products from the layer: Magento\Catalog\Model\Layer::getProductCollection() creates the
 * collection and passes it to prepareProductCollection() before anything loads it
 * (Catalog/Model/Layer.php:134-158). With the search engine (OpenSearch / Elasticsearch, the
 * only engines Magento 2.4 supports) that collection is
 * Magento\CatalogSearch\Model\ResourceModel\Fulltext\Collection, which does NOT filter in SQL:
 * addFieldToFilter() records a filter that Magento\Framework\Search\Search::search() binds to
 * the `$<field>$` placeholder of the named search request (Fulltext/Collection.php:325-350;
 * Framework/Search/Search.php:59-113), and the engine returns the page AND the total. A SQL
 * `WHERE` added afterwards would remove products from a page while the total and the pager
 * still counted them.
 *
 * So the exclusion is part of the search request instead. This module's etc/search_request.xml
 * adds, to `quick_search_container` and `catalog_view_container`, a `not` clause on a term
 * filter `category_ids IN $tackquote_hidden_category_ids$` (requests are merged across modules
 * by query and filter name, Framework/Search/Request/Config/FilesystemReader.php:16-24). The
 * engine adapter turns a `not` clause on a filter into `bool.must_not`
 * (Elasticsearch/ElasticAdapter/SearchAdapter/Mapper.php:190-212, which the OpenSearch mapper
 * delegates to), and a term filter with an array value into `terms`
 * (Elasticsearch/SearchAdapter/Filter/Builder/Term.php). When the placeholder is NOT bound (
 * every visitor with nothing hidden, every store with the feature off), Magento's request
 * Cleaner drops the unbound filter, the query that references it and the `not` clause
 * (Framework/Search/Request/Cleaner.php:84-128, 178-262), so the request is exactly Magento's
 * own. This plugin binds it, after Magento's own filters, for a visitor with hidden categories.
 *
 * Only the Fulltext collection is touched: any other collection (a custom layer, a module that
 * swaps the provider) is left alone rather than given a field it does not have.
 *
 * UNVERIFIED on a running store with OpenSearch: the merged request, the `must_not` it
 * produces, and that totals and layered-navigation counts follow it. The PR lists the steps.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Plugin\Catalog;

use Magento\Catalog\Model\Layer;
use Magento\CatalogSearch\Model\ResourceModel\Fulltext\Collection as FulltextCollection;
use Psr\Log\LoggerInterface;
use TackQuote\Quotes\Model\Groups\CatalogVisibility;

class LayerVisibility
{
    /** The search-request placeholder etc/search_request.xml declares. */
    public const SEARCH_FIELD = 'tackquote_hidden_category_ids';

    /**
     * @var CatalogVisibility
     */
    private $visibility;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param CatalogVisibility $visibility
     * @param LoggerInterface $logger
     */
    public function __construct(CatalogVisibility $visibility, LoggerInterface $logger)
    {
        $this->visibility = $visibility;
        $this->logger = $logger;
    }

    /**
     * @param Layer $subject
     * @param Layer $result
     * @param mixed $collection
     * @return Layer
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterPrepareProductCollection(Layer $subject, $result, $collection)
    {
        if (!$collection instanceof FulltextCollection) {
            return $result;
        }
        $hidden = $this->visibility->hiddenCategoryIds();
        if ($hidden === []) {
            return $result;
        }

        try {
            $collection->addFieldToFilter(self::SEARCH_FIELD, $hidden);
        } catch (\RuntimeException $e) {
            // The collection was already searched ("Illegal state"): too late to exclude.
            $this->logger->warning('TackQuote could not exclude hidden categories from a product listing.');
        }

        return $result;
    }
}
