<?php

namespace MediaWiki\Extension\AbuseFilter\EditBox;

use LogicException;
use MediaWiki\Extension\AbuseFilter\AbuseFilterPermissionManager;
use MediaWiki\Extension\AbuseFilter\KeywordsManager;
use MediaWiki\Extension\AbuseFilter\ServiceNames;
use MediaWiki\Output\OutputPage;
use MediaWiki\Permissions\Authority;
use MessageLocalizer;

/**
 * Factory for EditBoxBuilder objects
 */
class EditBoxBuilderFactory {

	public const SERVICE_NAME = ServiceNames::EditBoxBuilderFactory;

	/** @var AbuseFilterPermissionManager */
	private $afPermManager;

	/** @var KeywordsManager */
	private $keywordsManager;

	/** @var bool */
	private $isCodeEditorLoaded;

	/**
	 * @param AbuseFilterPermissionManager $afPermManager
	 * @param KeywordsManager $keywordsManager
	 * @param bool $isCodeEditorLoaded
	 */
	public function __construct(
		AbuseFilterPermissionManager $afPermManager,
		KeywordsManager $keywordsManager,
		bool $isCodeEditorLoaded,
		bool $isCodeMirrorLoaded,
	) {
		$this->afPermManager = $afPermManager;
		$this->keywordsManager = $keywordsManager;
		$this->isCodeEditorLoaded = $isCodeEditorLoaded;
	}

	/**
	 * Returns a builder, preferring the CodeMirror/Ace version if available
	 * @param MessageLocalizer $messageLocalizer
	 * @param Authority $authority
	 * @param OutputPage $output
	 * @return EditBoxBuilder
	 */
	public function newEditBoxBuilder(
		MessageLocalizer $messageLocalizer,
		Authority $authority,
		OutputPage $output
	): EditBoxBuilder {
		if ( $this->isCodeEditorLoaded ) {
			return $this->newAceBoxBuilder( $messageLocalizer, $authority, $output );
		}
		if ( $this->isCodeMirrorLoaded ) {
			return $this->newCodeMirrorBoxBuilder( $messageLocalizer, $authority, $output );
		}
		return $this->newPlainBoxBuilder( $messageLocalizer, $authority, $output );
	}

	/**
	 * @param MessageLocalizer $messageLocalizer
	 * @param Authority $authority
	 * @param OutputPage $output
	 * @return PlainEditBoxBuilder
	 */
	public function newPlainBoxBuilder(
		MessageLocalizer $messageLocalizer,
		Authority $authority,
		OutputPage $output
	): PlainEditBoxBuilder {
		return new PlainEditBoxBuilder(
			$this->afPermManager,
			$this->keywordsManager,
			$messageLocalizer,
			$authority,
			$output
		);
	}

	/**
	 * @param MessageLocalizer $messageLocalizer
	 * @param Authority $authority
	 * @param OutputPage $output
	 * @return AceEditBoxBuilder
	 */
	public function newAceBoxBuilder(
		MessageLocalizer $messageLocalizer,
		Authority $authority,
		OutputPage $output
	): AceEditBoxBuilder {
		if ( !$this->isCodeEditorLoaded ) {
			throw new LogicException( 'Cannot create Ace box without CodeEditor' );
		}
		return new AceEditBoxBuilder(
			$this->afPermManager,
			$this->keywordsManager,
			$messageLocalizer,
			$authority,
			$output,
			$this->newPlainBoxBuilder(
				$messageLocalizer,
				$authority,
				$output
			)
		);
	}

	/**
	 * @param MessageLocalizer $messageLocalizer
	 * @param Authority $authority
	 * @param OutputPage $output
	 * @return CodeMirrorEditBoxBuilder
	 */
	public function newCodeMirrorBoxBuilder(
		MessageLocalizer $messageLocalizer,
		Authority $authority,
		OutputPage $output
	): CodeMirrorEditBoxBuilder {
		if ( !$this->isCodeMirrorLoaded ) {
			throw new LogicException( 'Cannot create CodeMirror box without the CodeMirror extension' );
		}
		return new CodeMirrorEditBoxBuilder(
			$this->afPermManager,
			$this->keywordsManager,
			$messageLocalizer,
			$authority,
			$output,
			$this->newPlainBoxBuilder(
				$messageLocalizer,
				$authority,
				$output
			)
		);
	}

}
