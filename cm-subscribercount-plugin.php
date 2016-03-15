<?php

/**
 * @package Campaign Monitor Subscriber Count
 */

/*

Plugin Name: Campaign Monitor Subscriber Count

Description: This plugin integrates a Wordpress site with the Campaign Monitor API, specifically to map a Wordpress option - usable anywhere in the template - to the subscriber count of a Campaign Monitor list. There is an optional caching ability, which reduces the number of requests on the Campaign Monitor API. If you set CMCOUNT_CACHESECONDS to 0, requests won't be cached (inadvisible on a busy site). To output the signature count in your template, use 'echo number_format(get_option(CMCOUNT_OPTIONNAME, 5000));' in a PHP tag. In this example, 5000 is the default value that get_option will output if it can't access the live count - this number can be changed to whatever you'd like, or removed completely.

NOTE: You must enter a valid API Key and ListID from your Campaign Monitor account in the 'Edit' screen of the plugin file (documentation: https://www.campaignmonitor.com/api/). Requires PHP CURL, which is normally found in standard PHP configurations.

Version: 1.0
Author: Luke Cohen
Author URI: http://lukejournal.co.uk/
License: GPLv2 or later

*/

define('CM_APIKEY', 'enter-your-api-key');
define('CM_LISTID', 'enter-your-list-id');
define('CMCOUNT_OPTIONNAME', 'cmcount_total');
define('CMCOUNT_LASTPOLL_OPTIONNAME', 'cmcount_lastpoll_timestamp');
define('CMCOUNT_CACHESECONDS', 30);

function triggerPollIfCacheExpired() {

  $cmcount_lastpoll_timestamp = get_option(CMCOUNT_LASTPOLL_OPTIONNAME, $default = 0);

  if ($cmcount_lastpoll_timestamp <> 0) {

    /* the last poll timestamp has been set, meaning we have a time to compare with CMCOUNT_CACHESECONDS */
    $curtimestamp = time();

    if (($curtimestamp - $cmcount_lastpoll_timestamp) > CMCOUNT_CACHESECONDS) {

      /* CMCOUNT_CACHESECONDS has passed, so a new poll is triggered */

      if (WP_DEBUG === true) {
        echo "cache has expired (last poll at " . $cmcount_lastpoll_timestamp . " | current time: " . $curtimestamp . " | " . ($curtimestamp - $cmcount_lastpoll_timestamp) . "). polling...";
        flush();
      }

      attemptPollAPI();

    } else {

      /* CMCOUNT_CACHESECONDS hasn't yet passed, exit */
      if (WP_DEBUG === true) {
        echo "cache not expired, no new poll. exiting.";
        flush();
      }

      return;

    }

  } else {

    /* the WP options haven't been created (i.e if this code is run for the first time), so we create them here */
    add_option(CMCOUNT_LASTPOLL_OPTIONNAME, time());
    add_option(CMCOUNT_OPTIONNAME, 0);

    /* now trigger the first poll */
    attemptPollAPI();

  }

}

function attemptPollAPI() {

  /* this function attempts to connect to the CampaignMonitor API, and download the latest subscriber count */
  $apiCallURI = "https://api.createsend.com/api/v3.1/lists/" . CM_LISTID . "/stats.json";

  /* set up cURL for a http request to the CM API */
  $ch = curl_init();

  curl_setopt($ch, CURLOPT_URL, $apiCallURI);
  curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
  curl_setopt($ch, CURLOPT_USERPWD, CM_APIKEY . ":x");

  $apiResult = curl_exec($ch);

  if(curl_errno($ch) <> 0) {

    /* there has been an error connecting to the API, firstly close the cURL */
    curl_close($ch);

    /*

    reset the cache seconds clock, so we don't poll again on the next request,
    rationale being that if a HTTP error has occured, the API probably needs a
    minute to recover.

    */
    update_option(CMCOUNT_LASTPOLL_OPTIONNAME, time());

  } else {

    /* no network or server error, let's grab the latest count and update the WP option */
    curl_close($ch);

    $apiResult = json_decode($apiResult, true);

    if (array_key_exists("TotalActiveSubscribers", $apiResult)) {

      /* we have an API response and a TotalActiveSubscribers number */
      $totalSubscribers = $apiResult["TotalActiveSubscribers"];
      update_option(CMCOUNT_OPTIONNAME, intval($totalSubscribers));

      /* reset cache timer */
      update_option(CMCOUNT_LASTPOLL_OPTIONNAME, time());

    } else {

      /*

      we have an API response but it doesn't contain the TotalActiveSubscribers key.

      something has obviously gone wrong with the CM API, so we treat this as an error,
      resetting the cache clock accordingly to give the CM API time to recover if
      there is an error.

      */
      update_option(CMCOUNT_LASTPOLL_OPTIONNAME, time());

    }

  }

  /* for debugging: */
  //$all_options = wp_load_alloptions();
  //print_r($all_options);

}

/* create WP hook so the functions are fired on every request */
add_action('init', triggerPollIfCacheExpired);

?>
