<?php

namespace JT\CLI;

/**
 * Namespaced exception.
 *
 * @since 1.0.1
 */
class Exception extends \Exception {
	public $cli  = true;
	public $data = [];
}
