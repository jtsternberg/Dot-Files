#!/usr/bin/env php
<?php
namespace JT;
# =============================================================================
# graveyard_router — router script for `php -S` used by `graveyard serve`.
# By Justin Sternberg <me@jtsternberg.com>
#
# Serves the store's static files (index.html, page-data/) as-is, and answers
# the tiny JSON API (POST /api/rename, POST /api/delete) via
# Graveyard::handleApi() — the same rename/delete/purge core the CLI verbs
# use. Bound to 127.0.0.1 only by the launching `serve` command; this router
# never shells out with request data.
# =============================================================================

$cli = require_once dirname(__DIR__) . '/misc/helpers.php';
require_once __DIR__ . '/graveyard_lib.php';

$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if (strpos($path, '/api/') !== 0) {
	return false; // let php -S's built-in server handle static files
}

header('Content-Type: application/json');

$raw  = (string) file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body)) { $body = []; }

$gy  = new Graveyard($cli, null);
$res = $gy->handleApi((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'), $path, $body);

http_response_code((int) $res['status']);
echo json_encode($res['body']);
return true;
