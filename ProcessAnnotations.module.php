<?php namespace ProcessWire;

/**
 * Annotations Settings (Process)
 *
 * Adds a page under Setup so clients can edit the Annotations textformatter
 * settings without access to the Modules section. Guarded by its own
 * permission ("annotations-edit"). Renders the textformatter's own config
 * inputfields and saves them back to its module config.
 *
 * Copyright 2026 by frameless Media
 * Licensed under MIT
 *
 */

class ProcessAnnotations extends Process {

	const targetModule = 'TextformatterAnnotations';

	public static function getModuleInfo() {
		return array(
			'title' => 'Annotations Settings',
			'version' => 1,
			'summary' => 'Client-editable settings page for the Annotations textformatter (under Setup).',
			'author' => 'frameless Media',
			'icon' => 'asterisk',
			'requires' => array('TextformatterAnnotations'),
			// auto-create the permission on install
			'permission' => 'annotations-edit',
			'permissions' => array('annotations-edit' => 'Edit Annotations textformatter settings'),
			// auto-create (and remove) the admin page under Setup
			'page' => array(
				'name' => 'annotations',
				'parent' => 'setup',
				'title' => 'Annotations',
			),
		);
	}

	/**
	 * Render (and save) the Annotations settings form
	 *
	 * @return string
	 *
	 */
	public function ___execute() {

		$modules = $this->wire()->modules;
		$input = $this->wire()->input;
		$name = self::targetModule;

		if(!$modules->isInstalled($name)) {
			return '<p>' . $this->_('The Annotations textformatter is not installed.') . '</p>';
		}

		/** @var TextformatterAnnotations $tf */
		$tf = $modules->get($name);

		/** @var InputfieldForm $form */
		$form = $modules->get('InputfieldForm');
		$form->attr('action', './');
		$form->attr('method', 'post');
		$form->add($tf->getModuleConfigInputfields($modules->getModuleConfigData($name)));

		/** @var InputfieldSubmit $submit */
		$submit = $modules->get('InputfieldSubmit');
		$submit->attr('name', 'submit_save');
		$submit->attr('value', $this->_('Save'));
		$form->add($submit);

		if($input->post('submit_save')) {
			$form->processInput($input->post);

			// collect every named (non-wrapper) field value into the config data
			$config = array();
			foreach($form->getAll() as $f) {
				if($f instanceof InputfieldWrapper) continue;
				$fname = $f->attr('name');
				if($fname === '' || $fname === 'submit_save') continue;
				$config[$fname] = $f->attr('value');
			}

			$modules->saveModuleConfigData($name, $config);
			$this->message($this->_('Annotations settings saved.'));
			$this->wire()->session->redirect('./');
		}

		return $form->render();
	}
}
