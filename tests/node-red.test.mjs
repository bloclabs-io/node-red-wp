/**
 * End-to-end test: run the shipped example flow (examples/node-red-flow.json) in a real
 * Node-RED instance against the test WordPress site.
 *
 * The only change made to the flow is pointing the weather request at a local mock of
 * the Open-Meteo API and filling in the flow's environment variables.
 */
import { test, before, after } from 'node:test';
import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { createServer } from 'node:http';
import { mkdtempSync, readFileSync, writeFileSync, rmSync } from 'node:fs';
import { createRequire } from 'node:module';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { API, REPO_DIR, WP_URL, request, wpEval, createToken, sleep, phpErrors } from './helpers.mjs';

// Test another Node-RED install with NODE_RED_DIR=/path/to/project-with-node-red.
const require = createRequire( process.env.NODE_RED_DIR ? path.join( process.env.NODE_RED_DIR, 'package.json' ) : import.meta.url );
const NODE_RED_VERSION = require( 'node-red/package.json' ).version;
const NODE_RED_PORT = Number( process.env.NODE_RED_PORT || 1881 );
const NODE_RED_URL = `http://127.0.0.1:${ NODE_RED_PORT }`;
const WEATHER = { current: { temperature_2m: -3.4, wind_speed_10m: 14.2, wind_direction_10m: 270 } };

let weatherServer;
let nodeRed;
let nodeRedLog = '';
let userDir;
let comms;
const debugMessages = [];

function waitFor( predicate, what, timeout = 30000 ) {
	const start = Date.now();
	return ( async () => {
		while ( Date.now() - start < timeout ) {
			const result = await predicate();
			if ( result ) {
				return result;
			}
			await sleep( 250 );
		}
		throw new Error( `Timed out waiting for ${ what }.\nNode-RED log:\n${ nodeRedLog }` );
	} )();
}

before( async () => {
	wpEval( 'Node_Red_WP::init()->data->delete_all();' );
	const token = createToken();

	weatherServer = createServer( ( req, res ) => {
		res.writeHead( 200, { 'Content-Type': 'application/json' } );
		res.end( JSON.stringify( WEATHER ) );
	} );
	await new Promise( ( r ) => weatherServer.listen( 0, '127.0.0.1', r ) );
	const weatherUrl = `http://127.0.0.1:${ weatherServer.address().port }/v1/forecast?current=temperature_2m`;

	const flow = JSON.parse( readFileSync( path.join( REPO_DIR, 'examples/node-red-flow.json' ), 'utf8' ) );
	const tab = flow.find( ( n ) => n.type === 'tab' );
	tab.env = [
		{ name: 'WP_URL', value: WP_URL, type: 'str' },
		{ name: 'NRWP_TOKEN', value: token, type: 'str' },
	];
	const weatherNode = flow.find( ( n ) => n.type === 'http request' && n.url.includes( 'open-meteo' ) );
	assert.ok( weatherNode, 'example flow has an Open-Meteo request' );
	weatherNode.url = weatherUrl;

	userDir = mkdtempSync( path.join( tmpdir(), 'nrwp-node-red-' ) );
	writeFileSync( path.join( userDir, 'flows.json' ), JSON.stringify( flow ) );

	nodeRed = spawn(
		process.execPath,
		[ require.resolve( 'node-red/red.js' ), '--userDir', userDir, '--port', String( NODE_RED_PORT ), '--no-telemetry', 'flows.json' ],
		{ env: { ...process.env, NO_PROXY: `127.0.0.1,localhost,${ process.env.NO_PROXY || '' }` } }
	);
	nodeRed.stdout.on( 'data', ( d ) => ( nodeRedLog += d ) );
	nodeRed.stderr.on( 'data', ( d ) => ( nodeRedLog += d ) );

	await waitFor( () => /Started flows/.test( nodeRedLog ), 'Node-RED to start' );

	comms = new WebSocket( `ws://127.0.0.1:${ NODE_RED_PORT }/comms` );
	comms.addEventListener( 'message', ( event ) => {
		for ( const message of JSON.parse( event.data ) ) {
			if ( message.topic === 'debug' ) {
				debugMessages.push( message.data );
			}
		}
	} );
	await new Promise( ( r ) => comms.addEventListener( 'open', r ) );
	comms.send( JSON.stringify( { subscribe: 'debug' } ) );
} );

after( async () => {
	comms?.close();
	nodeRed?.kill();
	weatherServer?.close();
	await sleep( 250 );
	if ( userDir ) {
		rmSync( userDir, { recursive: true, force: true } );
	}
} );

test( `runs on Node-RED ${ NODE_RED_VERSION }`, () => {
	assert.ok( nodeRedLog.includes( `Node-RED version: v${ NODE_RED_VERSION }` ) );
	assert.doesNotMatch( nodeRedLog, /\[error\]/ );
} );

test( 'the example flow pushes weather data into WordPress', async () => {
	const data = await waitFor( async () => {
		const res = await request( 'GET', `${ API }/data` );
		return res.json && res.json.whistler_temp !== undefined ? res.json : null;
	}, 'weather data in WordPress' );

	assert.deepEqual( data, { whistler_temp: -3.4, whistler_wind_dir: 270, whistler_wind_speed: 14.2 } );

	const response = await waitFor(
		() => debugMessages.find( ( m ) => m.name === 'WordPress response' ),
		'the WordPress response in the debug sidebar'
	);
	assert.deepEqual( JSON.parse( response.msg ).updated, [ 'whistler_temp', 'whistler_wind_speed', 'whistler_wind_dir' ] );
} );

test( 'the example flow reads a value back from WordPress', async () => {
	const res = await fetch( `${ NODE_RED_URL }/inject/a1f0c0de00000007`, { method: 'POST' } );
	assert.equal( res.status, 200 );

	const message = await waitFor( () => debugMessages.find( ( m ) => m.name === 'Current value' ), 'the read back value' );
	assert.equal( JSON.parse( message.msg ), -3.4 );
} );

test( 'Node-RED logged no errors and WordPress no PHP errors', () => {
	assert.doesNotMatch( nodeRedLog, /\[error\]/ );
	assert.deepEqual( phpErrors(), [] );
} );
