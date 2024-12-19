<?php

namespace deemru;

class UnitsBridge
{
    private $dapp;
    private $contract;
    private $logger;

    public function __construct( $dapp, $contract, $logger = null )
    {
        $this->dapp = $dapp;
        $this->contract = $contract;
        $this->logger = $logger ?? new WavesKit;
    }

    static public function MAINNET()
    {
        return new UnitsBridge( '3PKgN8rfmvF7hK7RWJbpvkh59e1pQkUzero', '0x0000000000000000000000000000000000006a7e' );
    }

    static public function TESTNET()
    {
        return new UnitsBridge( '3Msx4Aq69zWUKy4d1wyKnQ4ofzEDAfv5Ngf', '0x0000000000000000000000000000000000006a7e' );
    }

    private function rc( $function, $retries = 100, $sleep = 10 )
    {
        for( $i = 0; $i < $retries; ++$i )
        {
            if( $i !== 0 )
            {
                usleep( 1000 );
                $this->logger->log( 'w', 'remote call failed, retry in ' . $sleep . ' seconds (' . $i . '/' . $retries . ')' );
                sleep( $sleep );
            }
            $result = ( $function )();
            if( $result !== false )
                return $result;
        }

        exit( $this->logger->log( 'e', 'remote call retries exceeded (' . $i . '/' . $retries . ')' ) );
    }

    public function basicInfo( UnitsKit $uk, WavesKit $wk )
    {
        $this->logger->log( 'UNITS: ' . $uk->getAddress() . ' ~ ' . $uk->stringValue( $this->rc( function() use ( $uk ){ return $uk->getBalance(); } ) ) . ' UNIT0' );
        $this->logger->log( 'WAVES: ' . str_pad( $wk->getAddress(), 42 ) . ' ~ ' . $uk->stringValue( $this->rc( function() use ( $wk ){ return $wk->balance( null, 'WAVES' ); } ), 8 ) . ' WAVES' );
    }

    public function sendNative( UnitsKit $uk, WavesKit $wk, $amount )
    {
        $this->logger->log( 'UNITS: sending ' . $uk->stringValue( $amount ) . ' UNIT0 from ' . $uk->getAddress() . ' to ' . $wk->getAddress() );
        $wavesPublicKeyHash = substr( $wk->getAddress( true ), 2, 20 );
        $sendNativeInput = '0x' . '78338413' . bin2hex( str_pad( $wavesPublicKeyHash, 32, chr( 0 ) ) );
        $gasPrice = $this->rc( function() use ( $uk ){ return $uk->getGasPrice(); } );
        $nonce = $this->rc( function() use ( $uk ){ return $uk->getNonce(); } );

        $tx = $uk->tx( $this->contract, $amount, $gasPrice, $nonce, $sendNativeInput );
        $tx = $this->rc( function() use ( $uk, $tx ){ return $uk->txEstimateGas( $tx ); } );
        $tx = $uk->txSign( $tx );
        $tx = $this->rc( function() use ( $uk, $tx ){ return $uk->txBroadcast( $tx ); } );
        $tx = $uk->ensure( $tx, 10 );
        if( $tx === false )
        {
            $this->logger->log( 'e', 'UNITS: sending failed' );
            return false;
        }
        $this->logger->log( 's', 'UNITS: sending done' );
        return $tx;
    }

    public function waitFinalized( UnitsKit $uk, WavesKit $wk, $txhash )
    {
        $this->logger->log( 'WAVES: waiting finalized block at ' . $this->dapp );
        for( ;; )
        {
            $tx = $this->rc( function() use ( $uk, $txhash ){ return $uk->txByHash( $txhash ); } );
            $finalized = $this->waitFinalizedInternal( $wk, $tx, $this->dapp );
            if( $finalized === false )
            {
                $this->logger->log( 'w', 'WAVES: waiting finalized failed, retry in 10 seconds' );
                sleep( 10 );
                continue;
            }
            break;
        }

        $this->logger->log( 's', 'WAVES: waiting finalized done' );
        return $tx;
    }

    function waitFinalizedInternal( $wk, $tx, $unitDapp )
    {
        $targetHeight = $this->getHeightByBlock( $wk, $tx['receipt']['blockHash'], $unitDapp );
        if( $targetHeight === false )
            return false;

        $lastFinalizedBlock = '';
        for( ;; )
        {
            $finalizedBlock = $wk->getData( 'finalizedBlock', $unitDapp );
            if( $finalizedBlock === false )
                return false;
            if( $lastFinalizedBlock !== $finalizedBlock )
            {
                $lastFinalizedBlock = $finalizedBlock;
                $finalizedHeight = $this->getHeightByBlock( $wk, '0x' . $finalizedBlock, $unitDapp );
                if( $finalizedHeight === false )
                    return false;
            }
            $mainChainId = $wk->getData( 'mainChainId', $unitDapp );
            if( $mainChainId === false )
                $mainChainId = 0;
            $headInfo = $wk->getData( 'chain_' . str_pad( strval( $mainChainId ), 8, '0', STR_PAD_LEFT ), $unitDapp );
            if( $headInfo === false )
                return false;
            $headHeight = intval( explode( ',', $headInfo )[0] );

            $finalzedDiff = $finalizedHeight - $targetHeight;
            $headDiff = $headHeight - $targetHeight;
            $this->logger->log( $finalzedDiff >= 0 ? 's' : 'i', 'UNITS: target = ' . $targetHeight . ', finalized = ' . $finalizedHeight . ' (' . ( $finalzedDiff > 0 ? '+' : '' ) . $finalzedDiff . '), head = ' . $headHeight . ' (' . ( $headDiff > 0 ? '+' : '' ) . $headDiff . ')'  );

            if( $finalzedDiff >= 0 )
                break;
            sleep( 10 );
        }

        return true;
    }

    function getHeightByBlock( $wk, $blockHash, $unitDapp )
    {
        $data = $wk->getData( 'block_' . $blockHash, $unitDapp );
        if( $data === false )
            return false;
        $data = base64_decode( substr( $data, 7 ) );
        return unpack( 'J', $data )[1];
    }

    function withdraw( UnitsKit $uk, WavesKit $wk, $tx )
    {
        $this->logger->log( 'WAVES: withdraw' );
        [ $proofs, $index ] = $uk->getBridgeProofs( $tx );
        if( $proofs === false || $index === false )
        {
            $this->logger->log( 'e', 'WAVES: withdraw getBridgeProofs() failed' );
            return false;
        }

        $wavesProofs = [];
        foreach( $proofs as $proof )
            $wavesProofs[] = [ $proof ];

        $blockHash = substr( $tx['receipt']['blockHash'], 2 );
        $wavesProofs = [ 'list' => $wavesProofs ];
        $amount = gmp_intval( gmp_div( gmp_init( $tx['value'], 16 ), 10000000000 ) );

        $tx = $wk->txInvokeScript( $this->dapp, 'withdraw', [ $blockHash, $wavesProofs, $index, $amount ] );
        $stx = $wk->txSign( $tx );
        $etx = $wk->ensure( $wk->txBroadcast( $stx ) );
        if( $etx === false )
        {
            $this->logger->log( 'e', 'WAVES: withdraw failed' );
            return false;
        }

        $this->logger->log( 's', 'WAVES: withdraw done (' . $uk->stringValue( $etx['call']['args'][3]['value'], 8 ) . ' UNIT0)' );
        return $etx;
    }
}
