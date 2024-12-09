<?php

class tester
{
    private $successful = 0;
    public $failed = 0;
    private $depth = 0;
    private $info = [];
    private $start = [];
    private $init;

    public function pretest( $info )
    {
        $this->info[$this->depth] = $info;
        $this->start[$this->depth] = microtime( true );
        if( !isset( $this->init ) )
            $this->init = $this->start[$this->depth];
        $this->depth++;
    }

    private function ms( $start )
    {
        $ms = ( microtime( true ) - $start ) * 1000;
        $ms = $ms > 100 ? round( $ms ) : $ms;
        $ms = sprintf( $ms > 10 ? ( $ms > 100 ? '%.00f' : '%.01f' ) : '%.02f', $ms );
        return $ms;
    }

    public function test( $cond )
    {
        global $uk;
        $this->depth--;
        $ms = $this->ms( $this->start[$this->depth] );
        $uk->log( $cond ? 's' : 'e', "{$this->info[$this->depth]} ($ms ms)" );
        $cond ? $this->successful++ : $this->failed++;
        return $cond;
    }

    public function finish()
    {
        $total = $this->successful + $this->failed;
        $ms = $this->ms( $this->init );
        echo "  TOTAL: {$this->successful}/$total ($ms ms)\n";
        sleep( 3 );

        if( $this->failed > 0 )
            exit( 1 );
    }
}
