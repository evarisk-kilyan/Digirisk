<?php
/* Copyright (C) 2021-2023 EVARISK <technique@evarisk.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 *	\file       core/modules/digiriskdolibarr/digiriskdolibarrdocuments/accidentinvestigationdocument/doc_accidentinvestigationdocument_odt.modules.php
 *	\ingroup    digiriskdolibarr
 *	\brief      File of class to build ODT documents for digiriskdolibarr
 */

// Load Dolibarr libraries.
require_once DOL_DOCUMENT_ROOT . '/core/lib/files.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/images.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/company.lib.php';

// Load Saturne libraries.
require_once __DIR__ . '/../../../../../../saturne/class/saturnesignature.class.php';

// Load DigiriskDolibarr libraries.
require_once __DIR__ . '/../../../../../class/digiriskstandard.class.php';
require_once __DIR__ . '/modules_accidentinvestigationdocument.php';
require_once __DIR__ . '/mod_accidentinvestigationdocument_standard.php';

/**
 *	Class to build documents using ODF templates generator
 */
class doc_accidentinvestigationdocument_odt extends SaturneDocumentModel
{
	/**
	 * @var array Minimum version of PHP required by module.
	 * e.g.: PHP ≥ 5.5 = array(5, 5)
	 */
	public $phpmin = [7, 4];

	/**
	 * @var string Dolibarr version of the loaded document.
	 */
	public string $version = 'dolibarr';

	/**
	 * @var string Module.
	 */
	public string $module = 'digiriskdolibarr';

	/**
	 * @var string Document type.
	 */
	public string $document_type = 'accidentinvestigationdocument';

	/**
	 * Constructor.
	 *
	 * @param DoliDB $db Database handler.
	 */
	public function __construct(DoliDB $db)
	{
		parent::__construct($db, $this->module, $this->document_type);
	}

	/**
	 * Return description of a module.
	 *
	 * @param  Translate $langs Lang object to use for output.
	 * @return string           Description.
	 */
	public function info(Translate $langs): string
	{
		return parent::info($langs);
	}

	/**
	 * Set task segment.
	 *
	 * @param  Odf       $odfHandler  Object builder odf library.
	 * @param  Translate $outputLangs Lang object to use for output.
	 * @param  array     $moreParam   More param (Object/user/etc).
	 *
	 * @throws Exception
	 */
	public function setTaskSegment(Odf $odfHandler, Translate $outputLangs, array $moreParam)
	{
		global $langs;

		// Get tasks.
		$foundTagForLines = 1;
		$tmpArray         = [];
		$now              = dol_now();
		$taskType         = $moreParam['task_type'];
		try {
			$listLines = $odfHandler->setSegment($taskType);
		} catch (OdfException $e) {
			// We may arrive here if tags for lines not present into template.
			$foundTagForLines = 0;
			$listLines        = '';
			dol_syslog($e->getMessage());
		}

		$taskParentId = $taskType == 'cur_task' ?  $moreParam['curativeTaskId'] : $moreParam['preventiveTaskId'];
		$actionTasks  = saturne_fetch_all_object_type('SaturneTask', '', '', 0, 0, ['customsql' => 'fk_task_parent = ' . $taskParentId]);

		if ($foundTagForLines) {
			if (is_array($actionTasks) && !empty($actionTasks)) {
				foreach ($actionTasks as $actionTask) {
					$taskExecutive = $actionTask->liste_contact(-1, 'internal', 0, 'TASKEXECUTIVE');

					$tmpArray[$taskType . '_ref']         = $actionTask->ref;
					$tmpArray[$taskType . '_description'] = $actionTask->description;

					$delay  = $actionTask->datee > 0 ? round(($actionTask->datee - $now) / 60 / 60 / 24) : 0;
					$delay .= ' ' . ($delay > 1 ? $langs->trans('Days') : $langs->trans('Day'));

					$tmpArray[$taskType . '_resp']   = dol_strtoupper($taskExecutive[0]['lastname']) . ' ' . ucfirst($taskExecutive[0]['firstname']);
					$tmpArray[$taskType . '_delay']  = $delay;
					$tmpArray[$taskType . '_budget'] = price($actionTask->budget_amount, 0, '', 1, -1, -1, 'auto');
					$this->setTmpArrayVars($tmpArray, $listLines, $outputLangs);
				}
			} else {
				$tmpArray[$taskType . '_ref']         = '';
				$tmpArray[$taskType . '_description'] = '';
				$tmpArray[$taskType . '_resp']        = '';
				$tmpArray[$taskType . '_delay']       = '';
				$tmpArray[$taskType . '_budget']      = '';
				$this->setTmpArrayVars($tmpArray, $listLines, $outputLangs);
			}
			$odfHandler->mergeSegment($listLines);
		}
	}

	/**
	 * Fill all odt tags for segments lines.
	 *
	 * @param  Odf       $odfHandler  Object builder odf library.
	 * @param  Translate $outputLangs Lang object to use for output.
	 * @param  array     $moreParam   More param (Object/user/etc).
	 *
	 * @return int                    1 if OK, <=0 if KO.
	 * @throws Exception
	 */
	public function fillTagsLines(Odf $odfHandler, Translate $outputLangs, array $moreParam): int
	{
		require_once __DIR__ . '/../../../../../class/digiriskdocuments.class.php';

		global $conf;

		$digiriskDocument = new DigiriskDocuments($this->db);
		$risk             = new Risk($this->db);
		// Replace tags of lines.
		try {
			$this->setAttendantsSegment($odfHandler, $outputLangs, $moreParam);

			$risks = $moreParam['gp_ut_id'] > 0 ? $risk->fetchRisksOrderedByCotation($moreParam['gp_ut_id'], true, $conf->global->DIGIRISKDOLIBARR_SHOW_INHERITED_RISKS_IN_DOCUMENTS, $conf->global->DIGIRISKDOLIBARR_SHOW_SHARED_RISKS) : [];
			$digiriskDocument->fillRiskData($odfHandler, $this, $outputLangs, [], null, $risks);

			$moreParam['task_type'] = 'cur_task';
			$this->setTaskSegment($odfHandler, $outputLangs, $moreParam);
			$moreParam['task_type'] = 'prev_task';
			$this->setTaskSegment($odfHandler, $outputLangs, $moreParam);
		} catch (OdfException $e) {
			$this->error = $e->getMessage();
			dol_syslog($this->error, LOG_WARNING);
			return -1;
		}
		return 0;
	}

	/**
	 * Function to build a document on disk.
	 *
	 * @param  SaturneDocuments $objectDocument  Object source to build document.
	 * @param  Translate        $outputLangs     Lang object to use for output.
	 * @param  string           $srcTemplatePath Full path of source filename for generator using a template file.
	 * @param  int              $hideDetails     Do not show line details.
	 * @param  int              $hideDesc        Do not show desc.
	 * @param  int              $hideRef         Do not show ref.
	 * @param  array            $moreParam       More param (Object/user/etc).
	 * @return int                               1 if OK, <=0 if KO.
	 * @throws Exception
	 */
	public function write_file(SaturneDocuments $objectDocument, Translate $outputLangs, string $srcTemplatePath, int $hideDetails = 0, int $hideDesc = 0, int $hideRef = 0, array $moreParam): int
	{
		require_once __DIR__ . '/../../../../../../saturne/class/task/saturnetask.class.php';
		require_once __DIR__ . '/../../../../../class/accident.class.php';

		global $conf;

		$object           = $moreParam['object'];
		$accident         = new Accident($this->db);
		$accidentMetadata = new AccidentMetaData($this->db);
		$victim           = new User($this->db);
		$signatory        = new SaturneSignature($this->db, $this->module, $object->element);
		$now              = dol_now();

		$accident->fetch($object->fk_accident);
		$accidentMetadata->fetch(0, '', 'AND status = 1 AND fk_accident = ' . $accident->id);
		$victim->fetch($accident->fk_user_victim);

		$actionTask           = saturne_fetch_all_object_type('SaturneTask', '', '', 2, 0, ['customsql' => 'fk_task_parent = ' . $object->fk_task]);
		$curativeActionTask   = array_shift($actionTask);
		$preventiveActionTask = array_pop($actionTask);
		$totalCATask          = $curativeActionTask->hasChildren();
		$totalPATask          = $preventiveActionTask->hasChildren();
		$totalBudget          = get_recursive_task_budget($object->fk_task);

		$moreParam['curativeTaskId']   = $curativeActionTask->id;
		$moreParam['preventiveTaskId'] = $preventiveActionTask->id;

		$tmpArray['investigation_date_start'] = dol_print_date($object->date_start, 'dayhour', 'tzuser');
		$tmpArray['investigation_date_end']   = dol_print_date($object->date_end, 'dayhour', 'tzuser');
		$tmpArray['total_curative_action']    = $totalCATask > 0 ? $totalCATask : '0 ';
		$tmpArray['total_preventive_action']  = $totalPATask > 0 ? $totalPATask : '0 ';
		$tmpArray['total_planned_budget']     = price($totalBudget,0, '', 1, -1, -1, 'auto');

		$signatoriesArray = $signatory->fetchSignatories($moreParam['object']->id, $moreParam['object']->element);
		if (!empty($signatoriesArray) && is_array($signatoriesArray)) {
			$tmpArray['attendants_number'] = count($signatoriesArray);
		} else {
			$tmpArray['attendants_number'] = '0 ';
		}

		$tmpArray['mycompany_siret']   = $conf->global->MAIN_INFO_SIRET;
		$tmpArray['mycompany_contact'] = $conf->global->MAIN_INFO_SOCIETE_MANAGERS;
		$tmpArray['mycompany_mail']    = $conf->global->MAIN_INFO_SOCIETE_MAIL;

		$tmpArray['victim_lastname']  = dol_strtoupper($victim->lastname);
		$tmpArray['victim_firstname'] = ucfirst($victim->firstname);

		if ($object->seniority_at_post > 0) {
			$daysAtPost                    = dol_time_plus_duree($now, -$object->seniority_at_post, 's');
			$daysAtPost                    = round($daysAtPost / 60 / 60 / 24);
			$tmpArray['seniority_at_post'] = dol_print_date($object->seniority_at_post, 'day', 'tzuser') . ' - ' . $daysAtPost . ' ' . ($daysAtPost <= 1 ? $outputLangs->trans('Day') : $outputLangs->trans('Days'));
		} else {
			$tmpArray['seniority_at_post'] = '';
		}
		if ($victim->dateemployment > 0) {
			$daysEmployee                       = dol_time_plus_duree($now, -$victim->dateemployment, 's');
			$daysEmployee                       = round($daysEmployee / 60 / 60 / 24);
			$tmpArray['victim_date_employment'] = dol_print_date($victim->dateemployment, 'day', 'tzuser') . ' - ' . $daysEmployee . ' ' . ($daysEmployee <= 1 ? $outputLangs->trans('Day') : $outputLangs->trans('Days'));
		} else {
			$tmpArray['victim_date_employment'] = '';
		}
		$tmpArray['accident_date'] = dol_print_date($accident->accident_date, 'day');
		$tmpArray['accident_hour'] = dol_print_date($accident->accident_date, 'hour');
		$tmpArray['accident_day']  = dol_print_date($accident->accident_date, '%A');

		if ($accident->external_accident == 1) {
			if ($accident->fk_element > 0) {
				$element = new DigiriskElement($this->db);
				$element->fetch($accident->fk_element);
				$tmpArray['gp_ut']     = $element->ref . ' - ' . $element->label;
				$moreParam['gp_ut_id'] = $accident->fk_element;
			} else {
				$element = new DigiriskStandard($this->db);
				$element->fetch($accident->fk_standard);
				$tmpArray['gp_ut'] = $element->ref . ' - ' . $conf->global->MAIN_INFO_SOCIETE_NOM;
			}
		} else if ($accident->external_accident == 2) {
			$societe = new Societe($this->db);
			$societe->fetch($accident->fk_soc);
			$tmpArray['gp_ut'] = $societe->name;
		} else {
			$tmpArray['gp_ut'] = $accident->accident_location;
		}

		$tmpArray['victim_skills']        = $object->victim_skills;
		$tmpArray['collective_equipment'] = $object->collective_equipment;
		$tmpArray['individual_equipment'] = $object->individual_equipment;
		$tmpArray['circumstances']        = $object->circumstances;
		$tmpArray['public_note']          = $object->note_public;
		$tmpArray['relative_location']    = $accidentMetadata->relative_location;

		$pathPhoto                        = $conf->digiriskdolibarr->multidir_output[$conf->entity] . '/accident_investigation/'. $object->ref . '/causality_tree/thumbs/';
		$causalityTreePath                = $pathPhoto . saturne_get_thumb_name($object->causality_tree, 'medium');
		$tmpArray['causality_tree_photo'] = $causalityTreePath;

		$moreParam['tmparray'] = $tmpArray;

		return parent::write_file($objectDocument, $outputLangs, $srcTemplatePath, $hideDetails, $hideDesc, $hideRef, $moreParam);
	}
}
