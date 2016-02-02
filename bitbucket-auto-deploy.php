<?php

# ===========================================================================
#             ==== No Humans Below. Only Honey Badgers. =====
# ===========================================================================

$bitbucket_IP_ranges = array(
                        '131.103.20.160/27',
                        '165.254.145.0/26',
                        '104.192.143.0/24'
                       );

$ip        = $_SERVER['REMOTE_ADDR'];
$protocol  = ( isset($_SERVER['SERVER_PROTOCOL'] ) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.0' );
$conf_file = __DIR__ . '/config.php';

if ( ! file_exists( $conf_file ) || ! is_readable( $conf_file ) ) {
    $error = 'config file not found';
    deployLogger( $error, true );

    header( $protocol . ' 400 Bad Request' );
    die( $error );
}

require_once $conf_file;

if ( empty( $ip ) || ! check_ip_in_range( $ip, $bitbucket_IP_ranges ) ) {
    header( $protocol . ' 400 Bad Request' );
    die( 'invalid ip address' );
} else if ( ! isset( $_GET['key'] ) && ! empty( $key ) && $_GET['key'] != $key ) {
    header( $protocol . ' 400 Bad Request' );
    die( 'invalid key' );
} else if ( empty( json_decode( file_get_contents( 'php://input' ), true) ) ) {
    header( $protocol . ' 400 Bad Request' );
    die( 'missing payload' );
}

# Receive POST data
$payload = json_decode( file_get_contents( 'php://input' ), true );

# Attempt to detect branch name
if ( isset( $payload['push'] ) ) {
    $lastChange = $payload['push']['changes'][ count( $payload['push']['changes'] ) - 1 ]['new'];
    $branch     = isset( $lastChange['name'] ) && ! empty( $lastChange['name'] ) ? $lastChange['name'] : '';

    if ( $branch == '' ) {
        header( $protocol . ' 400 Bad Request' );
        die( 'missing branch' );
    }
} else {
    header( $protocol . ' 400 Bad Request' );
    die( 'missing payload' );
}

# Container for directories
$dirs_to_update = array();

if ( empty( $repo_branch ) || empty( $repo_dir ) || empty( $web_root_dir ) ) {
    $error = 'missing configurations';
    deployLogger( $error );

    header( $protocol . ' 400 Bad Request' );
    die( $error );
}

// Your remote name
// Aliases for branches and directories
$aliases = array(
                $repo_branch => $repo_dir
           );

# Check for branch aliases
if ( array_key_exists( $branch, $aliases ) ) {

    if ( is_dir( $aliases[$branch] ) ) {
        $dirs_to_update[] = $aliases[$branch];
    }

}

# Check to see if there is nothing to do
if ( empty( $dirs_to_update ) ) {
    die( "Apparently there is nothing to update for this branch\n" );
}

# Capture current directory
$original_dir = getcwd();
$output       = array();

# Loop through the directories
foreach ( $dirs_to_update as $dir ) {

    chdir( $dir );

    $output[] = "Branch: " . $branch;
    exec( "git fetch origin " . $branch, $output );
    exec( "GIT_WORK_TREE=" . $web_root_dir . " git checkout -f", $output );
    $output[] = "Commit Hash: " . shell_exec( 'git rev-parse --short HEAD' );

    chdir( $original_dir );

}

deployLogger( $output );


# The End

# =====
# UTILS
# =====
function check_ip_in_range( $ip, $cidr_ranges ) {

    // Check if given IP is inside a IP range with CIDR format
    $ip = ip2long( $ip );
    if ( ! is_array( $cidr_ranges ) ) {
        $cidr_ranges = array( $cidr_ranges );
    } 
  
    foreach ( $cidr_ranges as $cidr_range ) {
        list( $subnet, $mask ) = explode( '/', $cidr_range );
        if ( ( $ip & ~( ( 1 << ( 32 - $mask ) ) - 1 ) ) == ip2long( $subnet ) ) { 
        return true;
        }
    }

    return false;

}

function deployLogger( $message, $force = false ) {

    global $log;

    if ( $force || ! empty( $log ) ) {
        if ( is_array( $message ) ) {
            $message = implode( PHP_EOL, $message );
        }

        if ( ! empty( $message ) ) {
            $logPath     = __DIR__ . DIRECTORY_SEPARATOR . 'log';
            $logFilePath = $logPath . DIRECTORY_SEPARATOR . date( 'Y-m-d' ) . '.log';
            file_put_contents(
                                $logFilePath,
                                'Webhook deployment ' . date( "F j, Y, g:i a" ) . ': ' . $message . "\n",
                                FILE_APPEND
                             );
        }
    }

}