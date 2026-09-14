<?php
namespace JT\Tests\ClaudeUpdate;

use JT\ClaudeChangelog;
use JT\Tests\TestCase;

final class ClaudeChangelogTest extends TestCase {

	private ClaudeChangelog $changelog;

	protected function setUp(): void {
		parent::setUp();

		$this->changelog = new ClaudeChangelog();
	}

	public function testParseSectionsExtractsVersionsAndBulletsInFileOrder(): void {
		$sections = $this->changelog->parseSections( <<<MD
			# Changelog

			## 2.1.270

			- Fixed a regression from 2.1.269

			## 2.1.269

			- Added a new thing
			- Fixed another thing
			MD );

		$this->assertSame(
			[
				[ 'version' => '2.1.270', 'lines' => [ 'Fixed a regression from 2.1.269' ] ],
				[ 'version' => '2.1.269', 'lines' => [ 'Added a new thing', 'Fixed another thing' ] ],
			],
			$sections
		);
	}

	public function testParseSectionsIgnoresNonBulletLines(): void {
		$sections = $this->changelog->parseSections( <<<MD
			## 1.0.0

			Some prose that isn't a bullet.

			- Added the only real bullet
			MD );

		$this->assertSame( [ 'Added the only real bullet' ], $sections[0]['lines'] );
	}

	public function testParseSectionsReturnsEmptyForUnrecognizedInput(): void {
		$this->assertSame( [], $this->changelog->parseSections( 'not a changelog' ) );
	}

	public function testSectionsSinceStopsAtTheGivenVersionExclusive(): void {
		$sections = [
			[ 'version' => '2.1.270', 'lines' => [] ],
			[ 'version' => '2.1.269', 'lines' => [] ],
			[ 'version' => '2.1.268', 'lines' => [] ],
		];

		$this->assertSame(
			[ $sections[0], $sections[1] ],
			$this->changelog->sectionsSince( $sections, '2.1.268' )
		);
	}

	public function testSectionsSinceReturnsEverythingWhenSinceIsOlderThanAllSections(): void {
		$sections = [ [ 'version' => '2.1.270', 'lines' => [] ] ];

		$this->assertSame( $sections, $this->changelog->sectionsSince( $sections, '2.0.0' ) );
	}

	public function testCategorizeBucketsByLeadingVerb(): void {
		$this->assertSame( 'features', $this->changelog->categorize( 'Added a thing' ) );
		$this->assertSame( 'bugfixes', $this->changelog->categorize( 'Fixed a thing' ) );
		$this->assertSame( 'other', $this->changelog->categorize( 'Improved a thing' ) );
		$this->assertSame( 'other', $this->changelog->categorize( 'Changed a thing' ) );
	}

	public function testCategorizeRequiresATrailingSpaceSoItDoesNotMatchUnrelatedWords(): void {
		// "Addendum" starts with "Add" but isn't an "Added " bullet.
		$this->assertSame( 'other', $this->changelog->categorize( 'Addendum to the docs' ) );
	}

	public function testSummarizeBucketsLinesAndCountsVersions(): void {
		$summary = $this->changelog->summarize( [
			[ 'version' => '2.1.269', 'lines' => [ 'Added X', 'Fixed Y', 'Improved Z' ] ],
			[ 'version' => '2.1.268', 'lines' => [ 'Fixed W' ] ],
		] );

		$this->assertSame( [ 'Added X' ], $summary['features'] );
		$this->assertSame( [ 'Fixed Y', 'Fixed W' ], $summary['bugfixes'] );
		$this->assertSame( [ 'Improved Z' ], $summary['other'] );
		$this->assertSame( 2, $summary['versionCount'] );
	}
}
