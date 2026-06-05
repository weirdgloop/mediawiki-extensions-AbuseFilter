<?php

namespace MediaWiki\Extension\AbuseFilter;

// phpcs:disable Generic.NamingConventions.UpperCaseConstantName.ClassConstantNotUpperCase

/**
 * This class holds constants for service names, so ServiceWiring.php doesn't have to autoload
 * service classes that aren't actually used during the request to access the constants (T426470).
 */
class ServiceNames {

	public const AbuseFilterHookRunner = 'AbuseFilterHookRunner';
	public const AbuseLogConditionFactory = 'AbuseLogConditionFactory';
	public const AbuseLoggerFactory = 'AbuseFilterAbuseLoggerFactory';
	public const BlockAutopromoteStore = 'AbuseFilterBlockAutopromoteStore';
	public const BlockedDomainFilter = 'AbuseFilterBlockedDomainFilter';
	public const BlockedDomainStorage = 'AbuseFilterBlockedDomainStorage';
	public const BlockedDomainValidator = 'AbuseFilterBlockedDomainValidator';
	public const CentralDBManager = 'AbuseFilterCentralDBManager';
	public const ChangeTagger = 'AbuseFilterChangeTagger';
	public const ChangeTagsManager = 'AbuseFilterChangeTagsManager';
	public const ChangeTagValidator = 'AbuseFilterChangeTagValidator';
	public const ConsequencesExecutorFactory = 'AbuseFilterConsequencesExecutorFactory';
	public const ConsequencesFactory = 'AbuseFilterConsequencesFactory';
	public const ConsequencesLookup = 'AbuseFilterConsequencesLookup';
	public const ConsequencesRegistry = 'AbuseFilterConsequencesRegistry';
	public const EchoNotifier = 'AbuseFilterEchoNotifier';
	public const EditBoxBuilderFactory = 'AbuseFilterEditBoxBuilderFactory';
	public const EditRevUpdater = 'AbuseFilterEditRevUpdater';
	public const EmergencyCache = 'AbuseFilterEmergencyCache';
	public const EmergencyWatcher = 'AbuseFilterEmergencyWatcher';
	public const FilterCompare = 'AbuseFilterFilterCompare';
	public const FilterImporter = 'AbuseFilterFilterImporter';
	public const FilterLookup = 'AbuseFilterFilterLookup';
	public const FilterProfiler = 'AbuseFilterFilterProfiler';
	public const FilterRunnerFactory = 'AbuseFilterFilterRunnerFactory';
	public const FilterStore = 'AbuseFilterFilterStore';
	public const FilterUser = 'AbuseFilterFilterUser';
	public const FilterValidator = 'AbuseFilterFilterValidator';
	public const KeywordsManager = 'AbuseFilterKeywordsManager';
	public const LazyVariableComputer = 'AbuseFilterLazyVariableComputer';
	public const LogDetailsLookup = 'AbuseFilterLogDetailsLookup';
	public const PermManager = 'AbuseFilterPermissionManager';
	public const ProtectedVariablesLookup = 'AbuseFilterProtectedVariablesLookup';
	public const RuleCheckerFactory = 'AbuseFilterRuleCheckerFactory';
	public const SpecsFormatter = 'AbuseFilterSpecsFormatter';
	public const TemporaryAccountIPsViewerSpecification = 'TemporaryAccountIPsViewerSpecification';
	public const TextExtractor = 'AbuseFilterTextExtractor';
	public const UpdateHitCountWatcher = 'AbuseFilterUpdateHitCountWatcher';
	public const VariableGeneratorFactory = 'AbuseFilterVariableGeneratorFactory';
	public const VariablesBlobStore = 'AbuseFilterVariablesBlobStore';
	public const VariablesFormatter = 'AbuseFilterVariablesFormatter';
	public const VariablesManager = 'AbuseFilterVariablesManager';

}
