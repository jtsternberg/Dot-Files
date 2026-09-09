<?php
namespace JT\Tests\Graveyard;

use JT\Tests\TestCase;

/**
 * The `kind` column on `graveyard candidates`.
 *
 * Agent and transport are TWO AXES, not one: `codex` says which agent is running,
 * `herdr` says which multiplexer hosts it, and a session can be any combination. So
 * one column carries both, showing only what departs from the default — Claude under
 * cmux is the overwhelming majority and stays blank.
 *
 * The column only appears when some row is non-default. That is not cosmetic: it is
 * what keeps a cmux-only, Claude-only install's output byte-identical to what it was
 * before graveyard learned about a second transport.
 */
final class GraveyardCandidateKindColumnTest extends TestCase {

	private function row(array $over = []): array {
		return array_merge([
			'session_id' => 'aaaaaaaa-0000-0000-0000-000000000000',
			'agent'       => 'claude',
			'transport'   => 'cmux',
			'idle_seconds' => 3600,
			'cwd'         => '/Users/JT/Sites/proj',
			'workspace_title' => 'ws',
			'tab_title'   => 'Some session title',
			'busy'        => false,
			'targetable'  => true,
			'reason'      => '',
		], $over);
	}

	# --- the label -----------------------------------------------------------

	public function test_the_default_pairing_has_no_label(): void {
		$this->assertSame('', $this->gy->candidateKindLabel($this->row()));
	}

	public function test_each_axis_labels_independently(): void {
		$this->assertSame('herdr', $this->gy->candidateKindLabel($this->row(['transport' => 'herdr'])));
		$this->assertSame('codex', $this->gy->candidateKindLabel($this->row(['agent' => 'codex'])));
	}

	public function test_both_axes_at_once_read_agent_then_transport(): void {
		$this->assertSame(
			'codex/herdr',
			$this->gy->candidateKindLabel($this->row(['agent' => 'codex', 'transport' => 'herdr']))
		);
	}

	public function test_a_missing_agent_or_transport_is_treated_as_the_default(): void {
		$bare = $this->row();
		unset($bare['agent'], $bare['transport']);
		$this->assertSame('', $this->gy->candidateKindLabel($bare));
	}

	# --- the column ----------------------------------------------------------

	public function test_the_title_no_longer_carries_a_bracket_tag(): void {
		$line = $this->gy->candidateLine($this->row(['transport' => 'herdr']), 120, '/Users/JT', 5);

		$this->assertStringNotContainsString('[herdr]', $line);
		$this->assertStringContainsString('herdr', $line);
		$this->assertStringContainsString('Some session title', $line);
	}

	public function test_the_kind_sits_before_the_title_not_after(): void {
		$line = $this->gy->candidateLine($this->row(['transport' => 'herdr']), 120, '/Users/JT', 5);

		$this->assertLessThan(
			mb_strpos($line, 'Some session title'),
			mb_strpos($line, 'herdr'),
			'the kind column belongs between the state and the description'
		);
	}

	public function test_a_default_row_leaves_the_column_blank_but_still_aligned(): void {
		$plain = $this->gy->candidateLine($this->row(), 120, '/Users/JT', 5);
		$kind  = $this->gy->candidateLine($this->row(['transport' => 'herdr']), 120, '/Users/JT', 5);

		// Same column width reserved for both, so titles line up down the list.
		$this->assertSame(
			mb_strpos($kind, 'Some session title'),
			mb_strpos($plain, 'Some session title')
		);
	}

	/**
	 * The invariant worth protecting: with nothing non-default to report, the column
	 * is not reserved at all and the line is exactly what it always was.
	 */
	public function test_zero_width_reserves_no_column_at_all(): void {
		$withColumn = $this->gy->candidateLine($this->row(), 120, '/Users/JT', 5);
		$without    = $this->gy->candidateLine($this->row(), 120, '/Users/JT', 0);

		$this->assertNotSame($withColumn, $without);
		$this->assertLessThan(mb_strlen($withColumn), mb_strlen($without));
		// And it matches the pre-column signature, which defaults to no column.
		$this->assertSame($without, $this->gy->candidateLine($this->row(), 120, '/Users/JT'));
	}

	public function test_the_column_width_is_the_widest_label_present(): void {
		$this->assertSame(0, $this->gy->candidateKindWidth([$this->row(), $this->row()]));
		$this->assertSame(5, $this->gy->candidateKindWidth([$this->row(), $this->row(['transport' => 'herdr'])]));
		$this->assertSame(11, $this->gy->candidateKindWidth([
			$this->row(['transport' => 'herdr']),
			$this->row(['agent' => 'codex', 'transport' => 'herdr']),
		]));
	}

	public function test_the_line_still_respects_the_terminal_width(): void {
		$long = $this->row([
			'agent' => 'codex', 'transport' => 'herdr',
			'tab_title' => str_repeat('very long title ', 12),
			'cwd' => '/Users/JT/Sites/some/deeply/nested/path/that/keeps/going',
		]);
		foreach ([60, 80, 100, 120] as $w) {
			$this->assertLessThanOrEqual($w, mb_strlen($this->gy->candidateLine($long, $w, '/Users/JT', 11)));
		}
	}

	# --- porcelain -----------------------------------------------------------

	/**
	 * Appended, never inserted. Porcelain's columns are positional, so a consumer
	 * reading fields 1-7 must keep working — which is why agent/transport go on the
	 * end rather than beside the title they describe.
	 */
	public function test_porcelain_appends_agent_and_transport_without_moving_anything(): void {
		$cols = explode("\t", $this->gy->formatCandidatePorcelain($this->row([
			'session_id' => 'abc', 'idle_seconds' => 3600, 'workspace_title' => 'proj', 'cwd' => '/x',
			'agent' => 'codex', 'transport' => 'herdr',
		])));

		$this->assertSame(['abc', '3600', 'idle', 'targetable', 'proj', '/x', ''], array_slice($cols, 0, 7));
		$this->assertSame(['codex', 'herdr'], array_slice($cols, 7, 2));
	}

	public function test_porcelain_still_names_the_default_pairing_explicitly(): void {
		// Not blank: a porcelain consumer should never have to infer a default.
		$cols = explode("\t", $this->gy->formatCandidatePorcelain($this->row()));
		$this->assertSame(['claude', 'cmux'], array_slice($cols, 7, 2));
	}
}
