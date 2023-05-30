<?php

namespace MediaWiki\Extension\AbuseFilter;

use BagOStuff;
use IBufferingStatsdDataFactory;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Deferred\DeferredUpdatesManager;
use MediaWiki\Extension\AbuseFilter\ChangeTags\ChangeTagger;
use MediaWiki\Extension\AbuseFilter\Consequences\ConsequencesExecutorFactory;
use MediaWiki\Extension\AbuseFilter\Hooks\AbuseFilterHookRunner;
use MediaWiki\Extension\AbuseFilter\Parser\RuleCheckerFactory;
use MediaWiki\Extension\AbuseFilter\VariableGenerator\VariableGeneratorFactory;
use MediaWiki\Extension\AbuseFilter\Variables\VariableHolder;
use MediaWiki\Extension\AbuseFilter\Variables\VariablesManager;
use MediaWiki\Extension\AbuseFilter\Watcher\EmergencyWatcher;
use MediaWiki\Extension\AbuseFilter\Watcher\UpdateHitCountWatcher;
use NullStatsdDataFactory;
use Psr\Log\LoggerInterface;
use Title;
use User;

class FilterRunnerFactory {
	public const SERVICE_NAME = 'AbuseFilterFilterRunnerFactory';

	/** @var AbuseFilterHookRunner */
	private $hookRunner;
	/** @var FilterProfiler */
	private $filterProfiler;
	/** @var ChangeTagger */
	private $changeTagger;
	/** @var FilterLookup */
	private $filterLookup;
	/** @var RuleCheckerFactory */
	private $ruleCheckerFactory;
	/** @var ConsequencesExecutorFactory */
	private $consExecutorFactory;
	/** @var AbuseLoggerFactory */
	private $abuseLoggerFactory;
	/** @var VariablesManager */
	private $varManager;
	/** @var VariableGeneratorFactory */
	private $varGeneratorFactory;
	/** @var EmergencyCache */
	private $emergencyCache;
	/** @var UpdateHitCountWatcher */
	private $updateHitCountWatcher;
	/** @var EmergencyWatcher */
	private $emergencyWatcher;
	/** @var BagOStuff */
	private $localCache;
	/** @var LoggerInterface */
	private $logger;
	/** @var LoggerInterface */
	private $editStashLogger;
	/** @var IBufferingStatsdDataFactory */
	private $statsdDataFactory;
	/** @var ServiceOptions */
	private $options;
	/** @var DeferredUpdatesManager */
	private DeferredUpdatesManager $deferredUpdatesManager;

	/**
	 * @param AbuseFilterHookRunner $hookRunner
	 * @param FilterProfiler $filterProfiler
	 * @param ChangeTagger $changeTagger
	 * @param FilterLookup $filterLookup
	 * @param RuleCheckerFactory $ruleCheckerFactory
	 * @param ConsequencesExecutorFactory $consExecutorFactory
	 * @param AbuseLoggerFactory $abuseLoggerFactory
	 * @param VariablesManager $varManager
	 * @param VariableGeneratorFactory $varGeneratorFactory
	 * @param EmergencyCache $emergencyCache
	 * @param UpdateHitCountWatcher $updateHitCountWatcher
	 * @param EmergencyWatcher $emergencyWatcher
	 * @param BagOStuff $localCache
	 * @param LoggerInterface $logger
	 * @param LoggerInterface $editStashLogger
	 * @param IBufferingStatsdDataFactory $statsdDataFactory
	 * @param ServiceOptions $options
	 * @param DeferredUpdatesManager $deferredUpdatesManager
	 */
	public function __construct(
		AbuseFilterHookRunner $hookRunner,
		FilterProfiler $filterProfiler,
		ChangeTagger $changeTagger,
		FilterLookup $filterLookup,
		RuleCheckerFactory $ruleCheckerFactory,
		ConsequencesExecutorFactory $consExecutorFactory,
		AbuseLoggerFactory $abuseLoggerFactory,
		VariablesManager $varManager,
		VariableGeneratorFactory $varGeneratorFactory,
		EmergencyCache $emergencyCache,
		UpdateHitCountWatcher $updateHitCountWatcher,
		EmergencyWatcher $emergencyWatcher,
		BagOStuff $localCache,
		LoggerInterface $logger,
		LoggerInterface $editStashLogger,
		IBufferingStatsdDataFactory $statsdDataFactory,
		ServiceOptions $options,
		DeferredUpdatesManager $deferredUpdatesManager
	) {
		$this->hookRunner = $hookRunner;
		$this->filterProfiler = $filterProfiler;
		$this->changeTagger = $changeTagger;
		$this->filterLookup = $filterLookup;
		$this->ruleCheckerFactory = $ruleCheckerFactory;
		$this->consExecutorFactory = $consExecutorFactory;
		$this->abuseLoggerFactory = $abuseLoggerFactory;
		$this->varManager = $varManager;
		$this->varGeneratorFactory = $varGeneratorFactory;
		$this->emergencyCache = $emergencyCache;
		$this->updateHitCountWatcher = $updateHitCountWatcher;
		$this->emergencyWatcher = $emergencyWatcher;
		$this->localCache = $localCache;
		$this->logger = $logger;
		$this->editStashLogger = $editStashLogger;
		$this->statsdDataFactory = $statsdDataFactory;
		$this->options = $options;
		$this->deferredUpdatesManager = $deferredUpdatesManager;
	}

	/**
	 * @param User $user
	 * @param Title $title
	 * @param VariableHolder $vars
	 * @param string $group
	 * @return FilterRunner
	 */
	public function newRunner(
		User $user,
		Title $title,
		VariableHolder $vars,
		string $group
	): FilterRunner {
		// TODO Add a method to this class taking these as params? Add a hook for custom watchers
		$watchers = [ $this->updateHitCountWatcher, $this->emergencyWatcher ];
		return new FilterRunner(
			$this->hookRunner,
			$this->filterProfiler,
			$this->changeTagger,
			$this->filterLookup,
			$this->ruleCheckerFactory,
			$this->consExecutorFactory,
			$this->abuseLoggerFactory,
			$this->varManager,
			$this->varGeneratorFactory,
			$this->emergencyCache,
			$watchers,
			new EditStashCache(
				$this->localCache,
				// Bots do not use edit stashing, so avoid distorting the stats
				$user->isBot() ? new NullStatsdDataFactory() : $this->statsdDataFactory,
				$this->varManager,
				$this->editStashLogger,
				$title,
				$group
			),
			$this->logger,
			$this->options,
			$this->deferredUpdatesManager,
			$user,
			$title,
			$vars,
			$group
		);
	}
}
