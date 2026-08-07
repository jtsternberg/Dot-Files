<?php
namespace JT\Tests\CmuxBak\Doubles;

use JT\CLI\Helpers;

/** CLI helper whose prompts are scripted, so a restore run never blocks on input. */
final class PromptHelpers extends Helpers {

	/** @var string[] Answers handed to ask(), in order. */
	public array $answers = [];

	/** @var string[] Questions ask() was called with. */
	public array $asked = [];

	public function __construct() {
		parent::__construct();
	}

	public function ask( $question = '', $emptyError = '' ) {
		$this->asked[] = $question;

		return (string) array_shift( $this->answers );
	}
}
