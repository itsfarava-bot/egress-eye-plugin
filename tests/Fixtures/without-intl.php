<?php
/**
 * PHP -n probe for genuine operation without ext-intl.
 *
 * @package Egress_Eye
 */

declare(strict_types=1);

define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
require dirname( __DIR__, 2 ) . '/vendor/autoload.php';
require dirname( __DIR__ ) . '/Support/standalone-wordpress.php';

if ( function_exists( 'idn_to_ascii' ) ) {
	exit( 1 );
}

$egress_eye_normalizer = new \EgressEye\Http\HostNormalizer();

if (
	null !== $egress_eye_normalizer->fromUrl( 'https://bücher.example/' )
	|| null !== \EgressEye\Persistence\DomainRepository::canonical_host( 'müller.de' )
	|| 'muller.de' !== \EgressEye\Persistence\DomainRepository::canonical_host( 'muller.de' )
	|| 'example.test' !== $egress_eye_normalizer->fromUrl( 'https://EXAMPLE.TEST./' )
) {
	exit( 1 );
}

echo "without-intl probe passed\n";
