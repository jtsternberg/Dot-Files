#!/usr/bin/env php
<?php
namespace JT;
# =============================================================================
# graveyard_router — router script for the `php -S` loopback server behind
# `graveyard page`/`serve`.
# By Justin Sternberg <me@jtsternberg.com>
#
# SERVE-ONLY architecture: the page is never a static snapshot. Every request
# renders from the CURRENT store, so a session buried moments ago shows up on
# the next refresh. This router answers:
#   GET  /  and  /index.html      -> Graveyard::renderStorePageHtml() (fresh)
#   GET  /page-data/<id>.js       -> Graveyard::renderTranscriptJs()  (fresh)
#   POST /api/rename, /api/delete -> Graveyard::handleApi() (same core as CLI)
# Anything else falls through to php -S's static file handling. Bound to
# 127.0.0.1 only by the launching command; never shells out with request data.
# =============================================================================

$cli = require_once dirname(__DIR__) . '/misc/helpers.php';
require_once __DIR__ . '/graveyard_lib.php';

$path   = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
$gy     = new Graveyard($cli, null);

// JSON API — live rename/delete, shared core with the CLI verbs.
if (strpos($path, '/api/') === 0) {
	header('Content-Type: application/json');
	$raw  = (string) file_get_contents('php://input');
	$body = json_decode($raw, true);
	if (!is_array($body)) { $body = []; }
	$res = $gy->handleApi($method, $path, $body);
	http_response_code((int) $res['status']);
	echo json_encode($res['body']);
	return true;
}

// Freshly-rendered transcript payload for the modal's JIT loader.
if (preg_match('#^/page-data/([^/]+)\.js$#', $path, $m)) {
	$id = rawurldecode($m[1]);
	$js = $gy->renderTranscriptJs($id);
	if ($js === null) {
		http_response_code(404);
		header('Content-Type: text/plain');
		echo '// no transcript archived';
		return true;
	}
	header('Content-Type: application/javascript');
	echo $js;
	return true;
}

// The overview page itself — rendered fresh from the store on every hit.
if ($path === '/' || $path === '/index.html') {
	header('Content-Type: text/html; charset=utf-8');
	echo $gy->renderStorePageHtml();
	return true;
}

return false; // let php -S's built-in server handle any other static file
