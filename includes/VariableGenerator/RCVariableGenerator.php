<?php

namespace MediaWiki\Extension\AbuseFilter\VariableGenerator;

use AbuseFilterVariableHolder;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MWFileProps;
use MWTimestamp;
use RecentChange;
use Title;

/**
 * This class contains the logic used to create AbuseFilterVariableHolder objects used to
 * examine a RecentChanges row.
 */
class RCVariableGenerator extends VariableGenerator {
	/**
	 * @var RecentChange
	 */
	protected $rc;

	/**
	 * @param AbuseFilterVariableHolder $vars
	 * @param RecentChange $rc
	 */
	public function __construct( AbuseFilterVariableHolder $vars, RecentChange $rc ) {
		parent::__construct( $vars );

		$this->rc = $rc;
	}

	/**
	 * Get an instance for a given rc_id.
	 *
	 * @param int $id
	 * @param AbuseFilterVariableHolder $vars
	 * @return self|null
	 */
	public static function newFromId(
		int $id,
		AbuseFilterVariableHolder $vars
	) : ?self {
		$rc = RecentChange::newFromId( $id );

		if ( !$rc ) {
			return null;
		}
		return new self( $vars, $rc );
	}

	/**
	 * @return AbuseFilterVariableHolder|null
	 */
	public function getVars() : ?AbuseFilterVariableHolder {
		if ( $this->rc->getAttribute( 'rc_type' ) == RC_LOG ) {
			switch ( $this->rc->getAttribute( 'rc_log_type' ) ) {
				case 'move':
					$this->addMoveVars();
					break;
				case 'newusers':
					$this->addCreateAccountVars();
					break;
				case 'delete':
					$this->addDeleteVars();
					break;
				case 'upload':
					$this->addUploadVars();
					break;
				default:
					return null;
			}
		} elseif ( $this->rc->getAttribute( 'rc_last_oldid' ) ) {
			// It's an edit.
			$this->addEditVarsForRow();
		} else {
			// @todo Ensure this cannot happen, and throw if it does
			return null;
		}

		$this->addGenericVars();
		$this->vars->setVar(
			'timestamp',
			MWTimestamp::convert( TS_UNIX, $this->rc->getAttribute( 'rc_timestamp' ) )
		);

		return $this->vars;
	}

	/**
	 * @return $this
	 */
	private function addMoveVars() : self {
		$user = $this->rc->getPerformer();

		$oldTitle = $this->rc->getTitle();
		$newTitle = Title::newFromText( $this->rc->getParam( '4::target' ) );

		$this->addUserVars( $user, $this->rc )
			->addTitleVars( $oldTitle, 'moved_from', $this->rc )
			->addTitleVars( $newTitle, 'moved_to', $this->rc );

		$this->vars->setVar( 'summary', $this->rc->getAttribute( 'rc_comment' ) );
		$this->vars->setVar( 'action', 'move' );

		return $this;
	}

	/**
	 * @return $this
	 */
	private function addCreateAccountVars() : self {
		$this->vars->setVar(
			'action',
			$this->rc->getAttribute( 'rc_log_action' ) === 'autocreate'
				? 'autocreateaccount'
				: 'createaccount'
		);

		$name = $this->rc->getTitle()->getText();
		// Add user data if the account was created by a registered user
		$user = $this->rc->getPerformer();
		if ( !$user->isAnon() && $name !== $user->getName() ) {
			$this->addUserVars( $user, $this->rc );
		}

		$this->vars->setVar( 'accountname', $name );

		return $this;
	}

	/**
	 * @return $this
	 */
	private function addDeleteVars() : self {
		$title = $this->rc->getTitle();
		$user = $this->rc->getPerformer();

		$this->addUserVars( $user, $this->rc )
			->addTitleVars( $title, 'page', $this->rc );

		$this->vars->setVar( 'action', 'delete' );
		$this->vars->setVar( 'summary', $this->rc->getAttribute( 'rc_comment' ) );

		return $this;
	}

	/**
	 * @return $this
	 */
	private function addUploadVars() : self {
		$title = $this->rc->getTitle();
		$user = $this->rc->getPerformer();

		$this->addUserVars( $user, $this->rc )
			->addTitleVars( $title, 'page', $this->rc );

		$this->vars->setVar( 'action', 'upload' );
		$this->vars->setVar( 'summary', $this->rc->getAttribute( 'rc_comment' ) );

		$time = $this->rc->getParam( 'img_timestamp' );
		$file = MediaWikiServices::getInstance()->getRepoGroup()->findFile(
			$title, [ 'time' => $time, 'private' => true ]
		);
		if ( !$file ) {
			// FixMe This shouldn't happen!
			$logger = LoggerFactory::getInstance( 'AbuseFilter' );
			$logger->debug( "Cannot find file from RC row with title $title" );
			return $this;
		}

		// This is the same as AbuseFilterHooks::filterUpload, but from a different source
		$this->vars->setVar( 'file_sha1', \Wikimedia\base_convert( $file->getSha1(), 36, 16, 40 ) );
		$this->vars->setVar( 'file_size', $file->getSize() );

		$this->vars->setVar( 'file_mime', $file->getMimeType() );
		$this->vars->setVar(
			'file_mediatype',
			MediaWikiServices::getInstance()->getMimeAnalyzer()
				->getMediaType( null, $file->getMimeType() )
		);
		$this->vars->setVar( 'file_width', $file->getWidth() );
		$this->vars->setVar( 'file_height', $file->getHeight() );

		$mwProps = new MWFileProps( MediaWikiServices::getInstance()->getMimeAnalyzer() );
		$bits = $mwProps->getPropsFromPath( $file->getLocalRefPath(), true )['bits'];
		$this->vars->setVar( 'file_bits_per_channel', $bits );

		return $this;
	}

	/**
	 * @return $this
	 */
	private function addEditVarsForRow() : self {
		$title = $this->rc->getTitle();
		$user = $this->rc->getPerformer();

		$this->addUserVars( $user, $this->rc )
			->addTitleVars( $title, 'page', $this->rc );

		// @todo Set old_content_model and new_content_model
		$this->vars->setVar( 'action', 'edit' );
		$this->vars->setVar( 'summary', $this->rc->getAttribute( 'rc_comment' ) );

		$this->vars->setLazyLoadVar( 'new_wikitext', 'revision-text-by-id',
			[ 'revid' => $this->rc->getAttribute( 'rc_this_oldid' ) ] );

		$parentId = $this->rc->getAttribute( 'rc_last_oldid' );
		if ( $parentId ) {
			$this->vars->setLazyLoadVar( 'old_wikitext', 'revision-text-by-id',
				[ 'revid' => $parentId ] );
		} else {
			$this->vars->setVar( 'old_wikitext', '' );
		}

		$this->addEditVars( $title );

		return $this;
	}
}
