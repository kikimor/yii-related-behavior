<?php
namespace Kikimor\relatedBehavior;
use CActiveRecord;
use CActiveRecordBehavior;

/**
 * @property CActiveRecord $owner
 */
class RelatedBehavior extends CActiveRecordBehavior
{
	/**
	 * @var \CDbTransaction
	 */
	private $transaction;

	/**
	 * Сохранение relation.
	 * Скорее всего требует допиливания.
	 * @param array $relations
	 * @param bool $runValidation
	 * @return bool
	 * @throws \CDbException
	 * @throws \Exception
	 */
	public function saveRelation(array $relations, $runValidation = true)
	{
		if ($runValidation && !$this->validateRelation($relations)) {
			return false;
		}

		$this->prepareRelationsData($relations);

		$this->beginTransaction();
		try {
			foreach ($relations as $relation) {
				if (!$this->mergeRelation($relation)) {
					throw new \Exception('Не удалось сохранить relation "' . $relation . '".');
				}
			}
		} catch (\Exception $e) {
			if (YII_DEBUG) {
				throw $e;
			}
			$this->owner->addError('all', $e->getMessage());
			$this->rollbackTransaction();
			return false;
		}
		$this->commitTransaction();
		return true;
	}

	/**
	 * Валидация релейшенов.
	 * @param array $relations
	 * @return bool
	 */
	public function validateRelation($relations)
	{
		$this->prepareRelationsData($relations);

		$validate = true;
		foreach ($relations as $relation) {
			/** @var CActiveRecord[] $currentRelationsData */
			$currentRelationsData = (array)$this->owner->$relation;

			foreach ($currentRelationsData as $currentRelationData) {
				$fk = array_keys($this->getRelationForeignKeysToBaseModel($currentRelationData));
				$relationAttributes = array_keys($currentRelationData->getAttributes());
				$validateAttributes = array_diff($relationAttributes, $fk);

				$validate = $validate && $currentRelationData->validate($validateAttributes); // Валидация без FK.
			}
		}

		if (!$validate) {
			return false;
		}
		return true;
	}

	/**
	 * Заполнение релейшенов моделями из пост-данных.
	 * @param string $relationName
	 * @param array $postData
	 * @throws \Exception
	 */
	public function fillRelationByPost($relationName, array $postData)
	{
		/** @var CActiveRecord $class */
		$class = $this->owner->relations()[$relationName][1];
		/** @var CActiveRecord $fake */
		$fake = new $class();
		$pk = $fake->tableSchema->primaryKey;

		$models = [];
		foreach ($postData as $data) {
			$model = null;
			if (is_array($pk)) {
				$arrayPk = [];
				foreach ($pk as $field) {
					$arrayPk[$field] = isset($data[$field]) ? $data[$field] : null;
				}
				$model = $class::model()->findByAttributes($arrayPk);
			} elseif (isset($data[$pk]) && $data[$pk]) {
				$model = $class::model()->findByPk(intval($data[$pk]));
			}
			if (!$model) {
				$model = new $class();
			}
			$model->setAttributes($data);
			$models[] = $model;
		}
		$this->owner->$relationName = $models;
	}

	/**
	 * Подготовка данных у релейшенов.
	 * Преобразование массива данных из POST в объект.
	 * Заполнение FK из основной модели.
	 * @param array $relations
	 * @throws \Exception
	 */
	private function prepareRelationsData($relations)
	{
		foreach ($relations as $relationName) {
			$relationData = $this->owner->$relationName;
			if (!is_array($relationData)) {
				continue;
			}

			if (is_array(current($relationData))) {
				$this->fillRelationByPost($relationName, $this->owner->$relationName);
			}

			$relationData = $this->owner->$relationName;
			foreach ($relationData as $key => $data) {
				$relationData[$key] = $this->setRelationForeignKeys($data);
			}
			$this->owner->$relationName = $relationData;
		}
	}

	/**
	 * Сохранение relation.
	 * @param string $relation
	 * @return bool
	 * @throws \CDbException
	 */
	private function mergeRelation($relation)
	{
		/** @var CActiveRecord[] $currentRelationsData */
		$currentRelationsData = (array)$this->owner->$relation;
		/** @var CActiveRecord[] $dbRelationsData */
		$dbRelationsData = (array)$this->owner->getRelated($relation, true);

		try {
			$return = $this->updateRelationData($currentRelationsData, $dbRelationsData) &&
			$this->addRelationData($currentRelationsData);
		} catch (\Exception $e) {
			$this->owner->$relation = $currentRelationsData;
			throw $e;
		}

		return $return;
	}

	/**
	 * Удаление и обновление данных в relation.
	 * @param CActiveRecord $currentRelationsData
	 * @param CActiveRecord $dbRelationsData
	 * @return bool
	 *
	 * @see self::saveRelation
	 */
	private function updateRelationData($currentRelationsData, $dbRelationsData)
	{
		/**
		 * @var CActiveRecord[] $currentRelationsData
		 * @var CActiveRecord[] $dbRelationsData
		 */
		foreach ($dbRelationsData as $dbRelationData) {
			$found = false;
			foreach ($currentRelationsData as $currentRelationData) {
				if ($currentRelationData->getPrimaryKey() == $dbRelationData->getPrimaryKey()) {
					// Обновление записи, если что-то изменилось в атрибутах.
					if ($currentRelationData->getAttributes() != $dbRelationData->getAttributes()) {
						if (!$currentRelationData->save(false)) {
							return false;
						}
					}
					$found = true;
					break;
				}
			}
			// Если запись в текущих данных нет, значит ее нужно удалить.
			if (!$found) {
				if (!$dbRelationData->delete()) {
					return false;
				}
			}
		}
		return true;
	}

	/**
	 * Добавние новых данных в relation.
	 * @param CActiveRecord $currentRelationsData
	 * @return bool
	 *
	 * @see self::saveRelation
	 */
	private function addRelationData($currentRelationsData)
	{
		/** @var CActiveRecord $currentRelationData */
		foreach ($currentRelationsData as $currentRelationData) {
			if ($currentRelationData->isNewRecord) {
				if (!$currentRelationData->save(false)) {
					return false;
				}
			}
		}
		return true;
	}

	/**
	 * Начало транзакции.
	 */
	private function beginTransaction()
	{
		if (!$this->owner->dbConnection->currentTransaction) {
			$this->transaction = $this->owner->dbConnection->beginTransaction();
		}
	}

	/**
	 * Откат изменений.
	 * @throws \CDbException
	 */
	private function rollbackTransaction()
	{
		if ($this->transaction !== null) {
			$this->transaction->rollback();
		}
	}

	/**
	 * Применение изменений.
	 * @throws \CDbException
	 */
	private function commitTransaction()
	{
		if ($this->transaction !== null) {
			$this->transaction->commit();
		}
	}

	/**
	 * Установка значений внешних ключей у relation на основную модель.
	 * @param CActiveRecord $currentRelationData
	 * @return CActiveRecord
	 */
	private function setRelationForeignKeys($currentRelationData)
	{
		$fk = $this->getRelationForeignKeysToBaseModel($currentRelationData);

		/** @var CActiveRecord $currentRelationData */
		foreach ($fk as $foreignName => $foreignData) {
			$currentRelationData->$foreignName = $this->owner->$foreignData[1];
		}
		return $currentRelationData;
	}

	/**
	 * Получение FK релейшенов, которые ссылаются на базовую модель.
	 * @param CActiveRecord $relation
	 * @return array
	 */
	private function getRelationForeignKeysToBaseModel($relation)
	{
		$fk = [];
		foreach ($relation->tableSchema->foreignKeys as $foreignName => $foreignData) {
			if ($foreignData[0] == $this->owner->tableSchema->name) {
				$fk[$foreignName] = $foreignData;
			}
		}
		return $fk;
	}
}
