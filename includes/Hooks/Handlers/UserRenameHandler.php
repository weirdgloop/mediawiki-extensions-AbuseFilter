<?php

namespace MediaWiki\Extension\AbuseFilter\Hooks\Handlers;

use MediaWiki\RenameUser\Hook\RenameUserSQLHook;
use MediaWiki\RenameUser\RenameuserSQL;

class UserRenameHandler implements RenameUserSQLHook {

	/**
	 * @inheritDoc
	 */
	public function onRenameUserSQL( RenameuserSQL $renameUserSql ): void {
		// WGL - Handle RenameUser for abuse_filter_log too.
		$renameUserSql->tablesJob['abuse_filter_log'] = [
			RenameuserSQL::NAME_COL => 'afl_user_text',
			RenameuserSQL::UID_COL => 'afl_user',
			RenameuserSQL::TIME_COL => 'afl_timestamp',
			'uniqueKey' => 'afl_id'
		];

		global $wgAbuseFilterActorTableSchemaMigrationStage;
		if ( !( $wgAbuseFilterActorTableSchemaMigrationStage & SCHEMA_COMPAT_OLD ) ) {
			return;
		}
		$renameUserSql->tablesJob['abuse_filter'] = [
			RenameuserSQL::NAME_COL => 'af_user_text',
			RenameuserSQL::UID_COL => 'af_user',
			RenameuserSQL::TIME_COL => 'af_timestamp',
			'uniqueKey' => 'af_id'
		];
		$renameUserSql->tablesJob['abuse_filter_history'] = [
			RenameuserSQL::NAME_COL => 'afh_user_text',
			RenameuserSQL::UID_COL => 'afh_user',
			RenameuserSQL::TIME_COL => 'afh_timestamp',
			'uniqueKey' => 'afh_id'
		];
	}

}
