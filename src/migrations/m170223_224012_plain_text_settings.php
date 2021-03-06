<?php

namespace craft\migrations;

use craft\db\Migration;
use craft\db\Query;
use craft\fields\PlainText;
use craft\helpers\ArrayHelper;
use craft\helpers\Db;
use craft\helpers\Json;
use yii\db\Schema;

/**
 * m170223_224012_plain_text_settings migration.
 */
class m170223_224012_plain_text_settings extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp()
    {
        $fields = (new Query())
            ->select(['id', 'settings'])
            ->from(['{{%fields}}'])
            ->where(['type' => PlainText::class])
            ->all();

        foreach ($fields as $field) {
            $settings = Json::decode($field['settings']);

            // maxLength => charLimit
            ArrayHelper::rename($settings, 'maxLength', 'charLimit');

            // columnType
            if ($settings['charLimit']) {
                // This is how Plain Text fields used to automagically determine their column type
                $settings['columnType'] = Db::getTextualColumnTypeByContentLength($settings['charLimit'], $this->db);
            } else {
                // Default to text
                $settings['columnType'] = Schema::TYPE_TEXT;
            }

            $this->update('{{%fields}}', [
                'settings' => Json::encode($settings)
            ], ['id' => $field['id']]);
        }
    }

    /**
     * @inheritdoc
     */
    public function safeDown()
    {
        echo "m170223_224012_plain_text_column_types cannot be reverted.\n";
        return false;
    }
}
