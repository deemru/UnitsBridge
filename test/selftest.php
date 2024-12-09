<?php

require_once __DIR__ . '/../vendor/autoload.php';

use deemru\UnitsBridge;
use deemru\UnitsKit;
use deemru\WavesKit;

require_once 'tester.php';
echo '   TEST: UnitsBridge @ PHP ' . PHP_VERSION . PHP_EOL;
$t = new tester();

if( file_exists( __DIR__ . '/private.php' ) )
    require_once __DIR__ . '/private.php';

$unitsPrivateKey = getenv( 'UNITSBRIDGE_UNITS_PRIVATEKEY' );
$wavesPrivateKey = getenv( 'UNITSBRIDGE_WAVES_PRIVATEKEY' );
$network = getenv( 'UNITSBRIDGE_NETWORK' );
$amount = UnitsKit::hexValue( getenv( 'UNITSBRIDGE_AMOUNT' ) );
$txhash = getenv( 'UNITSBRIDGE_TXHASH' );

$t->pretest( 'PHASE 0 (BASIC INFO)' );
{
    if( $network === 'MAINNET' )
    {
        $bridge = UnitsBridge::MAINNET();
        $uk = UnitsKit::MAINNET();
        $wk = new WavesKit( 'W' );
    }
    else
    if( $network === 'TESTNET' )
    {
        $bridge = UnitsBridge::TESTNET();
        $uk = UnitsKit::TESTNET();
        $wk = new WavesKit( 'T' );
    }
    else
    {
        $t->test( false );
        $t->finish();
    }

    $uk->setPrivateKey( $unitsPrivateKey );
    $wk->setPrivateKey( $wavesPrivateKey );
    $bridge->basicInfo( $uk, $wk );
}
$t->test( true );

$t->pretest( 'PHASE 1 (UNITS BRIDGE SEND NATIVE)' );
if( $txhash === false )
{
    $tx = $bridge->sendNative( $uk, $wk, $amount );
    if( $tx !== false )
        $txhash = $tx['hash'];
}
$t->test( $txhash !== false );

$t->pretest( 'PHASE 2 (WAIT FINALIZED)' );
{
    $tx = $bridge->waitFinalized( $uk, $wk, $txhash );
}
$t->test( $tx !== false );

$t->pretest( 'PHASE 3 (WAVES BRIDGE WITHDRAW)' );
{
    $tx = $bridge->withdraw( $uk, $wk, $tx );
}
$t->test( $tx !== false );

$t->finish();
