<?php

namespace phpMyFAQ\Search;

use phpMyFAQ\Configuration;
use phpMyFAQ\Database\Sqlite3;
use phpMyFAQ\Strings;
use PHPUnit\Framework\TestCase;

/**
 * Class SearchFactoryTest
 */
class SearchFactoryTest extends TestCase
{
    /** @var Configuration */
    private $configuration;

    /**
     * Prepares the environment before running a test.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Strings::init('en');

        $dbHandle = new Sqlite3();
        $this->configuration = new Configuration($dbHandle);
    }

    /**
     * Cleans up the environment after running a test.
     */
    protected function tearDown(): void
    {
        $this->PMF_Search_Database = null;
        parent::tearDown();
    }

    public function testCreate()
    {
        $search = SearchFactory::create($this->configuration, array('database' => 'sqlite3'));

        //$this->assertEquals(new Sqlite3($this->configuration), $search);
        $this->assertInstanceOf('phpMyFAQ\Search\Database\Sqlite3', $search);
    }

}
