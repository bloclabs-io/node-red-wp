/**
 * REST API, rendering and migration tests against a real WordPress install.
 *
 * Run `tests/bin/setup-wordpress.sh` first.
 */
import { test, before, after } from 'node:test';
import assert from 'node:assert/strict';
import { API, BASIC_AUTH, request, wp, wpEval, setSetting, createToken, phpErrors } from './helpers.mjs';

const auth = { Authorization: BASIC_AUTH };
let token;

before( () => {
	wpEval( 'Node_Red_WP::init()->data->delete_all();' );
	setSetting( 'public_read', true );
	setSetting( 'legacy_routes', true );
	token = createToken();
} );

after( () => {
	setSetting( 'public_read', true );
	setSetting( 'legacy_routes', true );
} );

test( 'registers the nrwp/v1 namespace with every route', async () => {
	const { status, json } = await request( 'GET', API );
	assert.equal( status, 200 );
	for ( const route of [ '/data', '/data/(?P<key>[a-zA-Z0-9_-]+)', '/keys', '/stats', '/get_all' ] ) {
		assert.ok( json.routes[ `/nrwp/v1${ route }` ], `missing route ${ route }` );
	}
} );

test( 'rejects anonymous writes', async () => {
	const res = await request( 'POST', `${ API }/data/temperature`, { body: { value: 1 } } );
	assert.equal( res.status, 401 );
	assert.equal( res.json.code, 'rest_forbidden' );

	const batch = await request( 'POST', `${ API }/data`, { body: { temperature: 1 } } );
	assert.equal( batch.status, 401 );

	const del = await request( 'DELETE', `${ API }/data/temperature` );
	assert.equal( del.status, 401 );
} );

test( 'rejects a wrong token', async () => {
	const res = await request( 'POST', `${ API }/data/temperature`, { body: { value: 1 }, headers: { 'X-NRWP-Token': 'nope' } } );
	assert.equal( res.status, 401 );
} );

test( 'writes with an Application Password and reads anonymously', async () => {
	const set = await request( 'POST', `${ API }/data/temperature`, { body: { value: 21.5 }, headers: auth } );
	assert.equal( set.status, 200 );
	assert.equal( set.json.key, 'temperature' );
	assert.equal( set.json.value, 21.5 );
	assert.match( set.json.updated, /^\d{4}-\d\d-\d\dT/ );

	const get = await request( 'GET', `${ API }/data/temperature` );
	assert.equal( get.status, 200 );
	assert.equal( get.json.value, 21.5 );
	assert.match( get.headers.get( 'cache-control' ), /no-cache/ );
} );

test( 'writes with the API token, including PUT and form bodies', async () => {
	const put = await request( 'PUT', `${ API }/data/humidity`, { body: { value: 40 }, headers: { 'X-NRWP-Token': token } } );
	assert.equal( put.status, 200 );
	assert.equal( put.json.value, 40 );

	const form = await request( 'POST', `${ API }/data/status`, {
		body: 'value=Partly+cloudy',
		headers: { 'X-NRWP-Token': token, 'Content-Type': 'application/x-www-form-urlencoded' },
	} );
	assert.equal( form.status, 200 );
	assert.equal( form.json.value, 'Partly cloudy' );
} );

test( 'preserves value types', async () => {
	const cases = [ [ 'str_zero', '007' ], [ 'flag', true ], [ 'off', false ], [ 'count', 3 ], [ 'neg', -0.25 ] ];
	for ( const [ key, value ] of cases ) {
		const res = await request( 'POST', `${ API }/data/${ key }`, { body: { value }, headers: auth } );
		assert.equal( res.status, 200, key );
		const get = await request( 'GET', `${ API }/data/${ key }` );
		assert.deepEqual( get.json.value, value, key );
	}
} );

test( 'validates keys and values', async () => {
	const obj = await request( 'POST', `${ API }/data/bad`, { body: { value: { a: 1 } }, headers: auth } );
	assert.equal( obj.status, 400 );

	const long = await request( 'POST', `${ API }/data/bad`, { body: { value: 'x'.repeat( 5000 ) }, headers: auth } );
	assert.equal( long.status, 400 );
	assert.equal( long.json.code, 'nrwp_invalid_value' );

	const missing = await request( 'POST', `${ API }/data/bad`, { body: {}, headers: auth } );
	assert.equal( missing.status, 400 );

	const invalidKey = await request( 'POST', `${ API }/data/bad.key`, { body: { value: 1 }, headers: auth } );
	assert.equal( invalidKey.status, 404 );

	assert.equal( ( await request( 'GET', `${ API }/data/bad` ) ).status, 404 );
} );

test( 'normalizes keys to lowercase', async () => {
	const res = await request( 'POST', `${ API }/data/Beam_X`, { body: { value: 1 }, headers: auth } );
	assert.equal( res.json.key, 'beam_x' );
	assert.equal( ( await request( 'GET', `${ API }/data/BEAM_X` ) ).json.value, 1 );
} );

test( 'batch writes several keys and reports invalid ones', async () => {
	const res = await request( 'POST', `${ API }/data`, {
		body: { wind_speed: 12.3, wind_dir: 'NW', 'bad key!': { nested: true } },
		headers: { 'X-NRWP-Token': token },
	} );
	assert.equal( res.status, 200 );
	assert.deepEqual( res.json.updated, [ 'wind_speed', 'wind_dir' ] );
	assert.ok( res.json.errors[ 'bad key!' ] );

	const empty = await request( 'POST', `${ API }/data`, { body: {}, headers: auth } );
	assert.equal( empty.status, 400 );
} );

test( 'survives concurrent writes to different keys', async () => {
	const keys = Array.from( { length: 12 }, ( _, i ) => `parallel_${ i }` );
	await Promise.all(
		keys.map( ( key, i ) => request( 'POST', `${ API }/data/${ key }`, { body: { value: i }, headers: { 'X-NRWP-Token': token } } ) )
	);
	const all = await request( 'GET', `${ API }/data?keys=${ keys.join( ',' ) }` );
	assert.deepEqual( Object.keys( all.json ).length, keys.length );
	keys.forEach( ( key, i ) => assert.equal( all.json[ key ], i ) );
} );

test( 'lists data and keys', async () => {
	const all = await request( 'GET', `${ API }/data` );
	assert.equal( all.status, 200 );
	assert.equal( all.json.temperature, 21.5 );
	assert.equal( all.json.wind_dir, 'NW' );

	const some = await request( 'GET', `${ API }/data?keys=temperature,wind_dir,unknown` );
	assert.deepEqual( some.json, { temperature: 21.5, wind_dir: 'NW' } );

	const arr = await request( 'GET', `${ API }/data?keys[]=humidity` );
	assert.deepEqual( arr.json, { humidity: 40 } );

	const keys = await request( 'GET', `${ API }/keys` );
	assert.ok( keys.json.includes( 'temperature' ) );
	assert.deepEqual( keys.json, [ ...keys.json ].sort() );
} );

test( 'deletes keys', async () => {
	await request( 'POST', `${ API }/data/doomed`, { body: { value: 1 }, headers: auth } );
	const del = await request( 'DELETE', `${ API }/data/doomed`, { headers: auth } );
	assert.equal( del.status, 200 );
	assert.deepEqual( del.json, { key: 'doomed', deleted: true } );
	assert.equal( ( await request( 'DELETE', `${ API }/data/doomed`, { headers: auth } ) ).status, 404 );
	assert.equal( ( await request( 'GET', `${ API }/data/doomed` ) ).status, 404 );
} );

test( 'legacy 0.x endpoints keep their response format', async () => {
	const anon = await request( 'GET', `${ API }/set/legacy/1` );
	assert.equal( anon.status, 401 );

	const set = await request( 'GET', `${ API }/set/legacy/Partly%20Cloudy`, { headers: { 'X-NRWP-Token': token } } );
	assert.deepEqual( set.json, { status: true, data: false, error: false, error_message: 'Value updated.' } );

	const get = await request( 'GET', `${ API }/get/legacy` );
	assert.deepEqual( get.json, { status: true, data: 'Partly Cloudy', error: false, error_message: false } );

	const missing = await request( 'GET', `${ API }/get/nope` );
	assert.equal( missing.json.status, false );
	assert.equal( missing.json.error, true );

	const all = await request( 'GET', `${ API }/get_all` );
	assert.equal( all.json.status, true );
	assert.equal( all.json.data.legacy, 'Partly Cloudy' );

	const keys = await request( 'GET', `${ API }/get_keys` );
	assert.ok( keys.json.data.includes( 'legacy' ) );
} );

test( 'legacy endpoints can be switched off', async () => {
	setSetting( 'legacy_routes', false );
	assert.equal( ( await request( 'GET', `${ API }/get/legacy` ) ).status, 404 );
	setSetting( 'legacy_routes', true );
	assert.equal( ( await request( 'GET', `${ API }/get/legacy` ) ).status, 200 );
} );

test( 'public read access can be switched off', async () => {
	setSetting( 'public_read', false );
	try {
		assert.equal( ( await request( 'GET', `${ API }/data/temperature` ) ).status, 401 );
		assert.equal( ( await request( 'GET', `${ API }/data` ) ).status, 401 );
		assert.equal( ( await request( 'GET', `${ API }/data/temperature`, { headers: auth } ) ).status, 200 );
		assert.equal( ( await request( 'GET', `${ API }/data/temperature`, { headers: { 'X-NRWP-Token': token } } ) ).status, 200 );
	} finally {
		setSetting( 'public_read', true );
	}
} );

test( 'stats endpoint requires auth and reports missing Jetpack', async () => {
	assert.equal( ( await request( 'GET', `${ API }/stats` ) ).status, 401 );
	const res = await request( 'GET', `${ API }/stats`, { headers: auth } );
	assert.equal( res.status, 501 );
	assert.equal( res.json.code, 'nrwp_no_jetpack' );
} );

test( 'shortcode, block and widget render escaped live markup', () => {
	wpEval( `Node_Red_WP::init()->data->set( 'xss', '<img src=x onerror=alert(1)>' );` );

	const shortcode = wpEval( `echo do_shortcode( '[nodered_data key="xss" title="Danger" unit="°C"]' );` );
	assert.match( shortcode, /<h2 class="nrwp-title">Danger<\/h2>/ );
	assert.match( shortcode, /<span class="nrwp-data nrwp-data-xss" data-key="xss" data-fallback="—" data-value="&lt;img/ );
	assert.match( shortcode, />&lt;img src=x onerror=alert\(1\)&gt;<\/span><span class="nrwp-unit">°C<\/span>/ );
	assert.doesNotMatch( shortcode, /<img/ );

	const fallback = wpEval( `echo do_shortcode( '[nodered_data key="never_set" fallback="n/a"]' );` );
	assert.match( fallback, /data-key="never_set" data-fallback="n\/a">n\/a<\/span>/ );
	assert.doesNotMatch( fallback, /data-value/ );

	assert.equal( wpEval( `echo do_shortcode( '[nodered_data]' );` ), '' );

	const block = wpEval( `echo do_blocks( '<!-- wp:nrwp/data {"dataKey":"temperature","unit":"°C"} /-->' );` );
	assert.match( block, /<div class="wp-block-nrwp-data"><span class="nrwp-data nrwp-data-temperature" data-key="temperature" data-fallback="—" data-value="21.5">21.5<\/span><span class="nrwp-unit">°C<\/span><\/div>/ );

	const widget = wpEval(
		`the_widget( 'Node_Red_WP_Data_Widget', array( 'title' => 'Temp', 'datakey' => 'temperature', 'unit' => '°C' ), array( 'before_title' => '<h3>', 'after_title' => '</h3>' ) );`
	);
	assert.match( widget, /<h3>Temp<\/h3><span class="nrwp-data nrwp-data-temperature"[^>]*>21.5<\/span>/ );
} );

test( 'block is registered from block.json', () => {
	const out = JSON.parse(
		wpEval( `$b = WP_Block_Type_Registry::get_instance()->get_registered( 'nrwp/data' ); echo wp_json_encode( array( 'api' => $b->api_version, 'editor' => $b->editor_script_handles ) );` )
	);
	assert.equal( out.api, 3 );
	assert.equal( out.editor.length, 1 );
	const deps = JSON.parse( wpEval( `echo wp_json_encode( wp_scripts()->registered[ ${ JSON.stringify( out.editor[ 0 ] ) } ]->deps );` ) );
	assert.ok( deps.includes( 'wp-server-side-render' ) );
} );

test( 'migrates data stored by 0.x', () => {
	wpEval( `update_option( 'nrwp_data', array( 'OldTemp' => '12.5', 'temperature' => 'ignored, newer value exists' ) );` );
	wpEval( 'Node_Red_WP_Data::maybe_migrate();' );
	assert.equal( wpEval( `echo Node_Red_WP::init()->data->get( 'oldtemp' );` ), '12.5' );
	assert.equal( wpEval( `echo Node_Red_WP::init()->data->get( 'temperature' );` ), '21.5' );
	assert.equal( wpEval( `var_export( get_option( 'nrwp_data', 'gone' ) );` ), "'gone'" );
} );

test( 'uninstall removes every option', () => {
	const out = wpEval(
		`$before = $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->options} WHERE option_name LIKE 'nrwp%'" );` +
			`define( 'WP_UNINSTALL_PLUGIN', 'node-red-wp/node-red-wp.php' ); include WP_PLUGIN_DIR . '/node-red-wp/uninstall.php';` +
			`$after = $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->options} WHERE option_name LIKE 'nrwp%'" );` +
			`echo "$before $after";`
	);
	const [ beforeCount, afterCount ] = out.split( ' ' ).map( Number );
	assert.ok( beforeCount > 5 );
	assert.equal( afterCount, 0 );
	// Restore the token so later suites keep working.
	token = createToken( token );
} );

test( 'no PHP notices, warnings or deprecations were logged', () => {
	assert.deepEqual( phpErrors(), [] );
	assert.doesNotMatch( wp( 'plugin', 'status', 'node-red-wp' ), /Inactive/ );
} );
