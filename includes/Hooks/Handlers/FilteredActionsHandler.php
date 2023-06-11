<?php

namespace MediaWiki\Extension\AbuseFilter\Hooks\Handlers;

use ApiMessage;
use Content;
use DeferredUpdates;
use IBufferingStatsdDataFactory;
use IContextSource;
use MediaWiki\Extension\AbuseFilter\BlockedDomainStorage;
use MediaWiki\Extension\AbuseFilter\EditRevUpdater;
use MediaWiki\Extension\AbuseFilter\FilterRunnerFactory;
use MediaWiki\Extension\AbuseFilter\VariableGenerator\VariableGeneratorFactory;
use MediaWiki\Extension\AbuseFilter\Variables\UnsetVariableException;
use MediaWiki\Extension\AbuseFilter\Variables\VariableHolder;
use MediaWiki\Extension\AbuseFilter\Variables\VariablesManager;
use MediaWiki\Hook\EditFilterMergedContentHook;
use MediaWiki\Hook\TitleMoveHook;
use MediaWiki\Hook\UploadStashFileHook;
use MediaWiki\Hook\UploadVerifyUploadHook;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\Page\Hook\ArticleDeleteHook;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Storage\Hook\ParserOutputStashForEditHook;
use MediaWiki\Utils\UrlUtils;
use Message;
use Status;
use Title;
use UploadBase;
use User;
use WikiPage;

/**
 * Handler for actions that can be filtered
 */
class FilteredActionsHandler implements
	EditFilterMergedContentHook,
	TitleMoveHook,
	ArticleDeleteHook,
	UploadVerifyUploadHook,
	UploadStashFileHook,
	ParserOutputStashForEditHook
{
	/** @var IBufferingStatsdDataFactory */
	private $statsDataFactory;
	/** @var FilterRunnerFactory */
	private $filterRunnerFactory;
	/** @var VariableGeneratorFactory */
	private $variableGeneratorFactory;
	/** @var EditRevUpdater */
	private $editRevUpdater;
	private VariablesManager $variablesManager;
	private BlockedDomainStorage $blockedDomainStorage;
	private UrlUtils $urlUtils;
	private PermissionManager $permissionManager;

	/**
	 * @param IBufferingStatsdDataFactory $statsDataFactory
	 * @param FilterRunnerFactory $filterRunnerFactory
	 * @param VariableGeneratorFactory $variableGeneratorFactory
	 * @param EditRevUpdater $editRevUpdater
	 * @param VariablesManager $variablesManager
	 * @param BlockedDomainStorage $blockedDomainStorage
	 * @param UrlUtils $urlUtils
	 * @param PermissionManager $permissionManager
	 */
	public function __construct(
		IBufferingStatsdDataFactory $statsDataFactory,
		FilterRunnerFactory $filterRunnerFactory,
		VariableGeneratorFactory $variableGeneratorFactory,
		EditRevUpdater $editRevUpdater,
		VariablesManager $variablesManager,
		BlockedDomainStorage $blockedDomainStorage,
		UrlUtils $urlUtils,
		PermissionManager $permissionManager
	) {
		$this->statsDataFactory = $statsDataFactory;
		$this->filterRunnerFactory = $filterRunnerFactory;
		$this->variableGeneratorFactory = $variableGeneratorFactory;
		$this->editRevUpdater = $editRevUpdater;
		$this->variablesManager = $variablesManager;
		$this->blockedDomainStorage = $blockedDomainStorage;
		$this->urlUtils = $urlUtils;
		$this->permissionManager = $permissionManager;
	}

	/**
	 * @inheritDoc
	 * @param string $slot Slot role for the content, added by Wikibase (T288885)
	 */
	public function onEditFilterMergedContent(
		IContextSource $context,
		Content $content,
		Status $status,
		$summary,
		User $user,
		$minoredit,
		string $slot = SlotRecord::MAIN
	) {
		$startTime = microtime( true );
		if ( !$status->isOK() ) {
			// Investigate what happens if we skip filtering here (T211680)
			LoggerFactory::getInstance( 'AbuseFilter' )->info(
				'Status is already not OK',
				[ 'status' => (string)$status ]
			);
		}

		$filterResult = $this->filterEdit( $context, $user, $content, $summary, $slot );

		if ( !$filterResult->isOK() ) {
			// Produce a useful error message for API edits
			$filterResultApi = self::getApiStatus( $filterResult );
			$status->merge( $filterResultApi );
		}
		$this->statsDataFactory->timing( 'timing.editAbuseFilter', microtime( true ) - $startTime );

		return $status->isOK();
	}

	/**
	 * Implementation for EditFilterMergedContent hook.
	 *
	 * @param IContextSource $context the context of the edit
	 * @param User $user
	 * @param Content $content the new Content generated by the edit
	 * @param string $summary Edit summary for page
	 * @param string $slot slot role for the content
	 * @return Status
	 */
	private function filterEdit(
		IContextSource $context,
		User $user,
		Content $content,
		string $summary,
		string $slot = SlotRecord::MAIN
	): Status {
		$this->editRevUpdater->clearLastEditPage();

		$title = $context->getTitle();
		$logger = LoggerFactory::getInstance( 'AbuseFilter' );
		if ( $title === null ) {
			// T144265: This *should* never happen.
			$logger->warning( __METHOD__ . ' received a null title.' );
			return Status::newGood();
		}
		if ( !$title->canExist() ) {
			// This also should be handled in EditPage or whoever is calling the hook.
			$logger->warning( __METHOD__ . ' received a Title that cannot exist.' );
			// Note that if the title cannot exist, there's no much point in filtering the edit anyway
			return Status::newGood();
		}

		$page = $context->getWikiPage();

		$builder = $this->variableGeneratorFactory->newRunGenerator( $user, $title );
		$vars = $builder->getEditVars( $content, $summary, $slot, $page );
		if ( $vars === null ) {
			// We don't have to filter the edit
			return Status::newGood();
		}
		$runner = $this->filterRunnerFactory->newRunner( $user, $title, $vars, 'default' );
		$filterResult = $runner->run();
		if ( !$filterResult->isOK() ) {
			return $filterResult;
		}

		$this->editRevUpdater->setLastEditPage( $page );

		if ( $this->permissionManager->userHasRight( $user, 'abusefilter-bypass-blocked-external-domains' ) ) {
			return Status::newGood();
		}
		$blockedDomainFilterResult = $this->blockedDomainFilter( $vars );
		if ( $blockedDomainFilterResult instanceof Status ) {
			return $blockedDomainFilterResult;
		}

		return Status::newGood();
	}

	/**
	 * @param VariableHolder $vars variables by the action
	 * @return Status|bool Status if it's a match and false if not
	 */
	private function blockedDomainFilter( VariableHolder $vars ) {
		global $wgAbuseFilterEnableBlockedExternalDomain;
		if ( !$wgAbuseFilterEnableBlockedExternalDomain ) {
			return false;
		}
		try {
			$urls = $this->variablesManager->getVar( $vars, 'added_links', VariablesManager::GET_STRICT );
		} catch ( UnsetVariableException $_ ) {
			return false;
		}

		$addedDomains = [];
		foreach ( $urls->toArray() as $addedUrl ) {
			$parsedUrl = $this->urlUtils->parse( (string)$addedUrl->getData() );
			if ( !$parsedUrl ) {
				continue;
			}
			// Given that we block subdomains of blocked domains too
			// pretend that all of higher-level domains are added as well
			// so for foo.bar.com, you will have three domains to check:
			// foo.bar.com, bar.com, and com
			// This saves string search in the large list of blocked domains
			// making it much faster.
			$domainString = '';
			foreach ( array_reverse( explode( '.', $parsedUrl['host'] ) ) as $domainPiece ) {
				if ( !$domainString ) {
					$domainString = $domainPiece;
				} else {
					$domainString = $domainPiece . '.' . $domainString;
				}
				// It should be a map, benchmark at https://phabricator.wikimedia.org/P48956
				$addedDomains[$domainString] = true;
			}
		}
		if ( !$addedDomains ) {
			return false;
		}
		$blockedDomains = $this->blockedDomainStorage->loadComputed();
		$blockedDomainsAdded = array_intersect_key( $addedDomains, $blockedDomains );
		if ( !$blockedDomainsAdded ) {
			return false;
		}
		$blockedDomainsAdded = array_keys( $blockedDomainsAdded );
		$error = Message::newFromSpecifier( 'abusefilter-blocked-domains-attempted' );
		$error->params( Message::listParam( $blockedDomainsAdded ) );

		$status = Status::newFatal( $error, 'blockeddomain', 'blockeddomain' );
		$status->value['blockeddomain'] = [ 'disallow' ];
		return $status;
	}

	/**
	 * @param Status $status Error message details
	 * @return Status Status containing the same error messages with extra data for the API
	 */
	private static function getApiStatus( Status $status ): Status {
		$allActionsTaken = $status->getValue();
		$statusForApi = Status::newGood();

		foreach ( $status->getErrors() as $error ) {
			[ $filterDescription, $filter ] = $error['params'];
			$actionsTaken = $allActionsTaken[ $filter ];

			$code = ( $actionsTaken === [ 'warn' ] ) ? 'abusefilter-warning' : 'abusefilter-disallowed';
			$data = [
				'abusefilter' => [
					'id' => $filter,
					'description' => $filterDescription,
					'actions' => $actionsTaken,
				],
			];

			$message = ApiMessage::create( $error, $code, $data );
			$statusForApi->fatal( $message );
		}

		return $statusForApi;
	}

	/**
	 * @inheritDoc
	 */
	public function onTitleMove( Title $old, Title $nt, User $user, $reason, Status &$status ) {
		$builder = $this->variableGeneratorFactory->newRunGenerator( $user, $old );
		$vars = $builder->getMoveVars( $nt, $reason );
		$runner = $this->filterRunnerFactory->newRunner( $user, $old, $vars, 'default' );
		$result = $runner->run();
		$status->merge( $result );
	}

	/**
	 * @inheritDoc
	 */
	public function onArticleDelete( WikiPage $wikiPage, User $user, &$reason, &$error, Status &$status, $suppress ) {
		if ( $suppress ) {
			// Don't filter suppressions, T71617
			return true;
		}
		$builder = $this->variableGeneratorFactory->newRunGenerator( $user, $wikiPage->getTitle() );
		$vars = $builder->getDeleteVars( $reason );
		$runner = $this->filterRunnerFactory->newRunner( $user, $wikiPage->getTitle(), $vars, 'default' );
		$filterResult = $runner->run();

		$status->merge( $filterResult );
		$error = $filterResult->isOK() ? '' : $filterResult->getHTML();

		return $filterResult->isOK();
	}

	/**
	 * @inheritDoc
	 */
	public function onUploadVerifyUpload(
		UploadBase $upload,
		User $user,
		?array $props,
		$comment,
		$pageText,
		&$error
	) {
		return $this->filterUpload( 'upload', $upload, $user, $props, $comment, $pageText, $error );
	}

	/**
	 * Filter an upload to stash. If a filter doesn't need to check the page contents or
	 * upload comment, it can use `action='stashupload'` to provide better experience to e.g.
	 * UploadWizard (rejecting files immediately, rather than after the user adds the details).
	 *
	 * @inheritDoc
	 */
	public function onUploadStashFile( UploadBase $upload, User $user, ?array $props, &$error ) {
		return $this->filterUpload( 'stashupload', $upload, $user, $props, null, null, $error );
	}

	/**
	 * Implementation for UploadStashFile and UploadVerifyUpload hooks.
	 *
	 * @param string $action 'upload' or 'stashupload'
	 * @param UploadBase $upload
	 * @param User $user User performing the action
	 * @param array|null $props File properties, as returned by MWFileProps::getPropsFromPath().
	 * @param string|null $summary Upload log comment (also used as edit summary)
	 * @param string|null $text File description page text (only used for new uploads)
	 * @param array|ApiMessage &$error
	 * @return bool
	 */
	private function filterUpload(
		string $action,
		UploadBase $upload,
		User $user,
		?array $props,
		?string $summary,
		?string $text,
		&$error
	): bool {
		$title = $upload->getTitle();
		if ( $title === null ) {
			// T144265: This could happen for 'stashupload' if the specified title is invalid.
			// Let UploadBase warn the user about that, and we'll filter later.
			$logger = LoggerFactory::getInstance( 'AbuseFilter' );
			$logger->warning( __METHOD__ . " received a null title. Action: $action." );
			return true;
		}

		$builder = $this->variableGeneratorFactory->newRunGenerator( $user, $title );
		$vars = $builder->getUploadVars( $action, $upload, $summary, $text, $props );
		if ( $vars === null ) {
			return true;
		}
		$runner = $this->filterRunnerFactory->newRunner( $user, $title, $vars, 'default' );
		$filterResult = $runner->run();

		if ( !$filterResult->isOK() ) {
			// Produce a useful error message for API edits
			$filterResultApi = self::getApiStatus( $filterResult );
			// @todo Return all errors instead of only the first one
			$error = $filterResultApi->getErrors()[0]['message'];
		} else {
			if ( $this->permissionManager->userHasRight( $user, 'abusefilter-bypass-blocked-external-domains' ) ) {
				return true;
			}
			$blockedDomainFilterResult = $this->blockedDomainFilter( $vars );
			if ( $blockedDomainFilterResult instanceof Status ) {
				$error = $blockedDomainFilterResult->getErrors()[0]['message'];
				return $blockedDomainFilterResult->isOK();
			}
		}

		return $filterResult->isOK();
	}

	/**
	 * @inheritDoc
	 */
	public function onParserOutputStashForEdit( $page, $content, $output, $summary, $user ) {
		// XXX: This makes the assumption that this method is only ever called for the main slot.
		// Which right now holds true, but any more fancy MCR stuff will likely break here...
		$slot = SlotRecord::MAIN;

		// Cache any resulting filter matches.
		// Do this outside the synchronous stash lock to avoid any chance of slowdown.
		DeferredUpdates::addCallableUpdate(
			function () use (
				$user,
				$page,
				$summary,
				$content,
				$slot
			) {
				$startTime = microtime( true );
				$generator = $this->variableGeneratorFactory->newRunGenerator( $user, $page->getTitle() );
				$vars = $generator->getStashEditVars( $content, $summary, $slot, $page );
				if ( !$vars ) {
					return;
				}
				$runner = $this->filterRunnerFactory->newRunner( $user, $page->getTitle(), $vars, 'default' );
				$runner->runForStash();
				$totalTime = microtime( true ) - $startTime;
				$this->statsDataFactory->timing( 'timing.stashAbuseFilter', $totalTime );
			},
			DeferredUpdates::PRESEND
		);
	}
}
