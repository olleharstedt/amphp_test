<?php

require __DIR__ . '/../vendor/autoload.php';

class Test
{
    public function test()
    {
        $lazy = new Amp\LazyPromise(static function() {});
        var_dump($lazy);
    }
}

$t = new Test;
$t->test();
