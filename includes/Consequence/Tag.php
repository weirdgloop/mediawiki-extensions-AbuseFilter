<?php

namespace MediaWiki\Extension\AbuseFilter\Consequence;

use MediaWiki\Extension\AbuseFilter\ChangeTagger;

/**
 * Consequence that adds change tags once the edit is saved
 */
class Tag extends Consequence {
	/** @var string|null */
	private $accountName;
	/** @var string[] */
	private $tags;
	/** @var ChangeTagger */
	private $tagger;

	/**
	 * @param Parameters $parameters
	 * @param string|null $accountName Of the account being created, if this is an account creation
	 * @param string[] $tags
	 * @param ChangeTagger $tagger
	 */
	public function __construct( Parameters $parameters, ?string $accountName, array $tags, ChangeTagger $tagger ) {
		parent::__construct( $parameters );
		$this->accountName = $accountName;
		$this->tags = $tags;
		$this->tagger = $tagger;
	}

	/**
	 * @inheritDoc
	 */
	public function execute(): bool {
		$specs = [
			'action' => $this->parameters->getAction(),
			'username' => $this->parameters->getUser()->getName(),
			'target' => $this->parameters->getTarget(),
			'accountname' => $this->accountName
		];
		$this->tagger->addTags( $specs, $this->tags );
		return true;
	}
}
