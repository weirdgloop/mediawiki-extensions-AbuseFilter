<?php

use MediaWiki\Extension\AbuseFilter\AbuseFilterServices;
use MediaWiki\Extension\AbuseFilter\Hooks\AbuseFilterHookRunner;
use MediaWiki\Revision\RevisionRecord;

/**
 * This class contains most of the business logic of AbuseFilter. It consists of
 * static functions for generic use (mostly utility functions).
 */
class AbuseFilter {

	/**
	 * @var array IDs of logged filters like [ page title => [ 'local' => [ids], 'global' => [ids] ] ].
	 * @fixme avoid global state
	 */
	public static $logIds = [];

	public const HISTORY_MAPPINGS = [
		'af_pattern' => 'afh_pattern',
		'af_user' => 'afh_user',
		'af_user_text' => 'afh_user_text',
		'af_timestamp' => 'afh_timestamp',
		'af_comments' => 'afh_comments',
		'af_public_comments' => 'afh_public_comments',
		'af_deleted' => 'afh_deleted',
		'af_id' => 'afh_filter',
		'af_group' => 'afh_group',
	];

	/**
	 * Returns an associative array of filters which were tripped
	 *
	 * @param AbuseFilterVariableHolder $vars
	 * @param Title $title
	 * @param string $group The filter's group (as defined in $wgAbuseFilterValidGroups)
	 * @param string $mode 'execute' for edits and logs, 'stash' for cached matches
	 * @return bool[] Map of (integer filter ID => bool)
	 * @deprecated Since 1.34 See comment on FilterRunner::checkAllFilters
	 */
	public static function checkAllFilters(
		AbuseFilterVariableHolder $vars,
		Title $title,
		$group = 'default',
		$mode = 'execute'
	) {
		$parser = AbuseFilterServices::getParserFactory()->newParser( $vars );
		$user = RequestContext::getMain()->getUser();
		$runnerFactory = AbuseFilterServices::getFilterRunnerFactory();
		$runner = $runnerFactory->newRunner( $user, $title, $vars, $group );
		$runner->parser = $parser;
		return $runner->checkAllFilters();
	}

	/**
	 * @param AbuseFilterVariableHolder $vars
	 * @param Title $title
	 * @param string $group The filter's group (as defined in $wgAbuseFilterValidGroups)
	 * @param User $user The user performing the action
	 * @return Status
	 * @deprecated Since 1.34 Build a FilterRunner instance and call run() on that.
	 */
	public static function filterAction(
		AbuseFilterVariableHolder $vars, Title $title, $group, User $user
	) {
		$runnerFactory = AbuseFilterServices::getFilterRunnerFactory();
		$runner = $runnerFactory->newRunner( $user, $title, $vars, $group );
		return $runner->run();
	}

	/**
	 * Look up some text of a revision from its revision id
	 *
	 * Note that this is really *some* text, we do not make *any* guarantee
	 * that this text will be even close to what the user actually sees, or
	 * that the form is fit for any intended purpose.
	 *
	 * Note also that if the revision for any reason is not an Revision
	 * the function returns with an empty string.
	 *
	 * For now, this returns all the revision's slots, concatenated together.
	 * In future, this will be replaced by a better solution. See T208769 for
	 * discussion.
	 *
	 * @internal
	 * @todo Move elsewhere. VariableGenerator is a good candidate
	 *
	 * @param RevisionRecord|null $revision a valid revision
	 * @param User $user the user instance to check for privileged access
	 * @return string the content of the revision as some kind of string,
	 *        or an empty string if it can not be found
	 * @return-taint none
	 */
	public static function revisionToString( ?RevisionRecord $revision, User $user ) {
		if ( !$revision ) {
			return '';
		}

		$strings = [];

		foreach ( $revision->getSlotRoles() as $role ) {
			$content = $revision->getContent( $role, RevisionRecord::FOR_THIS_USER, $user );
			if ( $content === null ) {
				continue;
			}
			$strings[$role] = self::contentToString( $content );
		}

		$result = implode( "\n\n", $strings );
		return $result;
	}

	/**
	 * Converts the given Content object to a string.
	 *
	 * This uses Content::getNativeData() if $content is an instance of TextContent,
	 * or Content::getTextForSearchIndex() otherwise.
	 *
	 * The hook 'AbuseFilter::contentToString' can be used to override this
	 * behavior.
	 *
	 * @internal
	 * @todo Move elsewhere. VariableGenerator is a good candidate
	 *
	 * @param Content $content
	 *
	 * @return string a suitable string representation of the content.
	 */
	public static function contentToString( Content $content ) {
		$text = null;

		$hookRunner = AbuseFilterHookRunner::getRunner();
		if ( $hookRunner->onAbuseFilterContentToString(
			$content,
			$text
		) ) {
			$text = $content instanceof TextContent
				? $content->getText()
				: $content->getTextForSearchIndex();
		}

		// T22310
		$text = TextContent::normalizeLineEndings( (string)$text );
		return $text;
	}

	/**
	 * Get the history ID of the first change to a given filter
	 *
	 * @param int $filterID Filter id
	 * @return string
	 */
	public static function getFirstFilterChange( $filterID ) {
		static $firstChanges = [];

		if ( !isset( $firstChanges[$filterID] ) ) {
			$dbr = wfGetDB( DB_REPLICA );
			$historyID = $dbr->selectField(
				'abuse_filter_history',
				'afh_id',
				[
					'afh_filter' => $filterID,
				],
				__METHOD__,
				[ 'ORDER BY' => 'afh_timestamp ASC' ]
			);
			$firstChanges[$filterID] = $historyID;
		}

		return $firstChanges[$filterID];
	}

	/**
	 * Shortcut for checking whether $user can view the given revision, with mask
	 *  SUPPRESSED_ALL.
	 *
	 * @note This assumes that a revision with the given ID exists
	 *
	 * @param RevisionRecord $revRec
	 * @param User $user
	 * @return bool
	 */
	public static function userCanViewRev( RevisionRecord $revRec, User $user ) : bool {
		return $revRec->audienceCan(
			RevisionRecord::SUPPRESSED_ALL,
			RevisionRecord::FOR_THIS_USER,
			$user
		);
	}
}
