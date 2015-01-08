<?php
/**
 * @link http://buildwithcraft.com/
 * @copyright Copyright (c) 2013 Pixel & Tonic, Inc.
 * @license http://buildwithcraft.com/license
 */

namespace craft\app\services;

use craft\app\Craft;
use craft\app\dates\DateTime;
use craft\app\helpers\DateTimeHelper;
use craft\app\helpers\JsonHelper;
use craft\app\helpers\UrlHelper;
use craft\app\models\BaseElementModel;
use yii\base\Component;
use craft\app\models\ElementCriteria  as ElementCriteriaModel;
use craft\app\web\Application;

/**
 * Class TemplateCache service.
 *
 * An instance of the TemplateCache service is globally accessible in Craft via [[Application::templateCache `Craft::$app->templateCache`]].
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 3.0
 */
class TemplateCache extends Component
{
	// Properties
	// =========================================================================

	/**
	 * The table that template caches are stored in.
	 *
	 * @var string
	 */
	private static $_templateCachesTable = 'templatecaches';

	/**
	 * The table that template cache-element relations are stored in.
	 *
	 * @var string
	 */
	private static $_templateCacheElementsTable = 'templatecacheelements';

	/**
	 * The table that queries used within template caches are stored in.
	 *
	 * @var string
	 */
	private static $_templateCacheCriteriaTable = 'templatecachecriteria';

	/**
	 * The duration (in seconds) between the times when Craft will delete any expired template caches.
	 *
	 * @var int
	 */
	private static $_lastCleanupDateCacheDuration = 86400;

	/**
	 * The current request's path, as it will be stored in the templatecaches table.
	 *
	 * @var string
	 */
	private $_path;

	/**
	 * A list of queries (and their criteria attributes) that are active within the existing caches.
	 *
	 * @var array
	 */
	private $_cacheCriteria;

	/**
	 * A list of element IDs that are active within the existing caches.
	 *
	 * @var array
	 */
	private $_cacheElementIds;

	/**
	 * Whether expired caches have already been deleted in this request.
	 *
	 * @var bool
	 */
	private $_deletedExpiredCaches = false;

	/**
	 * Whether all caches have been deleted in this request.
	 *
	 * @var bool
	 */
	private $_deletedAllCaches = false;

	/**
	 * Whether all caches have been deleted, on a per-element type basis, in this request.
	 *
	 * @var bool
	 */
	private $_deletedCachesByElementType;

	// Public Methods
	// =========================================================================

	/**
	 * Returns a cached template by its key.
	 *
	 * @param string $key    The template cache key
	 * @param bool   $global Whether the cache would have been stored globally.
	 *
	 * @return string|null
	 */
	public function getTemplateCache($key, $global)
	{
		// Take the opportunity to delete any expired caches
		$this->deleteExpiredCachesIfOverdue();

		$conditions = ['and', 'expiryDate > :now', 'cacheKey = :key', 'locale = :locale'];

		$params = [
			':now'    => DateTimeHelper::currentTimeForDb(),
			':key'    => $key,
			':locale' => Craft::$app->language
		];

		if (!$global)
		{
			$conditions[] = 'path = :path';
			$params[':path'] = $this->_getPath();
		}

		return Craft::$app->db->createCommand()
			->select('body')
			->from(static::$_templateCachesTable)
			->where($conditions, $params)
			->queryScalar();
	}

	/**
	 * Starts a new template cache.
	 *
	 * @param string $key The template cache key.
	 *
	 * @return null
	 */
	public function startTemplateCache($key)
	{
		if (Craft::$app->config->get('cacheElementQueries'))
		{
			$this->_cacheCriteria[$key] = [];
		}

		$this->_cacheElementIds[$key] = [];
	}

	/**
	 * Includes an element criteria in any active caches.
	 *
	 * @param ElementCriteriaModel $criteria The element criteria.
	 *
	 * @return null
	 */
	public function includeCriteriaInTemplateCaches(ElementCriteriaModel $criteria)
	{
		if (!empty($this->_cacheCriteria))
		{
			$criteriaHash = spl_object_hash($criteria);

			foreach (array_keys($this->_cacheCriteria) as $cacheKey)
			{
				$this->_cacheCriteria[$cacheKey][$criteriaHash] = $criteria;
			}
		}
	}

	/**
	 * Includes an element in any active caches.
	 *
	 * @param int $elementId The element ID.
	 *
	 * @return null
	 */
	public function includeElementInTemplateCaches($elementId)
	{
		if (!empty($this->_cacheElementIds))
		{
			foreach (array_keys($this->_cacheElementIds) as $cacheKey)
			{
				if (array_search($elementId, $this->_cacheElementIds[$cacheKey]) === false)
				{
					$this->_cacheElementIds[$cacheKey][] = $elementId;
				}
			}
		}
	}

	/**
	 * Ends a template cache.
	 *
	 * @param string      $key        The template cache key.
	 * @param bool        $global     Whether the cache should be stored globally.
	 * @param string|null $duration   How long the cache should be stored for.
	 * @param mixed|null  $expiration When the cache should expire.
	 * @param string      $body       The contents of the cache.
	 *
	 * @throws \Exception
	 * @return null
	 */
	public function endTemplateCache($key, $global, $duration, $expiration, $body)
	{
		// If there are any transform generation URLs in the body, don't cache it.
		// Can't use getResourceUrl() here because that will append ?d= or ?x= to the URL.
		if (strpos($body, UrlHelper::getSiteUrl(Craft::$app->config->getResourceTrigger().'/transforms')))
		{
			return;
		}

		// Figure out the expiration date
		if ($duration)
		{
			$expiration = new DateTime($duration);
		}

		if (!$expiration)
		{
			$duration = Craft::$app->config->getCacheDuration();

			if($duration <= 0)
			{
				$duration = 31536000; // 1 year
			}

			$duration += time();

			$expiration = new DateTime('@'.$duration);
		}

		// Save it
		$transaction = Craft::$app->db->getCurrentTransaction() === null ? Craft::$app->db->beginTransaction() : null;

		try
		{
			Craft::$app->db->createCommand()->insert(static::$_templateCachesTable, [
				'cacheKey'   => $key,
				'locale'     => Craft::$app->language,
				'path'       => ($global ? null : $this->_getPath()),
				'expiryDate' => DateTimeHelper::formatTimeForDb($expiration),
				'body'       => $body
			], false);

			$cacheId = Craft::$app->db->getLastInsertID();

			// Tag it with any element criteria that were output within the cache
			if (!empty($this->_cacheCriteria[$key]))
			{
				$values = [];

				foreach ($this->_cacheCriteria[$key] as $criteria)
				{
					$flattenedCriteria = $criteria->getAttributes(null, true);

					$values[] = [$cacheId, $criteria->getElementType()->getClassHandle(), JsonHelper::encode($flattenedCriteria)];
				}

				Craft::$app->db->createCommand()->insertAll(static::$_templateCacheCriteriaTable, ['cacheId', 'type', 'criteria'], $values, false);

				unset($this->_cacheCriteria[$key]);
			}

			// Tag it with any element IDs that were output within the cache
			if (!empty($this->_cacheElementIds[$key]))
			{
				$values = [];

				foreach ($this->_cacheElementIds[$key] as $elementId)
				{
					$values[] = [$cacheId, $elementId];
				}

				Craft::$app->db->createCommand()->insertAll(static::$_templateCacheElementsTable, ['cacheId', 'elementId'], $values, false);

				unset($this->_cacheElementIds[$key]);
			}

			if ($transaction !== null)
			{
				$transaction->commit();
			}
		}
		catch (\Exception $e)
		{
			if ($transaction !== null)
			{
				$transaction->rollback();
			}

			throw $e;
		}
	}

	/**
	 * Deletes a cache by its ID(s).
	 *
	 * @param int|array $cacheId The cache ID.
	 *
	 * @return bool
	 */
	public function deleteCacheById($cacheId)
	{
		if ($this->_deletedAllCaches)
		{
			return false;
		}

		if (is_array($cacheId))
		{
			$condition = ['in', 'id', $cacheId];
			$params = [];
		}
		else
		{
			$condition = 'id = :id';
			$params = [':id' => $cacheId];
		}

		$affectedRows = Craft::$app->db->createCommand()->delete(static::$_templateCachesTable, $condition, $params);
		return (bool) $affectedRows;
	}

	/**
	 * Deletes caches by a given element type.
	 *
	 * @param string $elementType The element type handle.
	 *
	 * @return bool
	 */
	public function deleteCachesByElementType($elementType)
	{
		if ($this->_deletedAllCaches || !empty($this->_deletedCachesByElementType[$elementType]))
		{
			return false;
		}

		$this->_deletedCachesByElementType[$elementType] = true;

		$affectedRows = Craft::$app->db->createCommand()->delete(static::$_templateCachesTable, ['type = :type'], [':type' => $elementType]);
		return (bool) $affectedRows;
	}

	/**
	 * Deletes caches that include a given element(s).
	 *
	 * @param BaseElementModel|BaseElementModel[] $elements The element(s) whose caches should be deleted.
	 *
	 * @return bool
	 */
	public function deleteCachesByElement($elements)
	{
		if ($this->_deletedAllCaches)
		{
			return false;
		}

		if (!$elements)
		{
			return false;
		}

		if (!is_array($elements))
		{
			$elements = [$elements];
		}

		$elementIds = [];

		foreach ($elements as $element)
		{
			// Make sure we haven't just deleted all of the caches for this element type.
			if (empty($this->_deletedCachesByElementType[$element->getElementType()]))
			{
				$elementIds[] = $element->id;
			}
		}

		return $this->deleteCachesByElementId($elementIds);
	}

	/**
	 * Deletes caches that include an a given element ID(s).
	 *
	 * @param int|array $elementId         The ID of the element(s) whose caches should be cleared.
	 * @param bool      $deleteQueryCaches Whether a DeleteStaleTemplateCaches task should be created, deleting any
	 *                                     query caches that may now involve this element, but hadn't previously.
	 *                                     (Defaults to `true`.)
	 *
	 * @return bool
	 */
	public function deleteCachesByElementId($elementId, $deleteQueryCaches = true)
	{
		if ($this->_deletedAllCaches)
		{
			return false;
		}

		if (!$elementId)
		{
			return false;
		}

		if ($deleteQueryCaches && Craft::$app->config->get('cacheElementQueries'))
		{
			// If there are any pending DeleteStaleTemplateCaches tasks, just append this element to it
			$task = Craft::$app->tasks->getNextPendingTask('DeleteStaleTemplateCaches');

			if ($task && is_array($task->settings))
			{
				$settings = $task->settings;

				if (!is_array($settings['elementId']))
				{
					$settings['elementId'] = [$settings['elementId']];
				}

				if (is_array($elementId))
				{
					$settings['elementId'] = array_merge($settings['elementId'], $elementId);
				}
				else
				{
					$settings['elementId'][] = $elementId;
				}

				// Make sure there aren't any duplicate element IDs
				$settings['elementId'] = array_unique($settings['elementId']);

				// Set the new settings and save the task
				$task->settings = $settings;
				Craft::$app->tasks->saveTask($task, false);
			}
			else
			{
				Craft::$app->tasks->createTask('DeleteStaleTemplateCaches', null, [
					'elementId' => $elementId
				]);
			}
		}

		$query = Craft::$app->db->createCommand()
			->selectDistinct('cacheId')
			->from(static::$_templateCacheElementsTable);

		if (is_array($elementId))
		{
			$query->where(['in', 'elementId', $elementId]);
		}
		else
		{
			$query->where('elementId = :elementId', [':elementId' => $elementId]);
		}

		$cacheIds = $query->queryColumn();

		if ($cacheIds)
		{
			return $this->deleteCacheById($cacheIds);
		}
		else
		{
			return false;
		}
	}

	/**
	 * Deletes caches that include elements that match a given criteria.
	 *
	 * @param ElementCriteriaModel $criteria The criteria that should be used to find elements whose caches should be
	 *                                       deleted.
	 *
	 * @return bool
	 */
	public function deleteCachesByCriteria(ElementCriteriaModel $criteria)
	{
		if ($this->_deletedAllCaches)
		{
			return false;
		}

		$criteria->limit = null;
		$elementIds = $criteria->ids();

		return $this->deleteCachesByElementId($elementIds);
	}

	/**
	 * Deletes any expired caches.
	 *
	 * @return bool
	 */
	public function deleteExpiredCaches()
	{
		if ($this->_deletedAllCaches || $this->_deletedExpiredCaches)
		{
			return false;
		}

		$affectedRows = Craft::$app->db->createCommand()->delete(static::$_templateCachesTable,
			'expiryDate <= :now',
			['now' => DateTimeHelper::currentTimeForDb()]
		);

		$this->_deletedExpiredCaches = true;

		return (bool) $affectedRows;
	}

	/**
	 * Deletes any expired caches if we haven't already done that within the past 24 hours.
	 *
	 * @return bool
	 */
	public function deleteExpiredCachesIfOverdue()
	{
		// Ignore if we've already done this once during the request
		if ($this->_deletedExpiredCaches)
		{
			return false;
		}

		$lastCleanupDate = Craft::$app->cache->get('lastTemplateCacheCleanupDate');

		if ($lastCleanupDate === false || DateTimeHelper::currentTimeStamp() - $lastCleanupDate > static::$_lastCleanupDateCacheDuration)
		{
			// Don't do it again for a while
			Craft::$app->cache->set('lastTemplateCacheCleanupDate', DateTimeHelper::currentTimeStamp(), static::$_lastCleanupDateCacheDuration);

			return $this->deleteExpiredCaches();
		}
		else
		{
			$this->_deletedExpiredCaches = true;
			return false;
		}
	}

	/**
	 * Deletes all the template caches.
	 *
	 * @return bool
	 */
	public function deleteAllCaches()
	{
		if ($this->_deletedAllCaches)
		{
			return false;
		}

		$this->_deletedAllCaches = true;

		$affectedRows = Craft::$app->db->createCommand()->delete(static::$_templateCachesTable);
		return (bool) $affectedRows;
	}

	// Private Methods
	// =========================================================================

	/**
	 * Returns the current request path, including a "site:" or "cp:" prefix.
	 *
	 * @return string
	 */
	private function _getPath()
	{
		if (!isset($this->_path))
		{
			if (Craft::$app->request->isCpRequest())
			{
				$this->_path = 'cp:';
			}
			else
			{
				$this->_path = 'site:';
			}

			$this->_path .= Craft::$app->request->getPath();

			if (($pageNum = Craft::$app->request->getPageNum()) != 1)
			{
				$this->_path .= '/'.Craft::$app->config->get('pageTrigger').$pageNum;
			}

			if ($queryString = Craft::$app->request->getQueryString())
			{
				// Strip the path param
				$queryString = trim(preg_replace('/'.Craft::$app->urlManager->pathParam.'=[^&]*/', '', $queryString), '&');

				if ($queryString)
				{
					$this->_path .= '?'.$queryString;
				}
			}
		}

		return $this->_path;
	}
}