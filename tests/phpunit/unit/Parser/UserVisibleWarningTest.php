<?php

namespace MediaWiki\Extension\AbuseFilter\Tests\Unit\Parser;

use MediaWiki\Extension\AbuseFilter\Parser\UserVisibleWarning;
use MediaWikiUnitTestCase;

/**
 * @group Test
 * @group AbuseFilter
 * @group AbuseFilterParser
 *
 * @coversDefaultClass \MediaWiki\Extension\AbuseFilter\Parser\UserVisibleWarning
 */
class UserVisibleWarningTest extends MediaWikiUnitTestCase {

	/**
	 * @covers ::getMessageObj
	 */
	public function testGetMessageObj() {
		$excID = 'abusefilter-foo';
		$position = 42;
		$params = [ 'foo' ];
		$message = ( new UserVisibleWarning( $excID, $position, $params ) )->getMessageObj();
		$this->assertSame( 'abusefilter-warning-' . $excID, $message->getKey(), 'msg key' );
		$this->assertArrayEquals( array_merge( [ $position ], $params ), $message->getParams(), 'msg params' );
	}
}
