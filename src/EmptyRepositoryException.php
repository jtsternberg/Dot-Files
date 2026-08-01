<?php
namespace JT;

/**
 * Thrown by GitUserLogger when a cloned repository is empty (no commits yet),
 * so callers can report "empty repository" without treating it as a fatal error.
 */
class EmptyRepositoryException extends \Exception {}
