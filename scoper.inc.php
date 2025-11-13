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

$excluded = array();
$dirs     = array(
	__DIR__ . '/../../excludes',
	__DIR__ . '/../../vendor/sniccowp/php-scoper-wordpress-excludes/generated',
);

$excluded_classes   = array();
$excluded_functions = array();
$excluded_constants = array();

foreach ( $dirs as $dir ) {
	if ( ! is_dir( $dir ) ) {
		throw new \Exception( "Directory does not exist: $dir" ); //phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
	}

	$files = scandir( $dir );

	foreach ( $files as $file ) {
		if ( '.' === $file || '..' === $file || pathinfo( $file, PATHINFO_EXTENSION ) !== 'json' ) {
					continue;
		}
		$file_path = $dir . '/' . $file;
		$symbols   = json_decode( file_get_contents( $file_path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		if ( str_ends_with( $file, '-classes.json' ) || str_ends_with( $file, '-interfaces.json' ) || str_ends_with( $file, '-traits.json' ) ) {
			$excluded_classes = array_merge( $excluded_classes, $symbols );
			continue;
		}

		if ( str_ends_with( $file, '-constants.json' ) ) {
			$excluded_constants = array_merge( $excluded_constants, $symbols );
			continue;
		}

		if ( str_ends_with( $file, '-functions.json' ) ) {
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
		'Symfony\Polyfill',
	),
	'exclude-classes'    => $excluded_classes,
	'exclude-functions'  => $excluded_functions,
	'exclude-constants'  => $excluded_constants,
);
