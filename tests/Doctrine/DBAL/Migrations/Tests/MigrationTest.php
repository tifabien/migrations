<?php
/*
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * This software consists of voluntary contributions made by many individuals
 * and is licensed under the LGPL. For more information, see
 * <http://www.doctrine-project.org>.
*/

namespace Doctrine\DBAL\Migrations\Tests;

use Doctrine\DBAL\Migrations\Configuration\Configuration;
use Doctrine\DBAL\Migrations\Migration;
use Doctrine\DBAL\Migrations\MigrationException;
use Doctrine\DBAL\Migrations\OutputWriter;
use \Mockery as m;
use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamFile;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class MigrationTest extends MigrationTestCase
{
    /** @var Configuration */
    private $config;
    
    protected $output;

    protected function setUp()
    {
        $this->config = new Configuration($this->getSqliteConnection());
        $this->config->setMigrationsDirectory(__DIR__ . DIRECTORY_SEPARATOR . 'Stub/migration-empty-folder');
        $this->config->setMigrationsNamespace('DoctrineMigrations\\');
    }

    public function testMigrateToUnknownVersionThrowsException()
    {
        $migration = new Migration($this->config);

        $this->setExpectedException(
            MigrationException::class,
            'Could not find migration version 1234'
        );
        $migration->migrate('1234');
    }

    /**
     * @expectedException \Doctrine\DBAL\Migrations\MigrationException
     * @expectedExceptionMessage Could not find any migrations to execute.
     */
    public function testMigrateWithNoMigrationsThrowsException()
    {
        $migration = new Migration($this->config);

        $migration->migrate();
    }

    public function testMigrateWithNoMigrationsDontThrowsExceptionIfContiniousIntegrationOption()
    {
        $migration = new Migration($this->config);

        $migration->setNoMigrationException(true);
        $migration->migrate();
    }

    /**
     * @param $to
     *
     * @dataProvider getSqlProvider
     */
    public function testGetSql($to)
    {
        $migrationMock = m::mock(Migration::class);
        $migrationMock->makePartial();
        $expected = 'something';
        $migrationMock->shouldReceive('migrate')->with($to, true)->andReturn($expected);
        $result = $migrationMock->getSql($to);
        $this->assertEquals($expected, $result);
    }

    /**
     * Data provider for ::testGetSql()
     * @return array
     */
    public function getSqlProvider()
    {
        return [
            [null],
            ['test'],
        ];
    }

    /**
     * @param $path
     * @param $to
     * @param $getSqlReturn
     *
     * @dataProvider writeSqlFileProvider
     */
    public function testWriteSqlFile($path, $from, $to, $getSqlReturn)
    {
        $expectedReturn = 123;
        $sqlWriter = m::instanceMock('overload:Doctrine\DBAL\Migrations\SqlFileWriter');
        $sqlWriter->shouldReceive('write')->with(m::type('array'), m::anyOf('up', 'down'))->andReturn($expectedReturn);

        $outputWriter = m::mock(OutputWriter::class);
        $outputWriter->shouldReceive('write');

        $config = m::mock(Configuration::class)
            ->makePartial();
        $config->shouldReceive('getCurrentVersion')->andReturn($from);
        $config->shouldReceive('getOutputWriter')->andReturn($outputWriter);
        if ($to == null) { // this will always just test the "up" direction
            $config->shouldReceive('getLatestVersion')->andReturn($from + 1);
        }

        $migration = m::mock('Doctrine\DBAL\Migrations\Migration[getSql]', [$config])->makePartial();
        $migration->shouldReceive('getSql')->with($to)->andReturn($getSqlReturn);

        $result = $migration->writeSqlFile($path, $to);
        $this->assertEquals($expectedReturn, $result);
    }

    public function writeSqlFileProvider()
    {
        return [
            [__DIR__, 0, 1, ['1' => ['SHOW DATABASES;']]], // up
            [__DIR__, 0, null, ['1' => ['SHOW DATABASES;']]], // up
            [__DIR__, 1, 1, ['1' => ['SHOW DATABASES;']]], // up (same)
            [__DIR__, 1, 0, ['1' => ['SHOW DATABASES;']]], // down
            [__DIR__ . '/tmpfile.sql', 0, 1, ['1' => ['SHOW DATABASES']]], // tests something actually got written
        ];
    }

    public function testWriteSqlFileShouldUseStandardCommentMarkerInSql()
    {
        $config = m::mock(Configuration::class)->makePartial();
        $config->shouldReceive('getCurrentVersion')->andReturn(0);
        $config->shouldReceive('getOutputWriter')->andReturn($this->getOutputWriter());
        $migration = m::mock('Doctrine\DBAL\Migrations\Migration[getSql]', [$config])->makePartial();
        $migration->shouldReceive('getSql')->andReturn(['1' => ['SHOW DATABASES']]);


        $sqlFilesDir = vfsStream::setup('sql_files_dir');
        $migration->writeSqlFile(vfsStream::url('sql_files_dir'), 1);

        $this->assertRegExp('/^\s*-- Migrating from 0 to 1/m', $this->getOutputStreamContent($this->output));

        /** @var vfsStreamFile $sqlMigrationFile */
        $sqlMigrationFile = current($sqlFilesDir->getChildren());
        $this->assertInstanceOf(vfsStreamFile::class, $sqlMigrationFile);
        $this->assertRegExp('/^\s*-- Doctrine Migration File Generated on/m', $sqlMigrationFile->getContent());
        $this->assertRegExp('/^\s*-- Version 1/m', $sqlMigrationFile->getContent());
        $this->assertNotRegExp('/^\s*#/m', $sqlMigrationFile->getContent());
    }
}
