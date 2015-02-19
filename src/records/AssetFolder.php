<?php
/**
 * @link http://buildwithcraft.com/
 * @copyright Copyright (c) 2013 Pixel & Tonic, Inc.
 * @license http://buildwithcraft.com/license
 */

namespace craft\app\records;

use craft\app\enums\AttributeType;

/**
 * Class AssetFolder record.
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 3.0
 */
class AssetFolder extends BaseRecord
{
	// Public Methods
	// =========================================================================

	/**
	 * @inheritdoc
	 *
	 * @return string
	 */
	public static function tableName()
	{
		return '{{%assetfolders}}';
	}

	/**
	 * @inheritDoc BaseRecord::defineRelations()
	 *
	 * @return array
	 */
	public function defineRelations()
	{
		return [
			'parent' => [static::BELONGS_TO, 'AssetFolder', 'onDelete' => static::CASCADE],
			'source' => [static::BELONGS_TO, 'AssetSource', 'required' => false, 'onDelete' => static::CASCADE],
		];
	}

	/**
	 * @inheritDoc BaseRecord::defineIndexes()
	 *
	 * @return array
	 */
	public function defineIndexes()
	{
		return [
			['columns' => ['name', 'parentId', 'sourceId'], 'unique' => true],
		];
	}

	// Protected Methods
	// =========================================================================

	/**
	 * @inheritDoc BaseRecord::defineAttributes()
	 *
	 * @return array
	 */
	protected function defineAttributes()
	{
		return [
			'name'     => [AttributeType::String, 'required' => true],
			'path'     => [AttributeType::String],
		];
	}
}