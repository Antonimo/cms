<?php
/**
 * @link http://buildwithcraft.com/
 * @copyright Copyright (c) 2013 Pixel & Tonic, Inc.
 * @license http://buildwithcraft.com/license
 */

namespace craft\app\fieldtypes;

use craft\app\Craft;
use craft\app\enums\ElementType;
use craft\app\helpers\JsonHelper;
use craft\app\helpers\StringHelper;
use craft\app\models\BaseModel;
use craft\app\models\ElementCriteria as ElementCriteriaModel;
use craft\app\models\Field           as FieldModel;
use craft\app\models\MatrixBlock     as MatrixBlockModel;
use craft\app\models\MatrixBlockType as MatrixBlockTypeModel;
use craft\app\models\MatrixSettings  as MatrixSettingsModel;

/**
 * Matrix fieldtype
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 3.0
 */
class Matrix extends BaseFieldType
{
	// Public Methods
	// =========================================================================

	/**
	 * @inheritDoc ComponentTypeInterface::getName()
	 *
	 * @return string
	 */
	public function getName()
	{
		return Craft::t('Matrix');
	}

	/**
	 * @inheritDoc FieldTypeInterface::defineContentAttribute()
	 *
	 * @return mixed
	 */
	public function defineContentAttribute()
	{
		return false;
	}

	/**
	 * @inheritDoc SavableComponentTypeInterface::getSettingsHtml()
	 *
	 * @return string|null
	 */
	public function getSettingsHtml()
	{
		// Get the available field types data
		$fieldTypeInfo = $this->_getFieldTypeInfoForConfigurator();

		Craft::$app->templates->includeJsResource('js/MatrixConfigurator.js');
		Craft::$app->templates->includeJs('new Craft.MatrixConfigurator('.JsonHelper::encode($fieldTypeInfo).', "'.Craft::$app->templates->getNamespace().'");');

		Craft::$app->templates->includeTranslations(
			'What this block type will be called in the CP.',
			'How you’ll refer to this block type in the templates.',
			'Are you sure you want to delete this block type?',
			'This field is required',
			'This field is translatable',
			'Field Type',
			'Are you sure you want to delete this field?'
		);

		$fieldTypeOptions = [];

		foreach (Craft::$app->fields->getAllFieldTypes() as $fieldType)
		{
			// No Matrix-Inception, sorry buddy.
			if ($fieldType->getClassHandle() != 'Matrix')
			{
				$fieldTypeOptions[] = ['label' => $fieldType->getName(), 'value' => $fieldType->getClassHandle()];
			}
		}

		return Craft::$app->templates->render('_components/fieldtypes/Matrix/settings', [
			'settings'   => $this->getSettings(),
			'fieldTypes' => $fieldTypeOptions
		]);
	}

	/**
	 * @inheritDoc SavableComponentTypeInterface::prepSettings()
	 *
	 * @param array $settings
	 *
	 * @return array
	 */
	public function prepSettings($settings)
	{
		if ($settings instanceof MatrixSettingsModel)
		{
			return $settings;
		}

		$matrixSettings = new MatrixSettingsModel($this->model);
		$blockTypes = [];

		if (!empty($settings['blockTypes']))
		{
			foreach ($settings['blockTypes'] as $blockTypeId => $blockTypeSettings)
			{
				$blockType = new MatrixBlockTypeModel();
				$blockType->id      = $blockTypeId;
				$blockType->fieldId = $this->model->id;
				$blockType->name    = $blockTypeSettings['name'];
				$blockType->handle  = $blockTypeSettings['handle'];

				$fields = [];

				if (!empty($blockTypeSettings['fields']))
				{
					foreach ($blockTypeSettings['fields'] as $fieldId => $fieldSettings)
					{
						$field = new FieldModel();
						$field->id           = $fieldId;
						$field->name         = $fieldSettings['name'];
						$field->handle       = $fieldSettings['handle'];
						$field->instructions = $fieldSettings['instructions'];
						$field->required     = !empty($fieldSettings['required']);
						$field->translatable = !empty($fieldSettings['translatable']);
						$field->type         = $fieldSettings['type'];

						if (isset($fieldSettings['typesettings']))
						{
							$field->settings = $fieldSettings['typesettings'];
						}

						$fields[] = $field;
					}
				}

				$blockType->setFields($fields);
				$blockTypes[] = $blockType;
			}
		}

		$matrixSettings->setBlockTypes($blockTypes);

		if (!empty($settings['maxBlocks']))
		{
			$matrixSettings->maxBlocks = $settings['maxBlocks'];
		}

		return $matrixSettings;
	}

	/**
	 * @inheritDoc FieldTypeInterface::onAfterSave()
	 *
	 * @return null
	 */
	public function onAfterSave()
	{
		Craft::$app->matrix->saveSettings($this->getSettings(), false);
	}

	/**
	 * @inheritDoc FieldTypeInterface::onBeforeDelete()
	 *
	 * @return null
	 */
	public function onBeforeDelete()
	{
		Craft::$app->matrix->deleteMatrixField($this->model);
	}

	/**
	 * @inheritDoc FieldTypeInterface::prepValue()
	 *
	 * @param mixed $value
	 *
	 * @return ElementCriteriaModel
	 */
	public function prepValue($value)
	{
		$criteria = Craft::$app->elements->getCriteria(ElementType::MatrixBlock);

		// Existing element?
		if (!empty($this->element->id))
		{
			$criteria->ownerId = $this->element->id;
		}
		else
		{
			$criteria->id = false;
		}

		$criteria->fieldId = $this->model->id;
		$criteria->locale = $this->element->locale;

		// Set the initially matched elements if $value is already set, which is the case if there was a validation
		// error or we're loading an entry revision.
		if (is_array($value) || $value === '')
		{
			$criteria->status = null;
			$criteria->localeEnabled = null;
			$criteria->limit = null;

			if (is_array($value))
			{
				$prevElement = null;

				foreach ($value as $element)
				{
					if ($prevElement)
					{
						$prevElement->setNext($element);
						$element->setPrev($prevElement);
					}

					$prevElement = $element;
				}

				$criteria->setMatchedElements($value);
			}
			else if ($value === '')
			{
				// Means there were no blocks
				$criteria->setMatchedElements([]);
			}
		}

		return $criteria;
	}

	/**
	 * @inheritDoc FieldTypeInterface::getInputHtml()
	 *
	 * @param string $name
	 * @param mixed  $value
	 *
	 * @return string
	 */
	public function getInputHtml($name, $value)
	{
		$id = Craft::$app->templates->formatInputId($name);
		$settings = $this->getSettings();

		// Get the block types data
		$blockTypeInfo = $this->_getBlockTypeInfoForInput($name);

		Craft::$app->templates->includeJsResource('js/MatrixInput.js');

		Craft::$app->templates->includeJs('new Craft.MatrixInput(' .
			'"'.Craft::$app->templates->namespaceInputId($id).'", ' .
			JsonHelper::encode($blockTypeInfo).', ' .
			'"'.Craft::$app->templates->namespaceInputName($name).'", ' .
			($settings->maxBlocks ? $settings->maxBlocks : 'null') .
		');');

		Craft::$app->templates->includeTranslations('Disabled', 'Actions', 'Collapse', 'Expand', 'Disable', 'Enable', 'Add {type} above', 'Add a block');

		if ($value instanceof ElementCriteriaModel)
		{
			$value->limit = null;
			$value->status = null;
			$value->localeEnabled = null;
		}

		return Craft::$app->templates->render('_components/fieldtypes/Matrix/input', [
			'id' => $id,
			'name' => $name,
			'blockTypes' => $settings->getBlockTypes(),
			'blocks' => $value,
			'static' => false
		]);
	}

	/**
	 * @inheritDoc FieldTypeInterface::prepValueFromPost()
	 *
	 * @param mixed $data
	 *
	 * @return MatrixBlockModel[]
	 */
	public function prepValueFromPost($data)
	{
		// Get the possible block types for this field
		$blockTypes = Craft::$app->matrix->getBlockTypesByFieldId($this->model->id, 'handle');

		if (!is_array($data))
		{
			return [];
		}

		$oldBlocksById = [];

		// Get the old blocks that are still around
		if (!empty($this->element->id))
		{
			$ownerId = $this->element->id;

			$ids = [];

			foreach (array_keys($data) as $blockId)
			{
				if (is_numeric($blockId) && $blockId != 0)
				{
					$ids[] = $blockId;
				}
			}

			if ($ids)
			{
				$criteria = Craft::$app->elements->getCriteria(ElementType::MatrixBlock);
				$criteria->fieldId = $this->model->id;
				$criteria->ownerId = $ownerId;
				$criteria->id = $ids;
				$criteria->limit = null;
				$criteria->status = null;
				$criteria->localeEnabled = null;
				$criteria->locale = $this->element->locale;
				$oldBlocks = $criteria->find();

				// Index them by ID
				foreach ($oldBlocks as $oldBlock)
				{
					$oldBlocksById[$oldBlock->id] = $oldBlock;
				}
			}
		}
		else
		{
			$ownerId = null;
		}

		$blocks = [];
		$sortOrder = 0;

		foreach ($data as $blockId => $blockData)
		{
			if (!isset($blockData['type']) || !isset($blockTypes[$blockData['type']]))
			{
				continue;
			}

			$blockType = $blockTypes[$blockData['type']];

			// Is this new? (Or has it been deleted?)
			if (strncmp($blockId, 'new', 3) === 0 || !isset($oldBlocksById[$blockId]))
			{
				$block = new MatrixBlockModel();
				$block->fieldId = $this->model->id;
				$block->typeId  = $blockType->id;
				$block->ownerId = $ownerId;
				$block->locale  = $this->element->locale;

				// Preserve the collapsed state, which the browser can't remember on its own for new blocks
				$block->collapsed = !empty($blockData['collapsed']);
			}
			else
			{
				$block = $oldBlocksById[$blockId];
			}

			$block->setOwner($this->element);
			$block->enabled = (isset($blockData['enabled']) ? (bool) $blockData['enabled'] : true);

			// Set the content post location on the block if we can
			$ownerContentPostLocation = $this->element->getContentPostLocation();

			if ($ownerContentPostLocation)
			{
				$block->setContentPostLocation("{$ownerContentPostLocation}.{$this->model->handle}.{$blockId}.fields");
			}

			if (isset($blockData['fields']))
			{
				$block->setContentFromPost($blockData['fields']);
			}

			$sortOrder++;
			$block->sortOrder = $sortOrder;

			$blocks[] = $block;
		}

		return $blocks;
	}

	/**
	 * @inheritDoc FieldTypeInterface::validate()
	 *
	 * @param array $blocks
	 *
	 * @return true|string|array
	 */
	public function validate($blocks)
	{
		$errors = [];
		$blocksValidate = true;

		foreach ($blocks as $block)
		{
			if (!Craft::$app->matrix->validateBlock($block))
			{
				$blocksValidate = false;
			}
		}

		if (!$blocksValidate)
		{
			$errors[] = Craft::t('Correct the errors listed above.');
		}

		$maxBlocks = $this->getSettings()->maxBlocks;

		if ($maxBlocks && count($blocks) > $maxBlocks)
		{
			if ($maxBlocks == 1)
			{
				$errors[] = Craft::t('There can’t be more than one block.');
			}
			else
			{
				$errors[] = Craft::t('There can’t be more than {max} blocks.', ['max' => $maxBlocks]);
			}
		}

		if ($errors)
		{
			return $errors;
		}
		else
		{
			return true;
		}
	}

	/**
	 * @inheritDoc FieldTypeInterface::getSearchKeywords()
	 *
	 * @param mixed $value
	 *
	 * @return string
	 */
	public function getSearchKeywords($value)
	{
		$keywords = [];
		$contentService = Craft::$app->content;

		foreach ($value as $block)
		{
			$originalContentTable      = $contentService->contentTable;
			$originalFieldColumnPrefix = $contentService->fieldColumnPrefix;
			$originalFieldContext      = $contentService->fieldContext;

			$contentService->contentTable      = $block->getContentTable();
			$contentService->fieldColumnPrefix = $block->getFieldColumnPrefix();
			$contentService->fieldContext      = $block->getFieldContext();

			foreach (Craft::$app->fields->getAllFields() as $field)
			{
				$fieldType = $field->getFieldType();

				if ($fieldType)
				{
					$fieldType->element = $block;
					$handle = $field->handle;
					$keywords[] = $fieldType->getSearchKeywords($block->getFieldValue($handle));
				}
			}

			$contentService->contentTable      = $originalContentTable;
			$contentService->fieldColumnPrefix = $originalFieldColumnPrefix;
			$contentService->fieldContext      = $originalFieldContext;
		}

		return parent::getSearchKeywords($keywords);
	}

	/**
	 * @inheritDoc FieldTypeInterface::onAfterElementSave()
	 *
	 * @return null
	 */
	public function onAfterElementSave()
	{
		Craft::$app->matrix->saveField($this);
	}

	/**
	 * @inheritDoc BaseFieldType::getStaticHtml()
	 *
	 * @param mixed $value
	 *
	 * @return string
	 */
	public function getStaticHtml($value)
	{
		if ($value)
		{
			$settings = $this->getSettings();
			$id = StringHelper::randomString();

			return Craft::$app->templates->render('_components/fieldtypes/Matrix/input', [
				'id' => $id,
				'name' => $id,
				'blockTypes' => $settings->getBlockTypes(),
				'blocks' => $value,
				'static' => true
			]);
		}
		else
		{
			return '<p class="light">'.Craft::t('No blocks.').'</p>';
		}
	}

	// Protected Methods
	// =========================================================================

	/**
	 * @inheritDoc BaseSavableComponentType::getSettingsModel()
	 *
	 * @return BaseModel
	 */
	protected function getSettingsModel()
	{
		return new MatrixSettingsModel($this->model);
	}

	// Private Methods
	// =========================================================================

	/**
	 * Returns info about each field type for the configurator.
	 *
	 * @return array
	 */
	private function _getFieldTypeInfoForConfigurator()
	{
		$fieldTypes = [];

		// Set a temporary namespace for these
		$originalNamespace = Craft::$app->templates->getNamespace();
		$namespace = Craft::$app->templates->namespaceInputName('blockTypes[__BLOCK_TYPE__][fields][__FIELD__][typesettings]', $originalNamespace);
		Craft::$app->templates->setNamespace($namespace);

		foreach (Craft::$app->fields->getAllFieldTypes() as $fieldType)
		{
			$fieldTypeClass = $fieldType->getClassHandle();

			// No Matrix-Inception, sorry buddy.
			if ($fieldTypeClass == 'Matrix')
			{
				continue;
			}

			Craft::$app->templates->startJsBuffer();
			$settingsBodyHtml = Craft::$app->templates->namespaceInputs($fieldType->getSettingsHtml());
			$settingsFootHtml = Craft::$app->templates->clearJsBuffer();

			$fieldTypes[] = [
				'type'             => $fieldTypeClass,
				'name'             => $fieldType->getName(),
				'settingsBodyHtml' => $settingsBodyHtml,
				'settingsFootHtml' => $settingsFootHtml,
			];
		}

		Craft::$app->templates->setNamespace($originalNamespace);

		return $fieldTypes;
	}

	/**
	 * Returns info about each field type for the configurator.
	 *
	 * @param string $name
	 *
	 * @return array
	 */
	private function _getBlockTypeInfoForInput($name)
	{
		$blockTypes = [];

		// Set a temporary namespace for these
		$originalNamespace = Craft::$app->templates->getNamespace();
		$namespace = Craft::$app->templates->namespaceInputName($name.'[__BLOCK__][fields]', $originalNamespace);
		Craft::$app->templates->setNamespace($namespace);

		foreach ($this->getSettings()->getBlockTypes() as $blockType)
		{
			// Create a fake MatrixBlockModel so the field types have a way to get at the owner element, if there is one
			$block = new MatrixBlockModel();
			$block->fieldId = $this->model->id;
			$block->typeId = $blockType->id;

			if ($this->element)
			{
				$block->setOwner($this->element);
			}

			$fieldLayoutFields = $blockType->getFieldLayout()->getFields();

			foreach ($fieldLayoutFields as $fieldLayoutField)
			{
				$fieldType = $fieldLayoutField->getField()->getFieldType();

				if ($fieldType)
				{
					$fieldType->element = $block;
					$fieldType->setIsFresh(true);
				}
			}

			Craft::$app->templates->startJsBuffer();

			$bodyHtml = Craft::$app->templates->namespaceInputs(Craft::$app->templates->render('_includes/fields', [
				'namespace' => null,
				'fields'    => $fieldLayoutFields
			]));

			// Reset $_isFresh's
			foreach ($fieldLayoutFields as $fieldLayoutField)
			{
				$fieldType = $fieldLayoutField->getField()->getFieldType();

				if ($fieldType)
				{
					$fieldType->setIsFresh(null);
				}
			}

			$footHtml = Craft::$app->templates->clearJsBuffer();

			$blockTypes[] = [
				'handle'   => $blockType->handle,
				'name'     => Craft::t($blockType->name),
				'bodyHtml' => $bodyHtml,
				'footHtml' => $footHtml,
			];
		}

		Craft::$app->templates->setNamespace($originalNamespace);

		return $blockTypes;
	}
}