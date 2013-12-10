#!/usr/bin/env php
<?php
// Die if not running as CLI.
if( PHP_SAPI !== 'cli' ){
  die("WAT?!");
}
// Enable Garbage collection.
if (false == gc_enabled()) {
  gc_enable();
}

// Limit error reporting and use syslog (output to DAEMON facility and standard error).
error_reporting(E_ERROR | E_PARSE);
openlog('varnish-worker', LOG_CONS | LOG_NDELAY | LOG_PID | LOG_PERROR, LOG_DAEMON);
syslog(LOG_INFO, "--- varnish-worker started ---");

// Main loop exit flag (set when handling SIGTERM and SIGINT).
global $EXIT;
$EXIT = FALSE;

// Signal handling (if pcntl is enabled)
if (extension_loaded('pcntl')) {
  declare(ticks = 1);

  function sig_handler($signo) {
    static $signames = array(
      SIGTERM => 'SIGTERM',
      SIGINT => 'SIGINT',
    );
    $signame = isset($signames[$signo]) ? $signames[$signo] : $signo;
    syslog(LOG_INFO, "--- Signal $signame received ---");
    switch ($signo) {
      case SIGTERM:
      case SIGINT:
        global $EXIT;
        $EXIT = TRUE;
        break;
    }
  }
  pcntl_signal(SIGTERM, 'sig_handler');
  pcntl_signal(SIGINT, 'sig_handler');
}
else {
  syslog(LOG_WARNING, "Process Control (PCNTL) not supported, signal handling disabled.");
}

require 'vendor/autoload.php';

$varnish_defaults = array(
  'host' => '127.0.0.1',
  'port' => 6082,
  'version' => '2.1',
);
$no_gearman_server = TRUE;
// Instances of VarnishAdminSocket to run commands with.
$sockets = array();

// Initialize the Gearman worker.
$worker = new GearmanWorker();
$worker->setTimeout(1000);

// Initialize the VarnishAdminSocket for all configured Varnish Servers.
$ini_file = __DIR__ . DIRECTORY_SEPARATOR . 'varnish-worker.ini';
$ini = file_exists($ini_file) ? parse_ini_file($ini_file, TRUE) : FALSE;
if ($ini !== FALSE) {
  foreach ($ini as $k => $v) {
    switch($k) {
      case 'servers':
        if (is_array($v) && !empty($v)) {
          $no_gearman_server = FALSE;
          $servers = implode(',', $v);
          if (!$worker->addServers($servers)) {
            syslog(LOG_ERR, "Invalid Gearman server(s): $servers");
          }
        }
        break;
      default :
        if (is_array($v) && (isset($v['host']) || isset($v['port']) || isset($v['version']))) {
          $config = $v + $varnish_defaults;
          try {
            $sockets[$k] = new VarnishAdminSocket($config['host'], $config['port'], $config['version']);
            if (isset($config['secret'])) {
              if (file_exists($config['secret'])) {
                $sockets[$k]->set_auth(file_get_contents($config['secret']));
              }
              else {
                $sockets[$k]->set_auth($config['secret']);
              }
            }
            syslog(LOG_INFO, "Varnish server configuration: host={$config['host']}, port={$config['port']}, version={$config['version']}");
          }
          catch (Exception $e) {
            syslog(LOG_ERR, "Invalid Varnish server configuration: host={$config['host']}, port={$config['port']}, version={$config['version']}/n{$e->getMessage()}");
          }
        }
      break;
    }
  }
  syslog(LOG_INFO, "Config loaded from " . $ini_file);
}
else {
  syslog(LOG_WARNING, "Unable to load config from " . $ini_file);
}

// Exit if no (valid) Varnish server.
if (empty($sockets)) {
  syslog(LOG_WARNING, "No Varnish server, exiting.");
  die("No Varnish Servers found, exiting...\n");
}
// Add default Gearman server if none are configured.
if ($no_gearman_server) {
  syslog(LOG_WARNING, "No Gearman server configured, using localhost.");
  $worker->addServer();
}

$worker->addFunction("varnish_ban_url", "varnish_ban_url", $sockets);

// Main loop (exit on SIGTERM, SIGINT and Gearman failure).
while (!$EXIT && ($worker->work() || $worker->returnCode() == GEARMAN_TIMEOUT)) {
  if (GEARMAN_SUCCESS != $worker->returnCode() && $worker->returnCode() != GEARMAN_TIMEOUT) {
    syslog(LOG_ERR, "Worker failed: {$worker->error()}");
  }
  // TODO: Close connected sockets if not used after a short timeout.
}

// Log the last Gearman error (if any) when exiting the loop.
if (GEARMAN_SUCCESS != $worker->returnCode() && $worker->returnCode() != GEARMAN_TIMEOUT) {
  syslog(LOG_ERR, "Worker failed: {$worker->error()}");
}

syslog(LOG_INFO, "--- varnish-worker stopped ---");

// Worker functions

/**
 * Execute a ban command on the Varnish servers.
 * @param GearmanJob $job
 *   The Gearman job for the command, workload must an string as expected by VarnishAdminSocket::purge_url().
 * @param $varnish_sockets
 *   An array of VarnishAdminSocket instances for all configured Varnish server, indexed by server names (as provided in the config file).
 * @return array
 *   The outputs of the command executed on all Varnish server ads an array of strings.
 */
function varnish_ban_url(GearmanJob $job, &$varnish_sockets) {
  $results = array();
  $expr = $job->workload();
  // TODO: Validate $expr to avoid issuing invalid command to the Varnish servers.
  $socket_count = 0;
  foreach ($varnish_sockets as $name => $varnish_socket) {
    $socket_count += 1;
    try {
      // TODO: Connect socket only ig not already connected.
      $varnish_socket->connect();
      $results[$name] = $varnish_socket->purge_url($expr);
      $varnish_socket->quit();
      syslog(LOG_INFO, "Command executed 'ban.url $expr' on $name: {$results[$name]}");
    }
    catch (Exception $e) {
      // Handle permanent or semi permanent error (ie. unable to connect to varnish server) and disable sockets.
      $results[$name] = "ERROR: {$e->getMessage()}";
      syslog(LOG_ERR, "Command failed 'ban.url $expr' on $name: {$results[$name]}");
      $varnish_socket->close();
    }
    // TODO: Don't close the socket here (see TODO comment in main loop).
    // Report progress (for listening client).
    $job->sendStatus($socket_count, count($varnish_socket));
  }
  return json_encode($results);
}
