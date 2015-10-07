<?php
/***************************************************************
*  Copyright notice
*
*  (c) 2012 Ingo Renner <ingo@typo3.org>
*  All rights reserved
*
*  This script is part of the TYPO3 project. The TYPO3 project is
*  free software; you can redistribute it and/or modify
*  it under the terms of the GNU General Public License as published by
*  the Free Software Foundation; either version 2 of the License, or
*  (at your option) any later version.
*
*  The GNU General Public License can be found at
*  http://www.gnu.org/copyleft/gpl.html.
*
*  This script is distributed in the hope that it will be useful,
*  but WITHOUT ANY WARRANTY; without even the implied warranty of
*  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
*  GNU General Public License for more details.
*
*  This copyright notice MUST APPEAR in all copies of the script!
***************************************************************/


/**
 * An abstract indexer class to collect a few common methods shared with other
 * indexers.
 *
 * @author Ingo Renner <ingo@typo3.org>
 * @package TYPO3
 * @subpackage solr
 */
abstract class Tx_Solr_IndexQueue_AbstractIndexer {

	/**
	 * Holds the type of the data to be indexed, usually that is the table name.
	 *
	 * @var string
	 */
	protected $type = '';


	/**
	 * Adds fields to the document as defined in $indexingConfiguration
	 *
	 * @param Apache_Solr_Document $document base document to add fields to
	 * @param array $indexingConfiguration Indexing configuration / mapping
	 * @param array $data Record data
	 * @return Apache_Solr_Document Modified document with added fields
	 */
	protected function addDocumentFieldsFromTyposcript(Apache_Solr_Document $document, array $indexingConfiguration, array $data) {

			// mapping of record fields => solr document fields, resolving cObj
		foreach ($indexingConfiguration as $solrFieldName => $recordFieldName) {
			if (is_array($recordFieldName)) {
					// configuration for a content object, skipping
				continue;
			}

			$fieldValue = $this->resolveFieldValue($indexingConfiguration, $solrFieldName, $data);

			if (is_array($fieldValue)) {
					// multi value
				foreach ($fieldValue as $multiValue) {
					$document->addField($solrFieldName, $multiValue);
				}
			} else {
				if ($fieldValue !== '' && $fieldValue !== NULL) {
					$document->setField($solrFieldName, $fieldValue);
				}
			}
		}

		return $document;
	}

	/**
	 * Resolves a field to its value depending on its configuration.
	 *
	 * This enables you to configure the indexer to put the item/record through
	 * cObj processing if wanted/needed. Otherwise the plain item/record value
	 * is taken.
	 *
	 * @param array $indexingConfiguration Indexing configuration as defined in plugin.tx_solr_index.queue.[indexingConfigurationName].fields
	 * @param string $solrFieldName A Solr field name that is configured in the indexing configuration
	 * @param array $data A record or item's data
	 * @return string The resolved string value to be indexed
	 */
	protected function resolveFieldValue(array $indexingConfiguration, $solrFieldName, array $data) {
		$fieldValue    = '';
		$contentObject = t3lib_div::makeInstance('tslib_cObj');

		if (isset($indexingConfiguration[$solrFieldName . '.'])) {
				// configuration found => need to resolve a cObj

				// need to change directory to make IMAGE content objects work in BE context
				// see http://blog.netzelf.de/lang/de/tipps-und-tricks/tslib_cobj-image-im-backend
			$backupWorkingDirectory = getcwd();
			chdir(PATH_site);

			$contentObject->start($data, $this->type);
			$fieldValue = $contentObject->cObjGetSingle(
				$indexingConfiguration[$solrFieldName],
				$indexingConfiguration[$solrFieldName . '.']
			);

			chdir($backupWorkingDirectory);

			if ($this->isSerializedValue($indexingConfiguration, $solrFieldName)) {
				$fieldValue = unserialize($fieldValue);
			}

		} elseif (substr($indexingConfiguration[$solrFieldName], 0, 1) === '<') {
			$referencedTsPath = trim(substr($indexingConfiguration[$solrFieldName], 1));
			$typoScriptParser = t3lib_div::makeInstance('t3lib_TSparser');
			// $name and $conf is loaded with the referenced values.
			list($name, $conf) = $typoScriptParser->getVal($referencedTsPath, $GLOBALS['TSFE']->tmpl->setup);

			// need to change directory to make IMAGE content objects work in BE context
			// see http://blog.netzelf.de/lang/de/tipps-und-tricks/tslib_cobj-image-im-backend
			$backupWorkingDirectory = getcwd();
			chdir(PATH_site);

			$contentObject->start($data, $this->type);
			$fieldValue = $contentObject->cObjGetSingle($name, $conf);

			chdir($backupWorkingDirectory);

			if ($this->isSerializedValue($indexingConfiguration, $solrFieldName)) {
				$fieldValue = unserialize($fieldValue);
			}
		} else {
			$fieldValue = $data[$indexingConfiguration[$solrFieldName]];
		}

		// detect and correct type for dynamic fields

		// find last underscore, substr from there, cut off last character (S/M)
		$fieldType = substr($solrFieldName, strrpos($solrFieldName, '_') + 1, -1);
		if (is_array($fieldValue)) {
			foreach ($fieldValue as $key => $value) {
				$fieldValue[$key] = $this->ensureFieldValueType($value, $fieldType);
			}
		} else {
			$fieldValue = $this->ensureFieldValueType($fieldValue, $fieldType);
		}

		return $fieldValue;
	}


	// Utility methods


	/**
	 * Makes sure a field's value matches a (dynamic) field's type.
	 *
	 * @param mixed $value Value to be added to a document
	 * @param string $fieldType The dynamic field's type
	 * @return mixed Returns the value in the correct format for the field type
	 */
	protected function ensureFieldValueType($value, $fieldType) {

		switch ($fieldType) {
			case 'int':
			case 'tInt':
				$value = intval($value);
				break;

			case 'float':
			case 'tFloat':
				$value = floatval($value);
				break;

			// long and double do not exist in PHP
			// simply make sure it somehow looks like a number
			// <insert PHP rant here>
			case 'long':
			case 'tLong':
				// remove anything that's not a number or negative/minus sign
				$value = preg_replace('/[^0-9\\-]/', '', $value);
				if (trim($value) === '') {
					$value = 0;
				}
				break;
			case 'double':
			case 'tDouble':
			case 'tDouble4':
				// as long as it's numeric we'll take it, int or float doesn't matter
				if (!is_numeric($value)) {
					$value = 0;
				}
				break;

			default:
				// assume things are correct for non-dynamic fields
		}

		return $value;
	}

	/**
	 * Uses a field's configuration to detect whether its value returned by a
	 * content object is expected to be serialized and thus needs to be
	 * unserialized.
	 *
	 * @param array $indexingConfiguration Current item's indexing configuration
	 * @param string $solrFieldName	Current field being indexed
	 * @return boolean TRUE if the value is expected to be serialized, FALSE otherwise
	 */
	public static function isSerializedValue(array $indexingConfiguration, $solrFieldName) {
		$isSerialized = FALSE;

		if (is_array($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['solr']['detectSerializedValue'])) {
			foreach ($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['solr']['detectSerializedValue'] as $classReference) {
				$serializedValueDetector = t3lib_div::getUserObj($classReference);

				if ($serializedValueDetector instanceof Tx_Solr_SerializedValueDetector) {
					$isSerialized = (boolean) $serializedValueDetector->isSerializedValue($indexingConfiguration, $solrFieldName);
				} else {
					throw new UnexpectedValueException(
						get_class($serializedValueDetector) . ' must implement interface Tx_Solr_SerializedValueDetector',
						1404471741
					);
				}
			}
		}

			// SOLR_MULTIVALUE - always returns serialized array
		if ($indexingConfiguration[$solrFieldName] == Tx_Solr_ContentObject_Multivalue::CONTENT_OBJECT_NAME) {
			$isSerialized = TRUE;
		}

			// SOLR_RELATION - returns serialized array if multiValue option is set
		if ($indexingConfiguration[$solrFieldName] == Tx_Solr_ContentObject_Relation::CONTENT_OBJECT_NAME
			&& !empty($indexingConfiguration[$solrFieldName . '.']['multiValue'])
		) {
			$isSerialized = TRUE;
		}

		return $isSerialized;
	}

}


if (defined('TYPO3_MODE') && $GLOBALS['TYPO3_CONF_VARS'][TYPO3_MODE]['XCLASS']['ext/solr/Classes/IndexQueue/AbstractIndexer.php'])	{
	include_once($GLOBALS['TYPO3_CONF_VARS'][TYPO3_MODE]['XCLASS']['ext/solr/Classes/IndexQueue/AbstractIndexer.php']);
}

?>
