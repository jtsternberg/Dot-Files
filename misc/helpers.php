<?php
// Transitional shim (src/ migration): forwards to src/bootstrap.php.
// Deleted in Task 4 once bin/graveyard is rewired.
return require_once dirname(__DIR__) . '/src/bootstrap.php';
