<?php
// Early loader for APCu Cache.

$cache_plugin = YOURLS_PLUGINDIR . '/apcu-cache/plugin.php';

if ( file_exists( $cache_plugin ) && function_exists( 'apcu_exists' ) ) {
	include( $cache_plugin );
}