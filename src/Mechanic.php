<?php
/**
 * slince mechanic library
 * @author Tao <taosikai@yeah.net>
 */
namespace Slince\Mechanic;

use Slince\Config\Config;
use Slince\Di\Container;
use Slince\Cache\ArrayCache;
use Slince\Event\Dispatcher;
use Slince\Event\Event;
use Slince\Mechanic\Command\Command;
use Slince\Mechanic\Exception\InvalidArgumentException;
use Slince\Mechanic\Exception\RuntimeException;
use Slince\Mechanic\Report\Report;
use Slince\Mechanic\Report\TestMethodReport;
use Slince\Mechanic\ReportStrategy\ReportStrategy;
use slince\Mechanic\TestCase\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Composer\Autoload\ClassLoader;

class Mechanic
{
    /**
     * @var Container
     */
    protected $container;

    /**
     * @var ArrayCache
     */
    protected $parameters;

    /**
     * @var Dispatcher
     */
    protected $dispatcher;

    /**
     * @var Filesystem
     */
    protected $filesystem;

    /**
     * @var Config
     */
    protected $configs;

    /**
     * @var TestSuite[]
     */
    protected $testSuites;

    /**
     * @var TestSuite[]
     */
    protected $executeTestSuites;

    /**
     * class loader
     * @var ClassLoader
     */
    protected $classLoader;

    /**
     * 测试项目根目录
     * @var string
     */
    protected $rootPath;

    /**
     * 命令空间
     * @var string
     */
    protected $namespace;

    /**
     * @var Report
     */
    protected $report;

    /**
     * @var Command
     */
    protected $command;

    /**
     * @var ReportStrategy[]
     */
    protected $reportStrategies = [];

    /**
     * @var Mechanic
     */
    static $instance;
    
    function __construct(array $testSuites = [])
    {
        $this->container = new Container();
        $this->dispatcher = new Dispatcher();
        $this->parameters = new ArrayCache();
        $this->filesystem = new Filesystem();
        $this->configs = new Config();
        $this->classLoader = new ClassLoader();
        $this->report = new Report();
        $this->testSuites = $testSuites;
        $this->initialize();
        $this->classLoader->register();
        static::$instance = $this;
    }

    function initialize()
    {
    }

    /**
     * @return Container
     */
    public function getContainer()
    {
        return $this->container;
    }

    /**
     * @param TestSuite[] $testSuites
     */
    public function setTestSuites($testSuites)
    {
        $this->testSuites = $testSuites;
    }

    /**
     * @return TestSuite[]
     */
    public function getTestSuites()
    {
        return $this->testSuites;
    }

    /**
     * @return TestSuite[]
     */
    public function getExecuteTestSuites()
    {
        return $this->executeTestSuites;
    }

    /**
     * 获取指定的测试套件
     * @param $name
     * @return null|TestSuite
     */
    function getTestSuite($name)
    {
        foreach ($this->getTestSuites() as $testSuite) {
            if (strcasecmp($testSuite->getName(), $name) == 0) {
                return $testSuite;
            }
        }
        return null;
    }

    /**
     * @return ArrayCache
     */
    public function getParameters()
    {
        return $this->parameters;
    }

    /**
     * 获取自动加载器
     * @return ClassLoader
     */
    public function getClassLoader()
    {
        return $this->classLoader;
    }

    /**
     * @return Config
     */
    public function getConfigs()
    {
        return $this->configs;
    }

    /**
     * @return Dispatcher
     */
    public function getDispatcher()
    {
        return $this->dispatcher;
    }

    /**
     * @return Filesystem
     */
    public function getFilesystem()
    {
        return $this->filesystem;
    }

    /**
     * @return Report
     */
    public function getReport()
    {
        return $this->report;
    }

    /**
     * @return ReportStrategy[]
     */
    public function getReportStrategies()
    {
        return $this->reportStrategies;
    }

    /**
     * 添加报告策略
     * @param ReportStrategy $reportStrategy
     */
    function addReportStrategy(ReportStrategy $reportStrategy)
    {
        $this->reportStrategies[] = $reportStrategy;
    }

    /**
     * 获取根目录
     * @return string
     */
    function getRootPath()
    {
        if (is_null($this->rootPath)) {
            $reflection = new \ReflectionObject($this);
            $this->rootPath = dirname(dirname($reflection->getFileName()));
        }
        return $this->rootPath;
    }

    /**
     * 获取类地址
     * @return string
     */
    function getLibPath()
    {
        return $this->getRootPath() . '/src';
    }

    /**
     * 获取配置文件目录
     * @return string
     */
    function getConfigPath()
    {
        return $this->getRootPath() . '/config';
    }

    /**
     * 获取报告文件目录
     * @return string
     */
    function getReportPath()
    {
        return $this->getRootPath() . '/reports';
    }

    /**
     * 获取资源文件目录
     * @return string
     */
    function getAssetPath()
    {
        return $this->getRootPath() . '/assets';
    }

    /**
     * 获取当前application的命名空间
     * @return string
     */
    function getNamespace()
    {
        if (is_null($this->namespace)) {
            $this->namespace = (new \ReflectionClass($this))->getNamespaceName();
        }
        return $this->namespace;
    }

    /**
     * @param Command $command
     */
    public function setCommand($command)
    {
        $this->command = $command;
    }

    /**
     * @return Command
     */
    public function getCommand()
    {
        return $this->command;
    }

    /**
     * 执行的测试套件名称
     * @param array $testSuiteNames
     */
    function run(array $testSuiteNames = [])
    {
        //先执行测试套件的套件方法
        $this->preHandleTestSuites();
        //检查需要执行的套件名称
        $this->checkTestSuiteNames($testSuiteNames);
        $testSuites = $this->getWaitingExecuteTestSuites($testSuiteNames);
        $this->dispatcher->dispatch(EventStore::MECHANIC_RUN, new Event(EventStore::MECHANIC_RUN, $this, [
            'testSuites' => $testSuites
        ]));
        foreach ($testSuites as $testSuite) {
            $this->runTestSuite($testSuite);
            $this->getReport()->addTestSuiteReports($testSuite->getTestSuiteReport());
        }
        $this->executeTestSuites = $testSuites;
        $this->dispatcher->dispatch(EventStore::MECHANIC_FINISH, new Event(EventStore::MECHANIC_FINISH, $this));
        $this->executeReportStrategies();
    }

    /**
     * 预处理测试套件
     */
    protected function preHandleTestSuites()
    {
        foreach ($this->getTestSuites() as $testSuite) {
            $testSuite->suite();
        }
    }

    /**
     * 检查需要执行的测试套件名
     * @param array $testSuiteNames
     * @return bool
     */
    protected function checkTestSuiteNames(array $testSuiteNames)
    {
        if (!empty($testSuiteNames)) {
            $notExistsTestSuiteNames = array_filter($testSuiteNames, function($testSuiteName){
                foreach ($this->testSuites as $testSuite) {
                    if (strcasecmp($testSuite->getName(), $testSuiteName) == 0) {
                        return false;
                    }
                }
                return true;
            });
            if (!empty($notExistsTestSuiteNames)){
                throw new InvalidArgumentException(__("Test suite [{0}] does not exists",
                    implode(',', $notExistsTestSuiteNames)));
            }
        }
        return true;
    }

    /**
     * 获取需要执行的测试套件
     * @param array $testSuiteNames
     * @return array|TestSuite[]
     */
    protected function getWaitingExecuteTestSuites(array $testSuiteNames)
    {
        if (empty($testSuiteNames)) {
            return $this->testSuites;
        }
        return array_filter($this->testSuites, function(TestSuite $testSuite) use ($testSuiteNames){
            return in_array($testSuite->getName(), $testSuiteNames);
        });
    }

    /**
     * 执行测试套件
     * @param TestSuite $testSuite
     */
    protected function runTestSuite(TestSuite $testSuite)
    {
        //给TestSuite传参
        $testSuite->setMechanic($this);
        $this->dispatcher->dispatch(EventStore::TEST_SUITE_EXECUTE, new Event(EventStore::TEST_SUITE_EXECUTE, $this, [
            'testSuite' => $testSuite
        ]));
        foreach ($testSuite->getTestCases() as $testCase) {
            $this->runTestCase($testCase);
            $testSuite->getTestSuiteReport()->addTestCaseReport($testCase->getTestCaseReport());
        }
        $this->dispatcher->dispatch(EventStore::TEST_SUITE_EXECUTED, new Event(EventStore::TEST_SUITE_EXECUTED, $this, [
            'testSuite' => $testSuite
        ]));
    }

    /**
     * 执行测试用例
     * @param TestCase $testCase
     */
    protected function runTestCase(TestCase $testCase)
    {
        //给测试用例传递Mechanic
        $testCase->setMechanic($this);
        $testMethods = $this->getTestCaseTestMethods($testCase);
        $this->dispatcher->dispatch(EventStore::TEST_CASE_EXECUTE, new Event(EventStore::TEST_CASE_EXECUTE, $this, [
            'testCase' => $testCase,
            'testMethods' => $testMethods
        ]));
        foreach ($testMethods as $testMethod) {
            try {
                //执行用例方法，如果方法没有明确返回false，则用例方法算执行成功
                $result = $testMethod->invoke($testCase);
                $result = ($result !== false);
                $message = '';
            } catch (\Exception $e) {
                $result = false;
                $message = $e->getMessage();
            }
            //记录用例方法的测试报告到用例报告
            $testCase->getTestCaseReport()->addTestMethodReport(TestMethodReport::create($testMethod, $result, [$message]));
        }
        $this->dispatcher->dispatch(EventStore::TEST_CASE_EXECUTED, new Event(EventStore::TEST_CASE_EXECUTED, $this, [
            'testCase' => $testCase
        ]));
    }

    /**
     * @param TestCase $testCase
     * @return \ReflectionMethod[]
     */
    protected function getTestCaseTestMethods(TestCase $testCase)
    {
        $reflection = new \ReflectionObject($testCase);
        $publicMethods = $reflection->getMethods(\ReflectionMethod::IS_PUBLIC);
        $testMethods = [];
        foreach ($publicMethods as $method) {
            if (strcasecmp(substr($method->getName(), 0, 4), 'test') == 0) {
                $testMethods[] = $method;
            }
        }
        return $testMethods;
    }

    /**
     * 替换参数里的变量
     * @param $value
     * @return mixed
     */
    function processValue($value)
    {
        if (is_string($value)) {
            return preg_replace_callback('#\{([a-zA-Z0-9_,]*)\}#i', function ($matches) {
                if (!isset($this->parameters[$matches[1]])) {
                    throw new RuntimeException(__("The variable [{0}] does not exists", $matches[1]));
                }
                return $this->parameters[$matches[1]];
            }, $value);
        } elseif (is_array($value)) {
            return call_user_func_array([$this, 'processValue'], $value);
        } else {
            return $value;
        }
    }

    /**
     * 执行所有的报告策略
     */
    protected function executeReportStrategies()
    {
        foreach ($this->reportStrategies as $reportStrategy) {
            $reportStrategy->execute();
        }
    }

    /**
     * @return Mechanic
     */
    static function instance()
    {
        return static::$instance;
    }
}