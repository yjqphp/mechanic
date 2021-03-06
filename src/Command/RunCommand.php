<?php
/**
 * slince mechanic library
 * @author Tao <taosikai@yeah.net>
 */
namespace Slince\Mechanic\Command;

use Slince\Example\AppMechanic;
use Slince\Mechanic\Mechanic;
use Slince\Mechanic\EventStore;
use Slince\Mechanic\TestSuite;
use Slince\Event\Event;
use Slince\Mechanic\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Finder\Finder;

class RunCommand extends Command
{
    const NAME = 'run';

    /**
     * @var Finder
     */
    static $finder;

    /**
     * 资源位置
     * @var string
     */
    protected $src;

    /**
     * @var Mechanic
     */
    protected $mechanic;

    /**
     * @var ProgressBar[]
     */
    protected $progressBars = [];

    function configure()
    {
        $this->setName(static::NAME);
        $this->addArgument('src', InputArgument::OPTIONAL, __('Test project location'), getcwd());
        $this->addOption('suite', 's', InputOption::VALUE_IS_ARRAY | InputOption::VALUE_OPTIONAL, __('Test suite you want execute, default all'));
        $this->setDescription(__("Execute test project"));
    }

    function execute(InputInterface $input, OutputInterface $output)
    {
        $this->input = $input;
        $this->output = $output;
        $this->src = $input->getArgument('src');
        $testSuiteNames = $input->getOption('suite');
        $bootFile = "{$this->src}/src/AppMechanic.php";
        if (!file_exists($bootFile)) {
            throw new InvalidArgumentException(__("You should create \"AppMechanic.php\" at [{0}]", $this->src . '/src'));
        }
        include $bootFile;
        $mechanic = new AppMechanic();
        $mechanic->setCommand($this);
        $testSuites = $this->createTestSuites($mechanic);
        $testSuites = empty($testSuites) ? [$this->createDefaultTestSuite($mechanic)] : $testSuites;
        $mechanic->setTestSuites($testSuites);
        $this->bindEventsForUi($mechanic, $output);
        $mechanic->run($testSuiteNames);
        return intval(!$mechanic->getReport()->getTestResult());
    }

    /**
     * 绑定事件
     * @param Mechanic $mechanic
     * @param OutputInterface $output
     */
    protected function bindEventsForUi(Mechanic $mechanic, OutputInterface $output)
    {
        $dispatcher = $mechanic->getDispatcher();
        $dispatcher->bind(EventStore::MECHANIC_RUN, function(Event $event) use ($output){
            $testSuites = $event->getArgument('testSuites');
            $total = count($testSuites);
            $output->writeln(__("Mechanic will be performed {0} test suites, please wait a moment", $total));
            $output->write(PHP_EOL);
        });
        //执行测试套件
        $dispatcher->bind(EventStore::TEST_SUITE_EXECUTE, function(Event $event) use($output){
            $testSuite = $event->getArgument('testSuite');
            $output->writeln(__("Processing test suite \"{0}\"", $testSuite->getName()));
            $this->progressBars[$testSuite->getName()] = new ProgressBar($output, count($testSuite->getTestCases()));
            $this->progressBars[$testSuite->getName()]->start();
        });
        //测试套件执行完毕
        $dispatcher->bind(EventStore::TEST_SUITE_EXECUTED, function(Event $event) use($output){
            $testSuite = $event->getArgument('testSuite');
            $this->progressBars[$testSuite->getName()]->finish();
            $output->writeln(PHP_EOL);
        });
        //执行测试用例
        $dispatcher->bind(EventStore::TEST_CASE_EXECUTE, function(Event $event) use($output){
            $testCase = $event->getArgument('testCase');
            $this->progressBars[$testCase->getTestSuite()->getName()]->advance(1);
        });
        //测试用例执行完毕
        $dispatcher->bind(EventStore::TEST_SUITE_EXECUTED, function(Event $event) use($output){
            $testCase = $event->getArgument('testCase');
        });
        $dispatcher->bind(EventStore::MECHANIC_FINISH, function() use ($output){
            $output->writeln(__("Mechanic stop."));
        });
    }

    /**
     * 创建默认的测试套件
     * @param Mechanic $mechanic
     * @return TestSuite
     */
    function createDefaultTestSuite(Mechanic $mechanic)
    {
        //找出所有的php文件
        $files = static::getFinder()->files()->name('*.php')->in("{$mechanic->getLibPath()}/TestCase");
        $testCases = [];
        foreach ($files as $file) {
            $testCaseClass = "{$mechanic->getNamespace()}\\TestCase\\" . $file->getBasename('.php');
            $testCases[] = new $testCaseClass();
        }
        return new TestSuite('default', $testCases);
    }

    /**
     * 创建测试套件
     * @param Mechanic $mechanic
     * @return array
     */
    protected function createTestSuites(Mechanic $mechanic = null)
    {
        //找出所有的php文件
        $files = static::getFinder()->files()->name('*.php')->in("{$mechanic->getLibPath()}/TestSuite");
        $testSuites = [];
        foreach ($files as $file) {
            $testSuiteClass = "{$mechanic->getNamespace()}\\TestSuite\\" . $file->getBasename('.php');
            $testSuite = new $testSuiteClass();
            $testSuites[] = $testSuite;
        }
        return $testSuites;
    }

    /**
     * @return Finder
     */
    public static function getFinder()
    {
        self::$finder = new Finder();
        return self::$finder;
    }
}