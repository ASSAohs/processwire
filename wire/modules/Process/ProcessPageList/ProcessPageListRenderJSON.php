<?php namespace ProcessWire;

require_once(dirname(__FILE__) . '/ProcessPageListRender.php');

/**
 * JSON implementation of the Page List rendering
 *
 */
class ProcessPageListRenderJSON extends ProcessPageListRender {

	protected $systemIDs = array();
	
	protected $allowTrash = null;

	public function __construct(Page $page, PageArray $children) {

		parent::__construct($page, $children);

		$this->systemIDs = array(
			$this->config->http404PageID,
			$this->config->adminRootPageID,
			$this->config->trashPageID,
			$this->config->loginPageID,
		);
	}

	/**
	 * Are we allowed to display the Trash page?
	 * 
	 * @return bool
	 * 
	 */
	protected function allowTrash() {
		
		if($this->allowTrash !== null) return $this->allowTrash;
		
		/** @var User $user */
		$user = $this->wire('user');
		
		$petc = 'page-edit-trash-created';
		if(!$this->wire('permissions')->has($petc)) $petc = false;
		
		if($user->isSuperuser()) {
			// superuser
			$this->allowTrash = true;
		} else if($user->hasPermission('page-delete')) {
			// has page-delete globally
			$this->allowTrash = true;
		} else if($petc && $user->hasPermission($petc)) {
			// has page-edit-trash-created globally
			$this->allowTrash = true;
		} else if($user->hasPermission('page-delete', true)) {
			// has page-delete added specifically at a template
			$this->allowTrash = true; 
		} else if($petc && $user->hasPermission($petc, true)) {
			// has page-edit-trash-created added specifically at a template
			$this->allowTrash = true; 
		} else {
			$this->allowTrash = false;
		}
		
		return $this->allowTrash;
	}

	public function renderChild(Page $page) {

		$outputFormatting = $page->outputFormatting;
		$page->setOutputFormatting(true);
		$class = '';
		$type = '';
		$note = '';
		$label = '';
		$icons = array();

		if(in_array($page->id, $this->systemIDs)) {
			$type = 'System';
			if($page->id == $this->config->http404PageID) $label = $this->_('404 Page Not Found'); // Label for '404 Page Not Found' page in PageList // Overrides page title if used
			else if($page->id == $this->config->adminRootPageID) $label = $this->_('Admin'); // Label for 'Admin' page in PageList // Overrides page title if used
			else if($page->id == $this->config->trashPageID && isset($this->actionLabels['trash'])) $label = $this->actionLabels['trash']; // Label for 'Trash' page in PageList // Overrides page title if used
			// if label is not overridden by a language pack, make $label blank to use the page title instead
			if(in_array($label, array('Trash', 'Admin', '404 Page Not Found'))) $label = '';
		}

		if($page->getAccessParent() === $page && $page->parent->id) {
			$accessTemplate = $page->getAccessTemplate();
			if($accessTemplate && $accessTemplate->hasRole('guest')) {
				$accessTemplate = $page->parent->getAccessTemplate();
				if($accessTemplate && !$accessTemplate->hasRole('guest') && !$page->isTrash()) {
					$class .= ' PageListAccessOn';
					$icons[] = 'key fa-flip-horizontal';
				}
			} else {
				$accessTemplate = $page->parent->getAccessTemplate();
				if($accessTemplate && $accessTemplate->hasRole('guest')) {
					$class .= ' PageListAccessOff';
					$icons[] = 'key';
				}
			}
		}

		if($page->id == $this->config->trashPageID) {
			$note = '';
			if($this->superuser) {
				$note = "&lt; " . $this->_("Trash open: drag pages below here to trash them"); // Message that appears next to the Trash page when open
			}
			$icons = array('trash-o'); // override any other icons
			$numChildren = $page->numChildren(false);
			if($numChildren > 0 && !$this->superuser) {
				// manually count quantity that are listable in the trash
				$numChildren = 0;
				foreach($page->children("include=all") as $child) {
					if($child->listable()) $numChildren++;
				}
			}
		} else {
			if($page->hasStatus(Page::statusTemp)) $icons[] = 'bolt';
			if($page->hasStatus(Page::statusLocked)) $icons[] = 'lock';
			if($page->hasStatus(Page::statusDraft)) $icons[] = 'paperclip';
			$numChildren = $page->numChildren(1);
		}

		if(!$label) $label = $this->getPageLabel($page);
		
		if(count($icons)) foreach($icons as $n => $icon) {
			$label .= "<i class='PageListStatusIcon fa fa-fw fa-$icon'></i>";
		}

		$a = array(
			'id' => $page->id,
			'label' => $label,
			'status' => $page->status,
			'numChildren' => $numChildren,
			'path' => $page->template->slashUrls || $page->id == 1 ? $page->path() : rtrim($page->path(), '/'),
			'template' => $page->template->name,
			//'rm' => $this->superuser && $page->trashable(),
			'actions' => array_values($this->getPageActions($page)),
		);

		if($class) $a['addClass'] = trim($class);
		if($type) $a['type'] = $type;
		if($note) $a['note'] = $note;


		$page->setOutputFormatting($outputFormatting);

		return $a;
	}

	public function render() {

		$children = array();
		$extraPages = array(); // pages forced to bottom of list
		$config = $this->wire('config');
		$idTrash = $config->trashPageID;
		$id404 = $config->http404PageID;
		$page404 = null;

		foreach($this->children as $page) {
			if(!$this->superuser && !$page->listable()) continue;

			if($page->id == $id404 && !$this->superuser) {
				// allow showing 404 page, only if it's editable
				$page404 = $page;
				if(!$page->editable()) continue;
			} else if(in_array($page->id, $this->systemIDs)) {
				if($this->superuser) $extraPages[$page->id] = $page;
				continue;
			}

			$child = $this->renderChild($page);
			$children[] = $child;
		}
		
		if(!$this->superuser && $page404 && $page404->parent_id == 1 && !isset($extraPages[$idTrash])) {
			$pageTrash = $this->wire('pages')->get($idTrash);
			if($pageTrash->id && $pageTrash->listable()) $extraPages[$pageTrash->id] = $pageTrash;
		}

		foreach($extraPages as $page) {
			$children[] = $this->renderChild($page);
		}

		$json = array(
			'page' => $this->renderChild($this->page),
			'children' => $children,
			'start' => $this->start,
			'limit' => $this->limit,
		);

		if($this->getOption('getArray')) return $json;
		header("Content-Type: application/json;");
		return json_encode($json);
	}

}
