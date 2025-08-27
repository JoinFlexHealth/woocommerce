<?php
/**
 * PHP Scoper Configuration.
 *
 * @package Flex
 */

declare(strict_types=1);

use Isolated\Symfony\Component\Finder\Finder;

$excluded_files = array(
	'composer.json',
	'composer.lock',
	'scoper.inc.php',
);

// Add all files from symfony/polyfill-php84 to exclusions.
$exclude_finder = Finder::create() // @phpstan-ignore class.notFound
	->files()
	->in( __DIR__ . '/vendor/symfony/polyfill-*' );

foreach ( $exclude_finder as $file ) {
	$excluded_files[] = str_replace( __DIR__ . '/', '', $file->getPathname() );
}
$excluded     = array();
$excludes_dir = __DIR__ . '/../../excludes';

$excluded_classes   = array();
$excluded_functions = array();
$excluded_constants = array();

if ( is_dir( $excludes_dir ) ) {
	$files = scandir( $excludes_dir );

	foreach ( $files as $file ) {
		if ( '.' === $file || '..' === $file || pathinfo( $file, PATHINFO_EXTENSION ) !== 'php' ) {
					continue;
		}
		$file_path = $excludes_dir . '/' . $file;
		$symbols   = require $file_path;

		if ( str_ends_with( $file, '-classes.php' ) || str_ends_with( $file, '-interfaces.php' ) ) {
			$excluded_classes = array_merge( $excluded_classes, $symbols );
			continue;
		}

		if ( str_ends_with( $file, '-constants.php' ) ) {
			$excluded_constants = array_merge( $excluded_constants, $symbols );
			continue;
		}

		if ( str_ends_with( $file, '-functions.php' ) ) {
			$excluded_functions = array_merge( $excluded_functions, $symbols );
			continue;
		}
	}
}

return array(
	'prefix'             => 'Flex',
	'output-dir'         => '../../build/pay-with-flex',
	'exclude-files'      => $excluded_files,
	'php-version'        => '8.4',
	'exclude-namespaces' => array(
		'Symfony\\Polyfill\\*',
	),
	'exclude-classes'    => $excluded_classes,
	'exclude-functions'  => $excluded_functions,
	'exclude-constants'  => $excluded_constants,
);
