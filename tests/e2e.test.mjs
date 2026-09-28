/**
 * Browser tests: live front end updates, the block editor and the settings screen.
 */
import { test, before, after } from 'node:test';
import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { API, BASIC_AUTH, WP_URL, request, wp, wpEval, setSetting, phpErrors } from './helpers.mjs';

const auth = { Authorization: BASIC_AUTH };
let browser;
let postUrl;
const consoleErrors = [];

function trackErrors( page ) {
	const isLocal = ( url ) => url.startsWith( WP_URL );
	page.on( 'console', ( msg ) => {
		// Resource failures are reported below with their URL, so off-site ones (Gravatar,
		// fonts) can be ignored in offline sandboxes.
		if ( msg.type() === 'error' && ! msg.text().startsWith( 'Failed to load resource' ) ) {
			consoleErrors.push( `${ page.url() }: ${ msg.text() }` );
		}
	} );
	page.on( 'pageerror', ( err ) => consoleErrors.push( `${ page.url() }: ${ err.message }` ) );
	page.on( 'requestfailed', ( req ) => {
		if ( isLocal( req.url() ) && req.failure()?.errorText !== 'net::ERR_ABORTED' ) {
			consoleErrors.push( `${ req.url() }: ${ req.failure()?.errorText }` );
		}
	} );
	page.on( 'response', ( res ) => {
		if ( isLocal( res.url() ) && res.status() >= 500 ) {
			consoleErrors.push( `${ res.url() }: HTTP ${ res.status() }` );
		}
	} );
}

async function login( context ) {
	const page = await context.newPage();
	trackErrors( page );
	await page.goto( `${ WP_URL }/wp-login.php` );
	await page.fill( '#user_login', 'admin' );
	await page.fill( '#user_pass', 'password' );
	await Promise.all( [ page.waitForURL( /wp-admin/ ), page.click( '#wp-submit' ) ] );
	return page;
}

before( async () => {
	wpEval( 'Node_Red_WP::init()->data->delete_all();' );
	setSetting( 'refresh_interval', 1000 );
	setSetting( 'public_read', true );

	const content =
		'<!-- wp:nrwp/data {"dataKey":"e2e_temp","title":"Outside","unit":"°C"} /-->' +
		'<!-- wp:shortcode -->[nodered_data key="e2e_temp"] [nodered_data key="e2e_wind" fallback="calm"]<!-- /wp:shortcode -->';
	const id = wp( 'post', 'create', '--post_status=publish', '--post_title=Live data', `--post_content=${ content }`, '--porcelain' );
	postUrl = wp( 'post', 'url', id );

	browser = await chromium.launch();
} );

after( async () => {
	await browser?.close();
	setSetting( 'refresh_interval', 3000 );
} );

test( 'front end values update live without a reload', async () => {
	await request( 'POST', `${ API }/data/e2e_temp`, { body: { value: 10 }, headers: auth } );

	const context = await browser.newContext();
	const page = await context.newPage();
	trackErrors( page );

	const dataRequests = [];
	page.on( 'request', ( req ) => {
		if ( req.url().includes( '/nrwp/v1/data' ) ) {
			dataRequests.push( req.url() );
		}
	} );

	await page.goto( postUrl );

	const values = page.locator( '.nrwp-data-e2e_temp' );
	assert.equal( await values.count(), 2 );
	assert.equal( await values.first().textContent(), '10' );
	assert.equal( await page.locator( '.wp-block-nrwp-data .nrwp-title' ).textContent(), 'Outside' );
	assert.equal( await page.locator( '.wp-block-nrwp-data .nrwp-unit' ).textContent(), '°C' );
	assert.equal( await page.locator( '.nrwp-data-e2e_wind' ).textContent(), 'calm' );

	// Record the events the script dispatches.
	await page.evaluate( () => {
		window.__nrwpEvents = [];
		document.addEventListener( 'nrwp:update', ( e ) => window.__nrwpEvents.push( e.detail ) );
	} );

	await request( 'POST', `${ API }/data`, { body: { e2e_temp: 11.5, e2e_wind: '<b>gusty</b>' }, headers: auth } );

	await page.waitForFunction( () => document.querySelectorAll( '.nrwp-data-e2e_temp' )[ 1 ]?.textContent === '11.5', null, { timeout: 5000 } );
	await page.waitForFunction( () => document.querySelector( '.nrwp-data-e2e_wind' ).textContent === '<b>gusty</b>', null, { timeout: 5000 } );

	// Values are written as text, never as HTML.
	assert.equal( await page.locator( '.nrwp-data-e2e_wind b' ).count(), 0 );
	assert.equal( await page.locator( 'body' ).getAttribute( 'nrwp-e2e_temp' ), '11.5' );

	const events = await page.evaluate( () => window.__nrwpEvents );
	assert.ok( events.some( ( e ) => e.key === 'e2e_temp' && e.value === '11.5' && e.previous === '10' ) );

	// Deleting a key brings back the fallback text.
	await request( 'DELETE', `${ API }/data/e2e_wind`, { headers: auth } );
	await page.waitForFunction( () => document.querySelector( '.nrwp-data-e2e_wind' ).textContent === 'calm', null, { timeout: 5000 } );

	// One request per refresh for all keys on the page.
	assert.ok( dataRequests.length > 0 );
	for ( const url of dataRequests ) {
		assert.match( decodeURIComponent( url ), /keys=e2e_temp,e2e_wind/ );
	}

	// The script is deferred and loaded in the footer.
	const script = page.locator( 'script#nrwp-js' );
	assert.equal( await script.getAttribute( 'defer' ), '' );

	await context.close();
} );

test( 'the script is only loaded on pages that show data', async () => {
	const res = await fetch( `${ WP_URL }/?p=1` );
	assert.doesNotMatch( await res.text(), /nrwp\.js/ );
} );

test( 'the block works in the block editor', async () => {
	const context = await browser.newContext();
	const page = await login( context );

	await page.goto( `${ WP_URL }/wp-admin/post-new.php` );
	await page.waitForFunction( () => window.wp?.blocks?.getBlockType( 'nrwp/data' ) && window.wp.data.select( 'core/editor' ).getCurrentPostId() );

	const blockType = await page.evaluate( () => {
		const type = window.wp.blocks.getBlockType( 'nrwp/data' );
		return { title: type.title, apiVersion: type.apiVersion, category: type.category };
	} );
	assert.deepEqual( blockType, { title: 'Node-RED Data', apiVersion: 3, category: 'widgets' } );

	await page.evaluate( () => {
		window.wp.data.dispatch( 'core/preferences' ).set( 'core/edit-post', 'welcomeGuide', false );
		const { createBlock } = window.wp.blocks;
		window.wp.data.dispatch( 'core/block-editor' ).insertBlocks( [
			createBlock( 'nrwp/data' ),
			createBlock( 'nrwp/data', { dataKey: 'e2e_temp', unit: '°C' } ),
		] );
	} );

	const canvas = page.frameLocator( 'iframe[name="editor-canvas"]' );
	// Without a key the block asks for one.
	await canvas.locator( '.components-placeholder' ).filter( { hasText: 'Enter the data key to display.' } ).waitFor();
	// With a key the server rendered preview shows the current value.
	await canvas.locator( '.nrwp-data-e2e_temp' ).waitFor();
	assert.equal( await canvas.locator( '.nrwp-data-e2e_temp' ).textContent(), '11.5' );

	// Typing a key in the placeholder updates the block.
	await canvas.locator( '.components-placeholder input' ).fill( 'e2e_temp' );
	await page.waitForFunction( () =>
		window.wp.data.select( 'core/block-editor' ).getBlocks().filter( ( b ) => b.attributes.dataKey === 'e2e_temp' ).length === 2
	);

	// Save and check the stored markup renders on the front end.
	await page.evaluate( () => {
		window.wp.data.dispatch( 'core/editor' ).editPost( { title: 'Editor test', status: 'publish' } );
		return window.wp.data.dispatch( 'core/editor' ).savePost();
	} );
	const postId = await page.evaluate( () => window.wp.data.select( 'core/editor' ).getCurrentPostId() );
	const content = wp( 'post', 'get', String( postId ), '--field=post_content' );
	assert.match( content, /<!-- wp:nrwp\/data {"dataKey":"e2e_temp","unit":"°C"} \/-->/ );

	const html = await ( await fetch( wp( 'post', 'url', String( postId ) ) ) ).text();
	assert.match( html, /class="nrwp-data nrwp-data-e2e_temp" data-key="e2e_temp"/ );

	const invalid = await page.evaluate( () => window.wp.data.select( 'core/block-editor' ).getBlocks().filter( ( b ) => ! b.isValid ).length );
	assert.equal( invalid, 0 );

	await context.close();
} );

test( 'the settings screen manages the token and access', async () => {
	const context = await browser.newContext();
	const page = await login( context );

	await page.goto( `${ WP_URL }/wp-admin/options-general.php?page=node-red-wp` );
	assert.equal( await page.locator( 'h1' ).textContent(), 'Node-RED' );
	assert.equal( await page.locator( 'input[name="nrwp_settings[refresh_interval]"]' ).inputValue(), '1000' );
	assert.ok( await page.locator( 'text=e2e_temp' ).count() );

	// Generate a token, shown once.
	await page.click( '#nrwp_generate' );
	const token = await page.locator( '.notice-success input' ).inputValue();
	assert.equal( token.length, 40 );
	await page.reload();
	assert.equal( await page.locator( '.notice-success input' ).count(), 0 );
	assert.ok( await page.locator( 'text=A token is active.' ).count() );

	const write = await request( 'POST', `${ API }/data/from_token`, { body: { value: 'ok' }, headers: { 'X-NRWP-Token': token } } );
	assert.equal( write.status, 200 );

	// Turn off public reads through the form.
	await page.uncheck( 'input[name="nrwp_settings[public_read]"]' );
	await Promise.all( [ page.waitForURL( /settings-updated=true/ ), page.click( '#submit' ) ] );
	assert.equal( ( await request( 'GET', `${ API }/data/from_token` ) ).status, 401 );
	// ...and the token survived saving the form.
	assert.equal( ( await request( 'GET', `${ API }/data/from_token`, { headers: { 'X-NRWP-Token': token } } ) ).status, 200 );

	// Logged-in visitors can still see live values thanks to the REST nonce.
	await page.goto( postUrl );
	const refreshed = page.waitForResponse( ( r ) => r.url().includes( '/nrwp/v1/data' ) );
	assert.equal( ( await refreshed ).status(), 200 );

	await page.goto( `${ WP_URL }/wp-admin/options-general.php?page=node-red-wp` );
	await page.check( 'input[name="nrwp_settings[public_read]"]' );
	await Promise.all( [ page.waitForURL( /settings-updated=true/ ), page.click( '#submit' ) ] );
	assert.equal( ( await request( 'GET', `${ API }/data/from_token` ) ).status, 200 );

	// Revoke.
	await page.click( '#nrwp_revoke' );
	assert.ok( await page.locator( 'text=No token is configured.' ).count() );
	const denied = await request( 'POST', `${ API }/data/from_token`, { body: { value: 'no' }, headers: { 'X-NRWP-Token': token } } );
	assert.equal( denied.status, 401 );

	await context.close();
} );

test( 'no browser console errors and no PHP errors', () => {
	assert.deepEqual( consoleErrors, [] );
	assert.deepEqual( phpErrors(), [] );
} );
