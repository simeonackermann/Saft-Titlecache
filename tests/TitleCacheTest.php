<?php

namespace SaftTitleCache\Test;

use SaftTitleCache\TitleCache;

class TitleCacheTest extends \PHPUnit_Framework_TestCase
{
    /*
     * Tests for __construct
     */

    public function testConstructor()
    {
        $this->fixture = new TitleCache();
    }

    /*
     * Tests for run
     */

    public function testRunActionNotSet()
    {
        $fixture = new TitleCache();

        $this->assertEquals(
            array(
                'status' => 'error',
                'data' => null,
                'message' => 'No or empty action paramter given'
            ),
            $fixture->run()
        );
    }
}
