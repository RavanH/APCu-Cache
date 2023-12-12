<?php
/*
Plugin Name: APCu Cache
Plugin URI: https://github.com/RavanH/APCu-Cache
Description: An APCu based cache to reduce database load.
Version: 0.9.3
Author: Ian Barber, Chris Hastie, RavanH
Author URI: https://status301.net/
*/

// Verify APCu is installed, suggested by @ozh
if ( ! function_exists( 'apcu_exists' ) ) {
   yourls_die( 'This plugin requires the APCu extension: https://pecl.php.net/package/APCu' );
}

// keys for APC storage
if ( ! defined( 'YAPC_ID' ) ) {
	define( 'YAPC_ID', 'yapcache-' );
}
define( 'YAPC_LOG_INDEX', YAPC_ID . 'log_index' );
define( 'YAPC_LOG_TIMER', YAPC_ID . 'log_timer' );
define( 'YAPC_LOG_UPDATE_LOCK', YAPC_ID . 'log_update_lock' );
define( 'YAPC_CLICK_INDEX', YAPC_ID . 'click_index' );
define( 'YAPC_CLICK_TIMER', YAPC_ID . 'click_timer' );
define( 'YAPC_CLICK_KEY_PREFIX', YAPC_ID . 'clicks-' );
define( 'YAPC_CLICK_UPDATE_LOCK', YAPC_ID . 'click_update_lock' );
define( 'YAPC_KEYWORD_PREFIX', YAPC_ID . 'keyword-' );
define( 'YAPC_ALL_OPTIONS', YAPC_ID . 'get_all_options' );
//define( 'YAPC_YOURLS_INSTALLED', YAPC_ID . 'yourls_installed' );
define( 'YAPC_BACKOFF_KEY', YAPC_ID . 'backoff' );
define( 'YAPC_CLICK_INDEX_LOCK', YAPC_ID . 'click_index_lock' );

// configurable options
if ( ! defined( 'YAPC_WRITE_CACHE_TIMEOUT' ) ) {
	define( 'YAPC_WRITE_CACHE_TIMEOUT', 120 );
}
if ( ! defined( 'YAPC_READ_CACHE_TIMEOUT' ) ) {
	define( 'YAPC_READ_CACHE_TIMEOUT', 3600 );
}
if ( ! defined( 'YAPC_LONG_TIMEOUT' ) ) {
	define( 'YAPC_LONG_TIMEOUT', 86400 );
}
if ( ! defined( 'YAPC_MAX_LOAD' ) ) {
	define( 'YAPC_MAX_LOAD', 0.7 );
}
if ( ! defined( 'YAPC_MAX_UPDATES' ) ) {
	define( 'YAPC_MAX_UPDATES', 200 );
}
if ( ! defined( 'YAPC_MAX_CLICKS' ) ) {
	define( 'YAPC_MAX_CLICKS', 30 );
}
if ( ! defined( 'YAPC_BACKOFF_TIME' ) ) {
	define( 'YAPC_BACKOFF_TIME', 30 );
}
if ( ! defined( 'YAPC_WRITE_CACHE_HARD_TIMEOUT' ) ) {
	define( 'YAPC_WRITE_CACHE_HARD_TIMEOUT', 600 );
}
if ( ! defined( 'YAPC_LOCK_TIMEOUT' ) ) {
	define( 'YAPC_LOCK_TIMEOUT', 30 );
}
if ( ! defined( 'YAPC_API_USER' ) ) {
	define( 'YAPC_API_USER', '' );
}

yourls_add_action( 'pre_get_keyword', 'yapc_pre_get_keyword' );
yourls_add_filter( 'get_keyword_infos', 'yapc_get_keyword_infos' );
if ( ! defined( 'YAPC_SKIP_CLICKTRACK' ) ) {
	yourls_add_filter( 'shunt_update_clicks', 'yapc_shunt_update_clicks' );
	yourls_add_filter( 'shunt_log_redirect', 'yapc_shunt_log_redirect' );
}
if ( defined( 'YAPC_REDIRECT_FIRST' ) && YAPC_REDIRECT_FIRST ) {
	// set a very low priority to ensure any other plugins hooking here run first,
	// as we die at the end of yapc_redirect_shorturl
	yourls_add_action( 'redirect_shorturl', 'yapc_redirect_shorturl', 999);
}
//yourls_add_filter( 'shunt_all_options', 'yapc_shunt_all_options' );
//yourls_add_action( 'get_all_options', 'yapc_get_all_options' );
//yourls_add_action( 'add_option', 'yapc_option_change' );
//yourls_add_action( 'delete_option', 'yapc_option_change' );
//yourls_add_action( 'update_option', 'yapc_option_change' );
//yourls_add_filter( 'edit_link', 'yapc_edit_link' );
yourls_add_filter( 'api_actions', 'yapc_api_filter' );

/**
 * Set cached options if available
 *
 * @param bool $pre
 * @return bool true
 */
function yapc_shunt_all_options( $pre ) {
	$ydb = yourls_get_db();

	if ( apcu_exists( YAPC_ALL_OPTIONS ) ) {
		$options = apcu_fetch( YAPC_ALL_OPTIONS );
		if ( empty( $options ) || ! is_array( $options ) ) {
			return $pre;
		}
		foreach ( $options as $name => $value ) {
			$ydb->set_option( $name, yourls_maybe_unserialize($value) );
		}
		//yourls_set_installed( apcu_fetch( YAPC_YOURLS_INSTALLED ) );
		yourls_set_installed( true );

		yapc_debug( "shunt_all_options: Set options to " . print_r( $options, true ) );

		return true;
	}

	return $pre;
}

/**
 * Cache all_options data.
 *
 * @param string $options
 */
function yapc_get_all_options( $options ) {
	apcu_store( YAPC_ALL_OPTIONS, $options, YAPC_READ_CACHE_TIMEOUT );
	// Set timeout on installed property twice as long as the options as otherwise there could be a split second gap
	//apcu_store( YAPC_YOURLS_INSTALLED, true, (2 * YAPC_READ_CACHE_TIMEOUT ) );
}

/**
 * Clear the options cache if an option is altered
 * This covers changes to plugins too
 *
 * @param string $args
 */
function yapc_option_change( $args ) {
	apcu_delete( YAPC_ALL_OPTIONS );
}

/**
 * If the URL data is in the cache, stick it back into the global DB object.
 *
 * @param string $args
 */
function yapc_pre_get_keyword( $args ) {
	$ydb = yourls_get_db();
	$keyword = $args[0];
	$use_cache = isset( $args[1]) ? $args[1] : true;

	// Lookup in cache
	if ( $use_cache && apcu_exists( yapc_get_keyword_key( $keyword ) ) ) {
		$ydb->set_infos( $keyword, apcu_fetch( yapc_get_keyword_key( $keyword ) ) );
	}
}

/**
 * Store the keyword info in the cache
 *
 * @param array $info
 * @param string $keyword
 */
function yapc_get_keyword_infos( $info, $keyword ) {
	// Store in cache
	apcu_store( yapc_get_keyword_key( $keyword ), $info, YAPC_READ_CACHE_TIMEOUT );

	return $info;
}

/**
 * Delete a cache entry for a keyword if that keyword is edited.
 *
 * @param array $return
 * @param string $url
 * @param string $keyword
 * @param string $newkeyword
 * @param string $title
 * @param bool $new_url_already_there
 * @param bool $keyword_is_ok
 */
function yapc_edit_link( $return, $url, $keyword, $newkeyword, $title, $new_url_already_there, $keyword_is_ok ) {
	if ( $return['status'] != 'fail' ) {
		apcu_delete( yapc_get_keyword_key( $keyword ) );
	}

	return $return;
}

/**
 * Update the number of clicks in a performant manner.  This manner of storing does
 * mean we are pretty much guaranteed to lose a few clicks.
 *
 * @param string $keyword
 */
function yapc_shunt_update_clicks( $false, $keyword ) {

	// Initalize the timer.
	if ( ! apcu_exists( YAPC_CLICK_TIMER ) ) {
		apcu_add( YAPC_CLICK_TIMER, time() );
	}

	if ( defined( 'YAPC_STATS_SHUNT' ) ) {
		if ( YAPC_STATS_SHUNT == "drop" ) {
			return true;
		} else if ( YAPC_STATS_SHUNT == "none" ){
			return false;
		}
	}

	$keyword = yourls_sanitize_keyword( $keyword );
	$key = YAPC_CLICK_KEY_PREFIX . $keyword;

	// Store in cache.
	$added = false;
	$clicks = 1;
	if ( ! apcu_exists( $key ) ) {
		$added = apcu_add( $key, $clicks );
	}
	if ( !$added) {
		$clicks = yapc_key_increment( $key );
	}

	/**
	 * We need to keep a record of which keywords we have
	 * data cached for. We do this in an associative array
	 * stored at YAPC_CLICK_INDEX, with keyword as the keyword.
	 */
	$idxkey = YAPC_CLICK_INDEX;
	yapc_lock_click_index();
	if ( apcu_exists( $idxkey) ) {
		$clickindex = apcu_fetch( $idxkey);
	} else {
		$clickindex = array();
	}
	$clickindex[$keyword] = 1;
	apcu_store( $idxkey, $clickindex );
	yapc_unlock_click_index();

	if ( yapc_write_needed( 'click', $clicks ) ) {
		yapc_write_clicks();
	}

	return true;
}

/**
 * write any cached clicks out to the database
 */
function yapc_write_clicks() {
	$ydb = yourls_get_db();
	yapc_debug( "write_clicks: Writing clicks to database" );
	$updates = 0;
	// set up a lock so that another hit doesn't start writing too
	if ( ! apcu_add( YAPC_CLICK_UPDATE_LOCK, 1, YAPC_LOCK_TIMEOUT ) ) {
		yapc_debug( "write_clicks: Could not lock the click index. Abandoning write", true );

		return $updates;
	}

	if ( apcu_exists( YAPC_CLICK_INDEX ) ) {
		yapc_lock_click_index();
		$clickindex = apcu_fetch( YAPC_CLICK_INDEX );
		if ( $clickindex === false || ! apcu_delete( YAPC_CLICK_INDEX ) ) {
			// if apcu_delete fails it's because the key went away. We probably have a race condition
			yapc_unlock_click_index();
			yapc_debug( "write_clicks: Index key disappeared. Abandoning write", true );
			apcu_store( YAPC_CLICK_TIMER, time() );

			return $updates;
		}
		yapc_unlock_click_index();

		/**
		 * As long as the tables support transactions, it's much faster to wrap all the updates
		 * up into a single transaction. Reduces the overhead of starting a transaction for each
		 * query. The down side is that if one query errors we'll loose the log.
		 */
		$ydb->query( "START TRANSACTION" );
		foreach ( $clickindex as $keyword => $z) {
			$key = YAPC_CLICK_KEY_PREFIX . $keyword;
			$value = 0;
			if ( ! apcu_exists( $key ) ) {
				yapc_debug( "write_clicks: Click key $key dissappeared. Possible data loss!", true );
				continue;
			}
			$value += yapc_key_zero( $key );
			yapc_debug( "write_clicks: Adding $value clicks for $keyword" );
			// Write value to DB
			$ydb->query( "UPDATE `" .
							YOURLS_DB_TABLE_URL.
						"` SET `clicks` = clicks + " . $value .
						" WHERE `keyword` = '" . $keyword . "'" );
			$updates++;
		}
		yapc_debug( "write_clicks: Committing changes" );
		$ydb->query( "COMMIT" );
	}
	apcu_store( YAPC_CLICK_TIMER, time() );
	apcu_delete( YAPC_CLICK_UPDATE_LOCK);
	yapc_debug( "write_clicks: Updated click records for $updates URLs" );

	return $updates;
}

/**
 * Update the log in a performant way. There is a reasonable chance of losing a few log entries.
 * This is a good trade off for us, but may not be for everyone.
 *
 * @param string $keyword
 */
function yapc_shunt_log_redirect( $false, $keyword ) {

	if ( defined( 'YAPC_STATS_SHUNT' ) ) {
		if ( YAPC_STATS_SHUNT == "drop" ) {
			return true;
		} else if ( YAPC_STATS_SHUNT == "none" ){
			return false;
		}
	}
	// Respect setting in YOURLS_NOSTATS. Why you'd want to enable the plugin and
	// set YOURLS_NOSTATS true I don't know ;)
	if ( ! yourls_do_log_redirect() )
		return true;

	// Initialise the time.
	if ( ! apcu_exists( YAPC_LOG_TIMER ) ) {
		apcu_add( YAPC_LOG_TIMER, time() );
	}
	$ip = yourls_get_IP();
	$args = array(
		'now' => date( 'Y-m-d H:i:s' ),
        'keyword'  => yourls_sanitize_keyword($keyword),
        'referrer' => substr( yourls_get_referrer(), 0, 200 ),
        'ua'       => substr(yourls_get_user_agent(), 0, 255),
        'ip'       => $ip,
        'location' => yourls_geo_ip_to_countrycode($ip),
	);

	// Separated out the calls to make a bit more readable here
	$key = YAPC_LOG_INDEX;
	$logindex = 0;
	$added = false;

	if ( ! apcu_exists( $key ) ) {
		$added = apcu_add( $key, 0 );
	}

	$logindex = yapc_key_increment( $key );

	// We now have a reserved logindex, so lets cache
	apcu_store( yapc_get_logindex( $logindex ), $args, YAPC_LONG_TIMEOUT );

	// If we've been caching for over a certain amount do write
	if ( yapc_write_needed( 'log' ) ) {
		// We can add, so lets flush the log cache
		yapc_write_log();
	}

	return true;
}

/**
 * write any cached log entries out to the database
 */
function yapc_write_log() {
	$table = YOURLS_DB_TABLE_LOG;
	$updates = 0;
	// set up a lock so that another hit doesn't start writing too
	if ( ! apcu_add( YAPC_LOG_UPDATE_LOCK, 1, YAPC_LOCK_TIMEOUT ) ) {
		yapc_debug( "write_log: Could not lock the log index. Abandoning write", true );

		return $updates;
	}
	yapc_debug( "write_log: Writing log to database" );

	$key = YAPC_LOG_INDEX;
	$index = apcu_fetch( $key );
	if ( $index === false ) {
		yapc_debug( "write_log: key $key has disappeared. Abandoning write." );
		apcu_store( YAPC_LOG_TIMER, time() );
		apcu_delete( YAPC_LOG_UPDATE_LOCK);

		return $updates;
	}
	$fetched = 0;
	$n = 0;
	$loop = true;
	$values = array();

	// Retrieve all items and reset the counter
	while( $loop) {
		for( $i = $fetched+1; $i <= $index; $i++) {
			$row = apcu_fetch( yapc_get_logindex($i) );
			if ( $row === false ) {
				yapc_debug( "write_log: log entry " . yapc_get_logindex($i) . " disappeared. Possible data loss!!", true );
			} else {
				$values[] = $row;
			}
		}

		$fetched = $index;
		$n++;

		if ( apcu_cas( $key, $index, 0 ) ) {
			$loop = false;
		} else {
			usleep(500 );
			$index = apcu_fetch( $key );
		}
	}
	yapc_debug( "write_log: $fetched log entries retrieved; index reset after $n tries" );

	// Insert each log message - we're assuming input filtering happened earlier
	foreach( $values as $value ) {
		if ( !is_array( $value) ) {
		  yapc_debug( "write_log: log row is not an array. Skipping" );
		  continue;
		}
		// Try and log. An error probably means a concurrency problem : just skip the logging
		try {
			$result = yourls_get_db()->fetchAffected("INSERT INTO `$table` (click_time, shorturl, referrer, user_agent, ip_address, country_code) VALUES (:now, :keyword, :referrer, :ua, :ip, :location)", $value );
		} catch (Exception $e) {
			$result = 0;
		}
		yapc_debug( "write_log: " . print_r( $value, true ) );
		if ( $result ) $updates++;
	}

	apcu_store( YAPC_LOG_TIMER, time() );
	apcu_delete( YAPC_LOG_UPDATE_LOCK);
	yapc_debug( "write_log: Added $updates entries to log" );

	return $updates;
}

/**
 * Helper function to return a cache key for the log index.
 *
 * @param string $key
 * @return string
 */
function yapc_get_logindex( $key ) {
	return YAPC_LOG_INDEX . "-" . $key;
}

/**
 * Helper function to return a keyword key.
 *
 * @param string $key
 * @return string
 */
function yapc_get_keyword_key( $keyword ) {
	return YAPC_KEYWORD_PREFIX . $keyword;
}

/**
 * Helper function to do an atomic increment to a variable,
 *
 *
 * @param string $key
 * @return void
 */
function yapc_key_increment( $key ) {
	$n = 1;
	while( !$result = apcu_inc( $key ) ) {
		usleep(500 );
		$n++;
	}
	if ( $n > 1) yapc_debug( "key_increment: took $n tries on key $key" );

	return $result;
}

/**
 * Reset a key to 0 in a atomic manner
 *
 * @param string $key
 * @return old value before the reset
 */
function yapc_key_zero( $key ) {
	$old = 0;
	$n = 1;
	$old = apcu_fetch( $key );
	if ( $old == 0 ) {
		return $old;
	}
	while( ! apcu_cas( $key, $old, 0 ) ) {
		usleep(500 );
		$n++;
		$old = apcu_fetch( $key );
		if ( $old == 0 ) {
			yapc_debug( "key_zero: Key zeroed by someone else. Try $n. Key $key" );

			return $old;
		}
	}
	if ( $n > 1) yapc_debug( "key_zero: Key $key zeroed from $old after $n tries" );

	return $old;
}

/**
 * Helper function to manage a voluntary lock on YAPC_CLICK_INDEX
 *
 * @return true when locked
 */
function yapc_lock_click_index() {
	$n = 1;
	// we always unlock as soon as possilbe, so a TTL of 1 should be fine
	while( ! apcu_add( YAPC_CLICK_INDEX_LOCK, 1, 1) ) {
		$n++;
		usleep(500 );
	}
	if ( $n > 1) yapc_debug( "lock_click_index: Locked click index in $n tries" );

	return true;
}

/**
 * Helper function to unlock a voluntary lock on YAPC_CLICK_INDEX
 *
 * @return void
 */
function yapc_unlock_click_index() {
	apcu_delete( YAPC_CLICK_INDEX_LOCK );
}

/**
 * Send debug messages to PHP's error log
 *
 * @param string $msg
 * @param bool $important
 * @return void
 */
function yapc_debug( $msg, $important=false ) {
	if ( $important || ( defined( 'YAPC_DEBUG' ) && YAPC_DEBUG ) ) {
		error_log( "yourls_apcu_cache: " . $msg);
	}
}

/**
 * Check if the server load is above our maximum threshold for doing DB writes
 *
 * @return bool true if load exceeds threshold, false otherwise
 */
function yapc_load_too_high() {
	if ( YAPC_MAX_LOAD == 0 )
		// YAPC_MAX_LOAD of 0 means don't do load check
		return false;
	if ( stristr( PHP_OS, 'win' ) )
		// can't get load on Windows, so just assume it's OK
		return false;
	$load = sys_getloadavg();
	if ( $load[0] < YAPC_MAX_LOAD )
		return false;

	return true;
}

/**
 * Count number of click updates that are cached
 *
 * @return int number of keywords with cached clicks
 */
function yapc_click_updates_count() {
	$count = 0;
	if ( apcu_exists( YAPC_CLICK_INDEX ) ) {
		$clickindex = apcu_fetch( YAPC_CLICK_INDEX );
		$count = count( $clickindex );
	}

	return $count;
}

/**
 * Check if we need to do a write to DB yet
 * Considers time since last write, system load etc
 *
 * @param string $type either 'click' or 'log'
 * @param int $clicks number of clicks cached for current URL
 * @return bool true if a DB write is due, false otherwise
 */
function yapc_write_needed( $type, $clicks=0 ) {

	if ( $type == 'click' ) {
		$timerkey = YAPC_CLICK_TIMER;
		$count = yapc_click_updates_count();
	} elseif ( $type = 'log' ) {
		$timerkey = YAPC_LOG_TIMER;
		$count = apcu_fetch( YAPC_LOG_INDEX );
	} else {
		return false;
	}
	if ( empty( $count) ) $count = 0;
	yapc_debug( "write_needed: Info: $count $type updates in cache" );

	if ( ! empty( $clicks ) ) yapc_debug( "write_needed: Info: current URL has $clicks cached clicks" );

	if ( apcu_exists( $timerkey ) ) {
		$lastupdate = apcu_fetch( $timerkey );
		$elapsed = time() - $lastupdate;
		yapc_debug( "write_needed: Info: Last $type write $elapsed seconds ago at " . date( "%T" , $lastupdate ) );

		/**
		 * In the tests below YAPC_WRITE_CACHE_TIMEOUT of 0 means never do a write on the basis of
		 * time elapsed, YAPC_MAX_UPDATES of 0 means never do a write on the basis of number
		 * of queued updates, YAPC_MAX_CLICKS of 0 means never write on the basis of the number
		 * clicks pending.
		 */

		// if we reached YAPC_WRITE_CACHE_HARD_TIMEOUT force a write out no matter what
		if ( ! empty( YAPC_WRITE_CACHE_TIMEOUT ) && $elapsed > YAPC_WRITE_CACHE_HARD_TIMEOUT ) {
			yapc_debug( "write_needed: True: Reached hard timeout ( " . YAPC_WRITE_CACHE_HARD_TIMEOUT ." ). Forcing write for $type after $elapsed seconds" );

			return true;
		}

		// if we've backed off because of server load, don't write
		if ( apcu_exists( YAPC_BACKOFF_KEY) ) {
			yapc_debug( "write_needed: False: Won't do write for $type during backoff period" );

			return false;
		}

		// have we either reached YAPC_WRITE_CACHE_TIMEOUT or exceeded YAPC_MAX_UPDATES or YAPC_MAX_CLICKS
		if (( ! empty( YAPC_WRITE_CACHE_TIMEOUT ) && $elapsed > YAPC_WRITE_CACHE_TIMEOUT )
			|| ( ! empty( YAPC_MAX_UPDATES) && $count > YAPC_MAX_UPDATES )
			|| ( ! empty( YAPC_MAX_CLICKS) && ! empty( $clicks ) && $clicks > YAPC_MAX_CLICKS) ) {
			// if server load is high, delay the write and set a backoff so we won't try again
			// for a short while
			if ( yapc_load_too_high() ) {
				yapc_debug( "write_needed: False: System load too high. Won't try writing to database for $type", true );
				apcu_add( YAPC_BACKOFF_KEY, time(), YAPC_BACKOFF_TIME);

				return false;
			}
			yapc_debug( "write_needed: True: type: $type; count: $count; elapsed: $elapsed; clicks: $clicks; YAPC_WRITE_CACHE_TIMEOUT: " . YAPC_WRITE_CACHE_TIMEOUT . "; YAPC_MAX_UPDATES: " . YAPC_MAX_UPDATES . "; YAPC_MAX_CLICKS: " . YAPC_MAX_CLICKS);

			return true;
		}

		return false;
	}

	// The timer key went away. Better do an update to be safe
	yapc_debug( "write_needed: True: reason: no $type timer found" );

	return true;
}

/**
 * Add the flushcache method to the API
 *
 * @param array $api_action
 * @return array $api_action
 */
function yapc_api_filter( $api_actions ) {
	$api_actions['flushcache'] = 'yapc_force_flush';

	return $api_actions;
}

/**
 * Force a write of both clicks and logs to the database
 *
 * @return array $return status of updates
 */
function yapc_force_flush() {
	// YAPC_API_USER of false means disable API.
	// YAPC_API_USER of empty string means allow any user to use API.
	// Otherwise only the specified user is allowed.
	$user = defined( 'YOURLS_USER' ) ? YOURLS_USER : '-1';
	if ( YAPC_API_USER === false ) {
		yapc_debug( "force_flush: Attempt to use API flushcache function whilst it is disabled. User: $user", true );
		$return = array(
			'simple'    => 'Error: The flushcache function is disabled',
			'message'   => 'Error: The flushcache function is disabled',
			'errorCode' => 403,
		);
	}
	elseif ( ! empty( YAPC_API_USER) && YAPC_API_USER != $user) {
		yapc_debug( "force_flush: Unauthorised attempt to use API flushcache function by $user", true );
		$return = array(
			'simple'    => 'Error: User not authorised to use the flushcache function',
			'message'   => 'Error: User not authorised to use the flushcache function',
			'errorCode' => 403,
		);
	} else {
		yapc_debug( "force_flush: Forcing write to database from API call" );
		// Write log.
		$start = microtime( true );
		$log_updates = yapc_write_log();
		$log_time = sprintf( "%01.3f", 1000*(microtime( true ) - $start) );
		// Write clicks.
		$start = microtime( true );
		$click_updates = yapc_write_clicks();
		$click_time = sprintf( "%01.3f", 1000*(microtime( true ) - $start) );

		$return = array(
			'clicksUpdated'   => $click_updates,
			'clickUpdateTime' => $click_time,
			'logsUpdated' => $log_updates,
			'logUpdateTime' => $log_time,
			'statusCode' => 200,
			'simple'     => "Updated clicks for $click_updates URLs in $click_time ms. Logged $log_updates hits in $log_time ms.",
			'message'    => 'Success',
		);
	}

	return $return;
}

/**
 * Replaces yourls_redirect. Does redirect first, then does logging and click
 * recording afterwards so that redirect is not delayed
 * This is somewhat fragile and may be broken by other plugins that hook on
 * pre_redirect, redirect_location or redirect_code
 *
 */
function yapc_redirect_shorturl( $args ) {
	$code = defined( 'YAPC_REDIRECT_FIRST_CODE' ) ? YAPC_REDIRECT_FIRST_CODE : 301;
	$location = $args[0];
	$keyword = $args[1];
	yourls_do_action( 'pre_redirect', $location, $code );
	$location = yourls_apply_filter( 'redirect_location', $location, $code );
	$code     = yourls_apply_filter( 'redirect_code', $code, $location );
	// Redirect, either properly if possible, or via Javascript otherwise
	if ( !headers_sent() ) {
		yourls_status_header( $code );
		header( "Location: $location" );
		// force the headers to be sent
		echo "Redirecting to $location\n";
		@ob_end_flush();
		@ob_flush();
		flush();
	} else {
		yourls_redirect_javascript( $location );
	}

	$start = microtime( true );
	// Update click count in main table
	$update_clicks = yourls_update_clicks( $keyword );

	// Update detailed log for stats
	$log_redirect = yourls_log_redirect( $keyword );
	$lapsed = sprintf( "%01.3f", 1000*(microtime( true ) - $start) );
	yapc_debug( "redirect_shorturl: Database updates took $lapsed ms after sending redirect" );

	die();
}
