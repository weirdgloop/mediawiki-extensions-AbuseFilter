<?php

namespace MediaWiki\Extension\AbuseFilter\Hooks\Handlers;

use MediaWiki\RenameUser\Hook\RenameUserSQLHook;
use MediaWiki\RenameUser\RenameuserSQL;

class UserRenameHandler implements RenameUserSQLHook {

	/**
	 * @inheritDoc
	 */
	public function onRenameUserSQL( RenameuserSQL $renameUserSql ): void {
		// WGL - Handle RenameUser for abuse_filter_log.
		$renameUserSql->tablesJob['abuse_filter_log'] = [
			RenameuserSQL::NAME_COL => 'afl_user_text',
			RenameuserSQL::UID_COL => 'afl_user',
			RenameuserSQL::TIME_COL => 'afl_timestamp',
			'uniqueKey' => 'afl_id'
		];
	}

}
