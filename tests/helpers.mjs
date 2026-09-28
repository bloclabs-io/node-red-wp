import { execFileSync } from 'node:child_process';
import { readFileSync, existsSync } from 'node:fs';
import { createHash } from 'node:crypto';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

export const REPO_DIR = path.resolve( path.dirname( fileURLToPath( import.meta.url ) ), '..' );
export const TEST_DIR = process.env.WP_TEST_DIR || path.join( REPO_DIR, '.wp-test' );
export const WP_URL = readFileSync( path.join( TEST_DIR, 'url.txt' ), 'utf8' ).trim();
export const APP_PASSWORD = readFileSync( path.join( TEST_DIR, 'app-password.txt' ), 'utf8' ).trim();
export const API = `${ WP_URL }/wp-json/nrwp/v1`;
export const BASIC_AUTH = 'Basic ' + Buffer.from( `admin:${ APP_PASSWORD }` ).toString( 'base64' );

/** Run a WP-CLI command against the test site and return its trimmed output. */
export function wp( ...args ) {
	return execFileSync(
		'php',
		[ path.join( TEST_DIR, 'wp-cli.phar' ), `--path=${ path.join( TEST_DIR, 'wordpress' ) }`, '--allow-root', ...args ],
		{ encoding: 'utf8' }
	).trim();
}

/** Evaluate PHP inside WordPress. */
export function wpEval( php ) {
	return wp( 'eval', php );
}

/** Update one plugin setting. */
export function setSetting( name, value ) {
	wpEval(
		`$s = (array) get_option( 'nrwp_settings', array() ); $s[ ${ JSON.stringify( name ) } ] = json_decode( ${ JSON.stringify( JSON.stringify( value ) ) }, true ); update_option( 'nrwp_settings', $s );`
	);
}

/** Install an API token and return it. */
export function createToken( token = 'test-token-' + Date.now() ) {
	setSetting( 'token_hash', createHash( 'sha256' ).update( token ).digest( 'hex' ) );
	return token;
}

/** fetch() wrapper returning status, headers and parsed JSON. */
export async function request( method, url, { body, headers = {} } = {} ) {
	const init = { method, headers: { Accept: 'application/json', ...headers } };
	if ( body !== undefined ) {
		init.body = typeof body === 'string' ? body : JSON.stringify( body );
		if ( typeof body !== 'string' ) {
			init.headers[ 'Content-Type' ] = 'application/json';
		}
	}
	const res = await fetch( url, init );
	const text = await res.text();
	let json;
	try {
		json = JSON.parse( text );
	} catch {
		json = undefined;
	}
	return { status: res.status, headers: res.headers, json, text };
}

export const sleep = ( ms ) => new Promise( ( r ) => setTimeout( r, ms ) );

/**
 * PHP notices, warnings, deprecations and fatals logged by the test site, ignoring
 * failed update checks against WordPress.org (offline sandboxes) and deprecations
 * raised by WordPress core's own code.
 */
export function phpErrors() {
	const log = path.join( TEST_DIR, 'debug.log' );
	const content = existsSync( log ) ? readFileSync( log, 'utf8' ) : '';
	return content
		.split( '\n' )
		.filter( ( line ) => /PHP (Notice|Warning|Deprecated|Fatal|Parse)/.test( line ) )
		.filter( ( line ) => ! /wp_version_check|wp_update_plugins|wp_update_themes|WordPress\.org/.test( line ) )
		// Older WordPress releases raise deprecations of their own on newer PHP versions.
		.filter( ( line ) => ! /PHP Deprecated: .* in \S+\/wordpress\/wp-(includes|admin)\//.test( line ) );
}
