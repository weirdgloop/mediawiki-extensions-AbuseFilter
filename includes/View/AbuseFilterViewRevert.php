<?php

namespace MediaWiki\Extension\AbuseFilter\View;

use AbuseFilter;
use HTMLForm;
use IContextSource;
use Linker;
use ManualLogEntry;
use MediaWiki\Block\DatabaseBlock;
use MediaWiki\Extension\AbuseFilter\AbuseFilterPermissionManager;
use MediaWiki\Extension\AbuseFilter\BlockAutopromoteStore;
use MediaWiki\Extension\AbuseFilter\FilterUser;
use MediaWiki\Extension\AbuseFilter\VariablesBlobStore;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\User\UserGroupManager;
use Message;
use MWException;
use PermissionsError;
use SpecialPage;
use Title;
use User;
use UserBlockedError;
use Xml;

class AbuseFilterViewRevert extends AbuseFilterView {
	/** @var int */
	private $filter;
	/**
	 * @var string The start time of the lookup period
	 */
	public $origPeriodStart;
	/**
	 * @var string The end time of the lookup period
	 */
	public $origPeriodEnd;
	/**
	 * @var string|null The same as $origPeriodStart
	 */
	public $mPeriodStart;
	/**
	 * @var string|null The same as $origPeriodEnd
	 */
	public $mPeriodEnd;
	/**
	 * @var string|null The reason provided for the revert
	 */
	public $mReason;
	/**
	 * @var UserGroupManager
	 */
	private $userGroupsManager;
	/**
	 * @var BlockAutopromoteStore
	 */
	private $blockAutopromoteStore;
	/**
	 * @var FilterUser
	 */
	private $filterUser;
	/**
	 * @var VariablesBlobStore
	 */
	private $varBlobStore;

	/**
	 * @param UserGroupManager $userGroupManager
	 * @param AbuseFilterPermissionManager $afPermManager
	 * @param BlockAutopromoteStore $blockAutopromoteStore
	 * @param FilterUser $filterUser
	 * @param VariablesBlobStore $varBlobStore
	 * @param IContextSource $context
	 * @param LinkRenderer $linkRenderer
	 * @param string $basePageName
	 * @param array $params
	 */
	public function __construct(
		UserGroupManager $userGroupManager,
		AbuseFilterPermissionManager $afPermManager,
		BlockAutopromoteStore $blockAutopromoteStore,
		FilterUser $filterUser,
		VariablesBlobStore $varBlobStore,
		IContextSource $context,
		LinkRenderer $linkRenderer,
		string $basePageName,
		array $params
	) {
		parent::__construct( $afPermManager, $context, $linkRenderer, $basePageName, $params );
		$this->userGroupsManager = $userGroupManager;
		$this->blockAutopromoteStore = $blockAutopromoteStore;
		$this->filterUser = $filterUser;
		$this->varBlobStore = $varBlobStore;
	}

	/**
	 * Shows the page
	 */
	public function show() {
		$lang = $this->getLanguage();

		$user = $this->getUser();
		$out = $this->getOutput();

		if ( !$this->afPermManager->canRevertFilterActions( $user ) ) {
			throw new PermissionsError( 'abusefilter-revert' );
		}

		$block = $user->getBlock();
		if ( $block && $block->isSitewide() ) {
			throw new UserBlockedError( $block );
		}

		$this->loadParameters();

		if ( $this->attemptRevert() ) {
			return;
		}

		$filter = $this->filter;

		$out->addWikiMsg( 'abusefilter-revert-intro', Message::numParam( $filter ) );
		$out->setPageTitle( $this->msg( 'abusefilter-revert-title' )->numParams( $filter ) );

		// First, the search form. Limit dates to avoid huge queries
		$RCMaxAge = $this->getConfig()->get( 'RCMaxAge' );
		$min = wfTimestamp( TS_ISO_8601, time() - $RCMaxAge );
		$max = wfTimestampNow();
		$filterLink =
			$this->linkRenderer->makeLink(
				$this->getTitle( $filter ),
				$lang->formatNum( $filter )
			);
		$searchFields = [];
		$searchFields['filterid'] = [
			'type' => 'info',
			'default' => $filterLink,
			'raw' => true,
			'label-message' => 'abusefilter-revert-filter'
		];
		$searchFields['periodstart'] = [
			'type' => 'datetime',
			'name' => 'wpPeriodStart',
			'default' => $this->origPeriodStart,
			'label-message' => 'abusefilter-revert-periodstart',
			'min' => $min,
			'max' => $max
		];
		$searchFields['periodend'] = [
			'type' => 'datetime',
			'name' => 'wpPeriodEnd',
			'default' => $this->origPeriodEnd,
			'label-message' => 'abusefilter-revert-periodend',
			'min' => $min,
			'max' => $max
		];

		HTMLForm::factory( 'ooui', $searchFields, $this->getContext() )
			->setAction( $this->getTitle( "revert/$filter" )->getLocalURL() )
			->setWrapperLegendMsg( 'abusefilter-revert-search-legend' )
			->setSubmitTextMsg( 'abusefilter-revert-search' )
			->setMethod( 'get' )
			->setFormIdentifier( 'revert-select-date' )
			->setSubmitCallback( [ $this, 'showRevertableActions' ] )
			->showAlways();
	}

	/**
	 * Show revertable actions, called as submit callback by HTMLForm
	 * @param array $formData
	 * @param HTMLForm $dateForm
	 * @return bool
	 */
	public function showRevertableActions( array $formData, HTMLForm $dateForm ) : bool {
		$lang = $this->getLanguage();
		$user = $this->getUser();
		$filter = $this->filter;

		// Look up all of them.
		$results = $this->doLookup();
		if ( $results === [] ) {
			$dateForm->addPostText( $this->msg( 'abusefilter-revert-preview-no-results' )->escaped() );
			return true;
		}

		// Add a summary of everything that will be reversed.
		$dateForm->addPostText( $this->msg( 'abusefilter-revert-preview-intro' )->parseAsBlock() );
		$list = [];

		$context = $this->getContext();
		foreach ( $results as $result ) {
			$displayActions = [];
			foreach ( $result['actions'] as $action ) {
				$displayActions[] = AbuseFilter::getActionDisplay( $action, $context );
			}

			$msg = $this->msg( 'abusefilter-revert-preview-item' )
				->params(
					$lang->timeanddate( $result['timestamp'], true )
				)->rawParams(
					Linker::userLink( $result['userid'], $result['user'] )
				)->params(
					$result['action']
				)->rawParams(
					$this->linkRenderer->makeLink( $result['title'] )
				)->params(
					$lang->commaList( $displayActions )
				)->rawParams(
					$this->linkRenderer->makeLink(
						SpecialPage::getTitleFor( 'AbuseLog' ),
						$this->msg( 'abusefilter-log-detailslink' )->text(),
						[],
						[ 'details' => $result['id'] ]
					)
				)->params( $result['user'] )->parse();
			$list[] = Xml::tags( 'li', null, $msg );
		}

		$dateForm->addPostText( Xml::tags( 'ul', null, implode( "\n", $list ) ) );

		// Add a button down the bottom.
		$confirmForm = [];
		$confirmForm['edittoken'] = [
			'type' => 'hidden',
			'name' => 'editToken',
			'default' => $user->getEditToken( "abusefilter-revert-$filter" )
		];
		$confirmForm['title'] = [
			'type' => 'hidden',
			'name' => 'title',
			'default' => $this->getTitle( "revert/$filter" )->getPrefixedDBkey()
		];
		$confirmForm['wpPeriodStart'] = [
			'type' => 'hidden',
			'name' => 'wpPeriodStart',
			'default' => $this->origPeriodStart
		];
		$confirmForm['wpPeriodEnd'] = [
			'type' => 'hidden',
			'name' => 'wpPeriodEnd',
			'default' => $this->origPeriodEnd
		];
		$confirmForm['reason'] = [
			'type' => 'text',
			'label-message' => 'abusefilter-revert-reasonfield',
			'name' => 'wpReason',
			'id' => 'wpReason',
		];

		$revertForm = HTMLForm::factory( 'ooui', $confirmForm, $this->getContext() )
			->setAction( $this->getTitle( "revert/$filter" )->getLocalURL() )
			->setWrapperLegendMsg( 'abusefilter-revert-confirm-legend' )
			->setSubmitTextMsg( 'abusefilter-revert-confirm' )
			->prepareForm()
			->getHTML( true );
		$dateForm->addPostText( $revertForm );

		return true;
	}

	/**
	 * @return array[]
	 */
	public function doLookup() {
		$aflFilterMigrationStage = $this->getConfig()->get( 'AbuseFilterAflFilterMigrationStage' );
		$periodStart = $this->mPeriodStart;
		$periodEnd = $this->mPeriodEnd;
		$filter = $this->filter;
		$dbr = wfGetDB( DB_REPLICA );

		$conds = [];

		if ( $aflFilterMigrationStage & SCHEMA_COMPAT_READ_NEW ) {
			// Only hits from local filters can be reverted
			$conds['afl_filter_id'] = $filter;
			$conds['afl_global'] = 0;
		} else {
			// SCHEMA_COMPAT_READ_OLD
			$conds['afl_filter'] = $filter;
		}

		if ( $periodStart !== null ) {
			$conds[] = 'afl_timestamp >= ' . $dbr->addQuotes( $dbr->timestamp( $periodStart ) );
		}
		if ( $periodEnd !== null ) {
			$conds[] = 'afl_timestamp <= ' . $dbr->addQuotes( $dbr->timestamp( $periodEnd ) );
		}

		$selectFields = [
			'afl_id',
			'afl_user',
			'afl_user_text',
			'afl_action',
			'afl_actions',
			'afl_var_dump',
			'afl_timestamp',
			'afl_namespace',
			'afl_title',
			'afl_wiki',
		];
		$res = $dbr->select( 'abuse_filter_log', $selectFields, $conds, __METHOD__ );

		$results = [];
		foreach ( $res as $row ) {
			// Don't revert if there was no action, or the action was global
			if ( !$row->afl_actions || $row->afl_wiki != null ) {
				continue;
			}

			$actions = explode( ',', $row->afl_actions );
			$reversibleActions = [ 'block', 'blockautopromote', 'degroup' ];
			$currentReversibleActions = array_intersect( $actions, $reversibleActions );
			if ( count( $currentReversibleActions ) ) {
				$results[] = [
					'id' => $row->afl_id,
					'actions' => $currentReversibleActions,
					'user' => $row->afl_user_text,
					'userid' => $row->afl_user,
					'vars' => $this->varBlobStore->loadVarDump( $row->afl_var_dump ),
					'title' => Title::makeTitle( $row->afl_namespace, $row->afl_title ),
					'action' => $row->afl_action,
					'timestamp' => $row->afl_timestamp
				];
			}
		}

		return $results;
	}

	/**
	 * Loads parameters from request
	 */
	public function loadParameters() {
		$request = $this->getRequest();

		$this->filter = (int)$this->mParams[1];
		$this->origPeriodStart = $request->getText( 'wpPeriodStart' );
		$this->mPeriodStart = strtotime( $this->origPeriodStart ) ?: null;
		$this->origPeriodEnd = $request->getText( 'wpPeriodEnd' );
		$this->mPeriodEnd = strtotime( $this->origPeriodEnd ) ?: null;
		$this->mReason = $request->getVal( 'wpReason' );
	}

	/**
	 * @return bool
	 */
	public function attemptRevert() {
		$filter = $this->filter;
		$token = $this->getRequest()->getVal( 'editToken' );
		if ( !$this->getUser()->matchEditToken( $token, "abusefilter-revert-$filter" ) ) {
			return false;
		}

		$results = $this->doLookup();
		foreach ( $results as $result ) {
			foreach ( $result['actions'] as $action ) {
				$this->revertAction( $action, $result );
			}
		}
		$this->getOutput()->wrapWikiMsg(
			'<p class="success">$1</p>',
			[
				'abusefilter-revert-success',
				$filter,
				$this->getLanguage()->formatNum( $filter )
			]
		);

		return true;
	}

	/**
	 * @param string $action
	 * @param array $result
	 * @return bool
	 * @throws MWException
	 */
	public function revertAction( $action, $result ) {
		switch ( $action ) {
			case 'block':
				$block = DatabaseBlock::newFromTarget( $result['user'] );
				$filterUser = $this->filterUser->getUser();
				if ( !( $block && $block->getBy() === $filterUser->getId() ) ) {
					// Not blocked by abuse filter
					return false;
				}
				$block->delete();
				$logEntry = new ManualLogEntry( 'block', 'unblock' );
				$logEntry->setTarget( Title::makeTitle( NS_USER, $result['user'] ) );
				$logEntry->setComment(
					$this->msg(
						'abusefilter-revert-reason', $this->filter, $this->mReason
					)->inContentLanguage()->text()
				);
				$logEntry->setPerformer( $this->getUser() );
				$logEntry->publish( $logEntry->insert() );
				return true;
			case 'blockautopromote':
				$target = User::newFromId( $result['userid'] );
				$msg = $this->msg(
					'abusefilter-revert-reason', $this->filter, $this->mReason
				)->inContentLanguage()->text();

				return $this->blockAutopromoteStore->unblockAutopromote( $target, $this->getUser(), $msg );
			case 'degroup':
				// Pull the user's groups from the vars.
				$removedGroups = $result['vars']->getVar( 'user_groups' )->toNative();
				$removedGroups = array_diff( $removedGroups,
					$this->userGroupsManager->listAllImplicitGroups() );
				$user = User::newFromId( $result['userid'] );
				$currentGroups = $this->userGroupsManager->getUserGroups( $user );

				$addedGroups = [];
				foreach ( $removedGroups as $group ) {
					// TODO An addUserToGroups method with bulk updates would be nice
					if ( $this->userGroupsManager->addUserToGroup( $user, $group ) ) {
						$addedGroups[] = $group;
					}
				}

				// Don't log if no groups were added.
				if ( !$addedGroups ) {
					return false;
				}

				// TODO Core should provide a logging method
				$logEntry = new ManualLogEntry( 'rights', 'rights' );
				$logEntry->setTarget( $user->getUserPage() );
				$logEntry->setPerformer( $this->getUser() );
				$logEntry->setComment(
					$this->msg(
						'abusefilter-revert-reason',
						$this->filter,
						$this->mReason
					)->inContentLanguage()->text()
				);
				$logEntry->setParameters( [
					'4::oldgroups' => $currentGroups,
					'5::newgroups' => array_merge( $currentGroups, $addedGroups )
				] );
				$logEntry->publish( $logEntry->insert() );

				return true;
		}

		throw new MWException( "Invalid action $action" );
	}
}