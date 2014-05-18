<?php

use Gaufrette\Filesystem;
use Gaufrette\Adapter\InMemory;
use Karma\Hydrator;
use Karma\Configuration\InMemoryReader;
use Karma\Finder;
use Karma\ProfileReader;
use Karma\FormatterProviders\NullProvider;
use Karma\FormatterProviders\CallbackProvider;
use Karma\Formatters\Rules;

class HydratorTest extends PHPUnit_Framework_TestCase
{
    private
        $fs,
        $hydrator;
    
    protected function setUp()
    {
        $this->fs = new Filesystem(new InMemory());
        $reader = new InMemoryReader(array(
            'var:dev' => 42,
            'var:preprod' => 51,
            'var:prod' => 69,
            'db.user:dev' => 'root',
            'db.user:preprod' => 'someUser',
            'bool:dev' => true,
            'bool:prod' => false,
            'list:dev' => array('str', 2, true, null),
        ));
        
        $this->hydrator = new Hydrator($this->fs, $reader, new Finder($this->fs), new NullProvider());
        $this->hydrator->setSuffix('-dist');
    }
    
    /**
     * @dataProvider providerTestSimple
     */
    public function testSimple($environment, $expectedBValue, $expectedFValue)
    {
        $this->write('a.php');
        $this->write('b.php-dist', '<%var%>');
        $this->write('c.php', '<%var%>');
        $this->write('d.php-dist', 'var');
        $this->write('e.php-dist', '<%var %>');
        $this->write('f.php-dist', '<%db.user%>');
        
        $this->hydrator->hydrate($environment);
        
        $this->assertTrue($this->fs->has('b.php'));
        $this->assertTrue($this->fs->has('d.php'));
        $this->assertTrue($this->fs->has('e.php'));
        $this->assertTrue($this->fs->has('f.php'));
        
        $this->assertSame($expectedBValue, $this->fs->read('b.php'));
        
        $this->assertSame('<%var%>', $this->fs->read('c.php'));
        $this->assertSame('var', $this->fs->read('d.php'));
        $this->assertSame('<%var %>', $this->fs->read('e.php'));
        $this->assertSame($expectedFValue, $this->fs->read('f.php'));
    }
    
    public function providerTestSimple()
    {
        return array(
            array('dev', '42', 'root'),
            array('preprod', '51', 'someUser'),
        );
    }
    
    public function testDryRun()
    {
        $this->write('a.php');
        $this->write('b.php-dist', '<%var%>');
        $this->write('c.php', '<%var%>');
    
        $this->hydrator
            ->setDryRun()
            ->hydrate('dev');
    
        $this->assertFalse($this->fs->has('b.php'));
    }
    
    public function testTrappedFilenames()
    {
        $existingFiles = array('a.php', 'b.php-dist', 'c.php-dis', 'd.php-distt', 'e.php-dist.dist', 'f.dist', 'g-dist.php', 'h.php-dist-dist');
        
        foreach($existingFiles as $file)
        {
            $this->write($file);
        }
        
        $this->hydrator->hydrate('prod');
        
        $createdFiles = array('b.php', 'h.php-dist');
        $allFiles = array_merge($existingFiles, $createdFiles);

        // check there is no extra generated file
        $this->assertSame(count($allFiles), count($this->fs->keys()));
        
        foreach($allFiles as $file)
        {
            $this->assertTrue($this->fs->has($file), "File $file should be created");
        }
    }
    
    private function write($name, $content = null)
    {
        $this->fs->write($name, $content);
    }

    public function testBackupFiles()
    {
        $this->write('a.php-dist');
        $this->write('b.php-dist', '<%var%>');
        $this->write('b.php', 'oldValue');
        $this->write('c.php-dist');
        
        $this->hydrator
            ->enableBackup()
            ->hydrate('dev');
        
        $this->assertTrue($this->fs->has('a.php'));
        $this->assertFalse($this->fs->has('a.php~'));
        
        $this->assertTrue($this->fs->has('b.php'));
        $this->assertTrue($this->fs->has('b.php~'));
        
        $this->assertTrue($this->fs->has('c.php'));
        $this->assertFalse($this->fs->has('c.php~'));
        
        $this->assertSame('42', $this->fs->read('b.php'));
        $this->assertSame('oldValue', $this->fs->read('b.php~'));
        
        $this->hydrator->hydrate('dev');
        
        $this->assertTrue($this->fs->has('a.php~'));
        $this->assertTrue($this->fs->has('b.php~'));
        $this->assertTrue($this->fs->has('c.php~'));
        
        $this->assertSame('42', $this->fs->read('b.php~'));
    }
    
    public function testFormatter()
    {
        $yellFormatter = new Rules(array(
        	'<true>' => 'TRUE',
        	'<false>' => 'FALSE',
        ));
        
        $otherFormatter = new Rules(array(
        	'<true>' => 'string_true',
        	'<false>' => 0,
        ));
        
        $provider = new CallbackProvider(function ($index) use($yellFormatter, $otherFormatter) {
        	return strtolower($index) === 'yell' ? $yellFormatter : $otherFormatter;
        });
        
        $this->hydrator->setFormatterProvider($provider);
        
        $this->write('a-dist', '<%bool%>');
        $this->write('b-dist', "<% karma:formatter = yell %>\n<%bool%>");
        $this->write('list-dist', "<%list%>\n<%karma:formatter=YeLl     %>   \n");
        
        $this->hydrator->hydrate('dev');
        $this->assertSame('string_true', $this->fs->read('a'));
        $this->assertSame('TRUE', $this->fs->read('b'));
        $this->assertSame(implode("\n", array("str", 2, "TRUE", null)). "\n", $this->fs->read('list'));
        
        $this->hydrator->hydrate('prod');
        $this->assertSame('0', $this->fs->read('a'));
        $this->assertSame('FALSE', $this->fs->read('b'));
    }
    
    /**
     * @expectedException \RuntimeException
     */
    public function testFormatterError()
    {
        $this->write('a-dist', <<< FILE
<% karma:formatter = a %>
<% karma:formatter = b %>
FILE
        );
        
        $this->hydrator->hydrate('dev');
    }
    
    /**
     * @dataProvider providerTestList
     */
    public function testList($env, $expected)
    {
        $this->fs = new Filesystem(new InMemory());
        $reader = new InMemoryReader(array(
            'var:dev' => array(42, 51, 69, 'some string'),
            'var:staging' => array(33),
            'var:prod' => 1337,
        ));
        
        $this->hydrator = new Hydrator($this->fs, $reader, new Finder($this->fs));
        
        $this->write('a.yml-dist', <<< YAML
array:
  - <%var%>
YAML
        );
        
        $this->hydrator->hydrate($env);
        $this->assertSame($expected, $this->fs->read('a.yml'));
    }
    
    public function providerTestList()
    {
        return array(
            array('dev', <<< YAML
array:
  - 42
  - 51
  - 69
  - some string
YAML
          ),            
            array('staging', <<< YAML
array:
  - 33
YAML
          ),            
            array('prod', <<< YAML
array:
  - 1337
YAML
          ),            
        );
    }
    
    public function testListMultiFormat()
    {
        $this->fs = new Filesystem(new InMemory());
        $reader = new InMemoryReader(array(
            'var:dev' => array(42, 51, 69),
        ));
    
        $this->hydrator = new Hydrator($this->fs, $reader, new Finder($this->fs));
    
        $this->write('a.php-dist', <<< PHP
\$var = array(
    <%var%>,
);
PHP
        );
        $expectedPhp = <<< PHP
\$var = array(
    42,
    51,
    69,
);
PHP;
        
        $this->write('b.ini-dist', <<< INI
[group]
list[]=<%var%>
INI
        );
    
        $expectedIni = <<< INI
[group]
list[]=42
list[]=51
list[]=69
INI;
    
        $this->hydrator->hydrate('dev');
        $this->assertSame($expectedPhp, $this->fs->read('a.php'));
        $this->assertSame($expectedIni, $this->fs->read('b.ini'));
    }
    
    public function testListEdgeCases()
    {
        $this->fs = new Filesystem(new InMemory());
        $reader = new InMemoryReader(array(
            'var:dev' => array(42, 51),
            'foo:dev' => 33,
            'bar:dev' => array(1337, 1001),
        ));
        
        $this->hydrator = new Hydrator($this->fs, $reader, new Finder($this->fs));
        
        $this->write('a.txt-dist', <<< TXT
foo = <%var%> <%foo%>
bar[<%bar%>] = <%var%>
baz = <%bar%> <%bar%> <%bar%>
TXT
        );
        
        $expected = <<< TXT
foo = 42 33
foo = 51 33
bar[1337] = 42
bar[1337] = 51
bar[1001] = 42
bar[1001] = 51
baz = 1337 1337 1337
baz = 1337 1337 1001
baz = 1337 1001 1337
baz = 1337 1001 1001
baz = 1001 1337 1337
baz = 1001 1337 1001
baz = 1001 1001 1337
baz = 1001 1001 1001
TXT;
        
        $this->hydrator->hydrate('dev');
        $this->assertSame($expected, $this->fs->read('a.txt'));        
    }
    
    /**
     * @dataProvider providerTestListEndOfLine
     */
    public function testListEndOfLine($content, $expected)
    {
        $this->fs = new Filesystem(new InMemory());
        $reader = new InMemoryReader(array(
            'var:dev' => array(42, 51),
        ));
        
        $this->hydrator = new Hydrator($this->fs, $reader, new Finder($this->fs));
        
        $this->write('a.txt-dist', $content);
        
        $this->hydrator->hydrate('dev');
        $this->assertSame($expected, $this->fs->read('a.txt'));        
    }
    
    public function providerTestListEndOfLine()
    {
        return array(
            "unix"    => array("line:\n - var=<%var%>\nend", "line:\n - var=42\n - var=51\nend"),    
            "windows" => array("line:\r\n - var=<%var%>\r\nend", "line:\r\n - var=42\r\n - var=51\r\nend"),    
            "mac" => array("line:\r - var=<%var%>\rend", "line:\r - var=42\r - var=51\rend"),
        );    
    }
}