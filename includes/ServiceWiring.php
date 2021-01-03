<?php

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\AbuseFilter\AbuseFilterPermissionManager as PermManager;
use MediaWiki\Extension\AbuseFilter\AbuseLogger;
use MediaWiki\Extension\AbuseFilter\AbuseLoggerFactory;
use MediaWiki\Extension\AbuseFilter\BlockAutopromoteStore;
use MediaWiki\Extension\AbuseFilter\CentralDBManager;
use MediaWiki\Extension\AbuseFilter\ChangeTags\ChangeTagger;
use MediaWiki\Extension\AbuseFilter\ChangeTags\ChangeTagsManager;
use MediaWiki\Extension\AbuseFilter\ChangeTags\ChangeTagValidator;
use MediaWiki\Extension\AbuseFilter\Consequences\ConsequencesExecutor;
use MediaWiki\Extension\AbuseFilter\Consequences\ConsequencesExecutorFactory;
use MediaWiki\Extension\AbuseFilter\Consequences\ConsequencesExecutorFactory as ConsExecutorFactory;
use MediaWiki\Extension\AbuseFilter\Consequences\ConsequencesFactory;
use MediaWiki\Extension\AbuseFilter\Consequences\ConsequencesLookup;
use MediaWiki\Extension\AbuseFilter\Consequences\ConsequencesRegistry;
use MediaWiki\Extension\AbuseFilter\EchoNotifier;
use MediaWiki\Extension\AbuseFilter\EditBoxBuilderFactory;
use MediaWiki\Extension\AbuseFilter\FilterCompare;
use MediaWiki\Extension\AbuseFilter\FilterImporter;
use MediaWiki\Extension\AbuseFilter\FilterLookup;
use MediaWiki\Extension\AbuseFilter\FilterProfiler;
use MediaWiki\Extension\AbuseFilter\FilterRunnerFactory;
use MediaWiki\Extension\AbuseFilter\FilterStore;
use MediaWiki\Extension\AbuseFilter\FilterUser;
use MediaWiki\Extension\AbuseFilter\FilterValidator;
use MediaWiki\Extension\AbuseFilter\Hooks\AbuseFilterHookRunner;
use MediaWiki\Extension\AbuseFilter\KeywordsManager;
use MediaWiki\Extension\AbuseFilter\Parser\ParserFactory;
use MediaWiki\Extension\AbuseFilter\SpecsFormatter;
use MediaWiki\Extension\AbuseFilter\TextExtractor;
use MediaWiki\Extension\AbuseFilter\VariableGenerator\VariableGeneratorFactory;
use MediaWiki\Extension\AbuseFilter\Variables\LazyVariableComputer;
use MediaWiki\Extension\AbuseFilter\Variables\VariablesBlobStore;
use MediaWiki\Extension\AbuseFilter\Variables\VariablesFormatter;
use MediaWiki\Extension\AbuseFilter\Variables\VariablesManager;
use MediaWiki\Extension\AbuseFilter\Watcher\EmergencyWatcher;
use MediaWiki\Extension\AbuseFilter\Watcher\UpdateHitCountWatcher;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\Session\SessionManager;

// This file is actually covered by AbuseFilterServicesTest, but it's not possible to specify a path
// in @covers annotations (https://github.com/sebastianbergmann/phpunit/issues/3794)
// @codeCoverageIgnoreStart

return [
	KeywordsManager::SERVICE_NAME => function ( MediaWikiServices $services ): KeywordsManager {
		return new KeywordsManager(
			new AbuseFilterHookRunner( $services->getHookContainer() )
		);
	},
	FilterProfiler::SERVICE_NAME => function ( MediaWikiServices $services ): FilterProfiler {
		return new FilterProfiler(
			$services->getMainObjectStash(),
			new ServiceOptions(
				FilterProfiler::CONSTRUCTOR_OPTIONS,
				$services->getMainConfig()
			),
			WikiMap::getCurrentWikiDbDomain()->getId(),
			$services->getStatsdDataFactory(),
			LoggerFactory::getInstance( 'AbuseFilter' )
		);
	},
	PermManager::SERVICE_NAME => function ( MediaWikiServices $services ): PermManager {
		return new PermManager( $services->getPermissionManager() );
	},
	ChangeTagger::SERVICE_NAME => function ( MediaWikiServices $services ) : ChangeTagger {
		return new ChangeTagger(
			$services->getService( ChangeTagsManager::SERVICE_NAME )
		);
	},
	ChangeTagsManager::SERVICE_NAME => function ( MediaWikiServices $services ): ChangeTagsManager {
		return new ChangeTagsManager(
			$services->getDBLoadBalancer(),
			$services->getMainWANObjectCache(),
			$services->get( CentralDBManager::SERVICE_NAME )
		);
	},
	ChangeTagValidator::SERVICE_NAME => function ( MediaWikiServices $services ): ChangeTagValidator {
		return new ChangeTagValidator(
			$services->getService( ChangeTagsManager::SERVICE_NAME )
		);
	},
	CentralDBManager::SERVICE_NAME => function ( MediaWikiServices $services ): CentralDBManager {
		return new CentralDBManager(
			$services->getDBLoadBalancerFactory(),
			$services->getMainConfig()->get( 'AbuseFilterCentralDB' ),
			$services->getMainConfig()->get( 'AbuseFilterIsCentral' )
		);
	},
	BlockAutopromoteStore::SERVICE_NAME => function ( MediaWikiServices $services ): BlockAutopromoteStore {
		return new BlockAutopromoteStore(
			ObjectCache::getInstance( 'db-replicated' ),
			LoggerFactory::getInstance( 'AbuseFilter' ),
			$services->get( FilterUser::SERVICE_NAME )
		);
	},
	FilterUser::SERVICE_NAME => function ( MediaWikiServices $services ): FilterUser {
		return new FilterUser(
			// TODO We need a proper MessageLocalizer, see T247127
			RequestContext::getMain(),
			$services->getUserGroupManager(),
			LoggerFactory::getInstance( 'AbuseFilter' )
		);
	},
	ParserFactory::SERVICE_NAME => function ( MediaWikiServices $services ): ParserFactory {
		return new ParserFactory(
			$services->getContentLanguage(),
			// We could use $services here, but we need the fallback
			ObjectCache::getLocalServerInstance( 'hash' ),
			LoggerFactory::getInstance( 'AbuseFilter' ),
			$services->getService( KeywordsManager::SERVICE_NAME ),
			$services->get( VariablesManager::SERVICE_NAME ),
			$services->getMainConfig()->get( 'AbuseFilterParserClass' ),
			$services->getMainConfig()->get( 'AbuseFilterConditionLimit' )
		);
	},
	FilterLookup::SERVICE_NAME => function ( MediaWikiServices $services ): FilterLookup {
		return new FilterLookup(
			$services->getDBLoadBalancer(),
			$services->getMainWANObjectCache(),
			$services->get( CentralDBManager::SERVICE_NAME )
		);
	},
	EmergencyWatcher::SERVICE_NAME => function ( MediaWikiServices $services ): EmergencyWatcher {
		return new EmergencyWatcher(
			$services->getService( FilterProfiler::SERVICE_NAME ),
			$services->getDBLoadBalancer(),
			$services->getService( FilterLookup::SERVICE_NAME ),
			$services->getService( EchoNotifier::SERVICE_NAME ),
			new ServiceOptions(
				EmergencyWatcher::CONSTRUCTOR_OPTIONS,
				$services->getMainConfig()
			)
		);
	},
	EchoNotifier::SERVICE_NAME => function ( MediaWikiServices $services ): EchoNotifier {
		return new EchoNotifier(
			$services->getService( FilterLookup::SERVICE_NAME ),
			ExtensionRegistry::getInstance()->isLoaded( 'Echo' )
		);
	},
	FilterValidator::SERVICE_NAME => function ( MediaWikiServices $services ): FilterValidator {
		return new FilterValidator(
			$services->get( ChangeTagValidator::SERVICE_NAME ),
			$services->get( ParserFactory::SERVICE_NAME ),
			$services->get( PermManager::SERVICE_NAME ),
			// Pass the cleaned list of enabled restrictions
			array_keys( array_filter( $services->getMainConfig()->get( 'AbuseFilterActionRestrictions' ) ) )
		);
	},
	FilterCompare::SERVICE_NAME => function ( MediaWikiServices $services ): FilterCompare {
		return new FilterCompare(
			$services->get( ConsequencesRegistry::SERVICE_NAME )
		);
	},
	FilterImporter::SERVICE_NAME => function ( MediaWikiServices $services ): FilterImporter {
		return new FilterImporter(
			new ServiceOptions(
				FilterImporter::CONSTRUCTOR_OPTIONS,
				$services->getMainConfig()
			),
			$services->get( ConsequencesRegistry::SERVICE_NAME )
		);
	},
	FilterStore::SERVICE_NAME => function ( MediaWikiServices $services ): FilterStore {
		return new FilterStore(
			$services->get( ConsequencesRegistry::SERVICE_NAME ),
			$services->getDBLoadBalancer(),
			$services->get( FilterProfiler::SERVICE_NAME ),
			$services->get( FilterLookup::SERVICE_NAME ),
			$services->get( ChangeTagsManager::SERVICE_NAME ),
			$services->get( FilterValidator::SERVICE_NAME ),
			$services->get( FilterCompare::SERVICE_NAME )
		);
	},
	ConsequencesFactory::SERVICE_NAME => function ( MediaWikiServices $services ): ConsequencesFactory {
		return new ConsequencesFactory(
			new ServiceOptions(
				ConsequencesFactory::CONSTRUCTOR_OPTIONS,
				$services->getMainConfig()
			),
			LoggerFactory::getInstance( 'AbuseFilter' ),
			$services->getBlockUserFactory(),
			$services->getDatabaseBlockStore(),
			$services->getUserGroupManager(),
			$services->getMainObjectStash(),
			$services->get( ChangeTagger::SERVICE_NAME ),
			$services->get( BlockAutopromoteStore::SERVICE_NAME ),
			$services->get( FilterUser::SERVICE_NAME ),
			SessionManager::getGlobalSession(),
			RequestContext::getMain()->getRequest()->getIP()
		);
	},
	EditBoxBuilderFactory::SERVICE_NAME => function ( MediaWikiServices $services ): EditBoxBuilderFactory {
		return new EditBoxBuilderFactory(
			$services->get( PermManager::SERVICE_NAME ),
			$services->get( KeywordsManager::SERVICE_NAME ),
			ExtensionRegistry::getInstance()->isLoaded( 'CodeEditor' )
		);
	},
	ConsequencesLookup::SERVICE_NAME => function ( MediaWikiServices $services ) : ConsequencesLookup {
		return new ConsequencesLookup(
			$services->getDBLoadBalancer(),
			$services->get( CentralDBManager::SERVICE_NAME ),
			$services->get( ConsequencesRegistry::SERVICE_NAME ),
			LoggerFactory::getInstance( 'AbuseFilter' )
		);
	},
	ConsequencesRegistry::SERVICE_NAME => function ( MediaWikiServices $services ): ConsequencesRegistry {
		return new ConsequencesRegistry(
			AbuseFilterHookRunner::getRunner(),
			$services->getMainConfig()->get( 'AbuseFilterActions' ),
			$services->getMainConfig()->get( 'AbuseFilterCustomActionsHandlers' )
		);
	},
	AbuseLoggerFactory::SERVICE_NAME => function ( MediaWikiServices $services ) : AbuseLoggerFactory {
		return new AbuseLoggerFactory(
			$services->get( CentralDBManager::SERVICE_NAME ),
			$services->get( FilterLookup::SERVICE_NAME ),
			$services->get( VariablesBlobStore::SERVICE_NAME ),
			$services->get( VariablesManager::SERVICE_NAME ),
			$services->getDBLoadBalancer(),
			new ServiceOptions(
				AbuseLogger::CONSTRUCTOR_OPTIONS,
				$services->getMainConfig()
			),
			WikiMap::getCurrentWikiDbDomain()->getId(),
			RequestContext::getMain()->getRequest()->getIP()
		);
	},
	UpdateHitCountWatcher::SERVICE_NAME => function ( MediaWikiServices $services ): UpdateHitCountWatcher {
		return new UpdateHitCountWatcher(
			$services->getDBLoadBalancer(),
			$services->get( CentralDBManager::SERVICE_NAME )
		);
	},
	VariablesBlobStore::SERVICE_NAME => function ( MediaWikiServices $services ): VariablesBlobStore {
		return new VariablesBlobStore(
			$services->get( VariablesManager::SERVICE_NAME ),
			$services->getBlobStoreFactory(),
			$services->getBlobStore(),
			$services->getMainConfig()->get( 'AbuseFilterCentralDB' )
		);
	},
	ConsExecutorFactory::SERVICE_NAME => function ( MediaWikiServices $services ) : ConsExecutorFactory {
		return new ConsExecutorFactory(
			$services->get( ConsequencesLookup::SERVICE_NAME ),
			$services->get( ConsequencesFactory::SERVICE_NAME ),
			$services->get( ConsequencesRegistry::SERVICE_NAME ),
			$services->get( FilterLookup::SERVICE_NAME ),
			LoggerFactory::getInstance( 'AbuseFilter' ),
			new ServiceOptions(
				ConsequencesExecutor::CONSTRUCTOR_OPTIONS,
				$services->getMainConfig()
			)
		);
	},
	FilterRunnerFactory::SERVICE_NAME => function ( MediaWikiServices $services ) : FilterRunnerFactory {
		return new FilterRunnerFactory(
			AbuseFilterHookRunner::getRunner(),
			$services->get( FilterProfiler::SERVICE_NAME ),
			$services->get( ChangeTagger::SERVICE_NAME ),
			$services->get( FilterLookup::SERVICE_NAME ),
			$services->get( ParserFactory::SERVICE_NAME ),
			$services->get( ConsequencesExecutorFactory::SERVICE_NAME ),
			$services->get( AbuseLoggerFactory::SERVICE_NAME ),
			$services->get( VariablesManager::SERVICE_NAME ),
			$services->get( VariableGeneratorFactory::SERVICE_NAME ),
			$services->get( UpdateHitCountWatcher::SERVICE_NAME ),
			$services->get( EmergencyWatcher::SERVICE_NAME ),
			LoggerFactory::getInstance( 'AbuseFilter' ),
			$services->getStatsdDataFactory(),
			$services->getMainConfig()->get( 'AbuseFilterValidGroups' )
		);
	},
	VariablesFormatter::SERVICE_NAME => function ( MediaWikiServices $services ): VariablesFormatter {
		return new VariablesFormatter(
			$services->get( KeywordsManager::SERVICE_NAME ),
			$services->get( VariablesManager::SERVICE_NAME ),
			// TODO: Use a proper MessageLocalizer once available (T247127)
			RequestContext::getMain()
		);
	},
	SpecsFormatter::SERVICE_NAME => function ( MediaWikiServices $services ): SpecsFormatter {
		return new SpecsFormatter(
			// TODO: Use a proper MessageLocalizer once available (T247127)
			RequestContext::getMain()
		);
	},
	LazyVariableComputer::SERVICE_NAME => function ( MediaWikiServices $services ): LazyVariableComputer {
		return new LazyVariableComputer(
			$services->get( TextExtractor::SERVICE_NAME ),
			AbuseFilterHookRunner::getRunner(),
			$services->getTitleFactory(),
			LoggerFactory::getInstance( 'AbuseFilter' ),
			$services->getDBLoadBalancer(),
			$services->getMainWANObjectCache(),
			$services->getRevisionLookup(),
			$services->getRevisionStore(),
			$services->getContentLanguage(),
			$services->getParser(),
			WikiMap::getCurrentWikiDbDomain()->getId()
		);
	},
	TextExtractor::SERVICE_NAME => function ( MediaWikiServices $services ): TextExtractor {
		return new TextExtractor(
			new AbuseFilterHookRunner( $services->getHookContainer() )
		);
	},
	VariablesManager::SERVICE_NAME => function ( MediaWikiServices $services ): VariablesManager {
		return new VariablesManager(
			$services->get( KeywordsManager::SERVICE_NAME ),
			$services->get( LazyVariableComputer::SERVICE_NAME ),
			LoggerFactory::getInstance( 'AbuseFilter' )
		);
	},
	VariableGeneratorFactory::SERVICE_NAME => function ( MediaWikiServices $services ): VariableGeneratorFactory {
		return new VariableGeneratorFactory(
			AbuseFilterHookRunner::getRunner(),
			$services->get( TextExtractor::SERVICE_NAME ),
			$services->getMimeAnalyzer(),
			$services->getRepoGroup()
		);
	},
];

// @codeCoverageIgnoreEnd
