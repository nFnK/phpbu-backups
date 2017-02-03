<?php
/**
 * phpbu
 *
 * Copyright (c) 2014 - 2017 Sebastian Feldmann <sebastian@phpbu.de>
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 *
 * @package    phpbu
 * @author     Sebastian Feldmann <sebastian@phpbu.de>
 * @copyright  Sebastian Feldmann
 * @license    https://opensource.org/licenses/MIT The MIT License (MIT)
 * @link       http://phpbu.de/
 * @since      Class available since Release 1.0.0
 */
namespace phpbu\App;

use Phar;
use phpbu\App\Cmd\Args;
use phpbu\App\Util\Arr;

/**
 * Main application class.
 *
 * @package    phpbu
 * @author     Sebastian Feldmann <sebastian@phpbu.de>
 * @copyright  Sebastian Feldmann <sebastian@phpbu.de>
 * @license    https://opensource.org/licenses/MIT The MIT License (MIT)
 * @link       http://phpbu.de/
 * @since      Class available since Release 1.0.0
 */
class Cmd
{
    const EXIT_SUCCESS   = 0;
    const EXIT_FAILURE   = 1;
    const EXIT_EXCEPTION = 2;

    /**
     * Ascii-Art app logo
     *
     * @var string
     */
    private static $logo = '             __          __
      ____  / /_  ____  / /_  __  __
     / __ \/ __ \/ __ \/ __ \/ / / /
    / /_/ / / / / /_/ / /_/ / /_/ /
   / .___/_/ /_/ .___/_.___/\__,_/
  /_/         /_/
';

    /**
     * Is cmd executed from phar.
     *
     * @var boolean
     */
    private $isPhar;

    /**
     * Is version string printed already.
     *
     * @var boolean
     */
    private $isVersionStringPrinted = false;

    /**
     * List of given arguments
     *
     * @var array
     */
    private $arguments;

    /**
     * Runs the application.
     *
     * @param array $args
     */
    public function run(array $args)
    {
        $this->isPhar = defined('__PHPBU_PHAR__');
        $this->handleOpt($args);
        $this->findConfiguration();

        $ret     = self::EXIT_FAILURE;
        $factory = new Factory();
        $runner  = new Runner($factory);

        try {
            $this->printVersionString();
            $result = $runner->run($this->createConfiguration($factory));

            if ($result->wasSuccessful()) {
                $ret = self::EXIT_SUCCESS;
            } elseif ($result->errorCount() > 0) {
                $ret = self::EXIT_EXCEPTION;
            }
        } catch (\Exception $e) {
            echo $e->getMessage() . PHP_EOL;
            $ret = self::EXIT_EXCEPTION;
        }

        exit($ret);
    }

    /**
     * Check arguments and load configuration file.
     *
     * @param array $args
     */
    protected function handleOpt(array $args)
    {
        try {
            $parser  = new Args($this->isPhar);
            $options = $parser->getOptions($args);
            $this->handleArgs($options);
        } catch (Exception $e) {
            $this->printError($e->getMessage(), true);
        }
    }

    /**
     * Handle the parsed command line options
     *
     * @param  array $options
     * @return void
     */
    protected function handleArgs(array $options)
    {
        foreach ($options as $option => $argument) {
            switch ($option) {
                case '--bootstrap':
                    $this->arguments['bootstrap'] = $argument;
                    break;
                case '--colors':
                    $this->arguments['colors'] = $argument;
                    break;
                case '--configuration':
                    $this->arguments['configuration'] = $argument;
                    break;
                case '--debug':
                    $this->arguments['debug'] = $argument;
                    break;
                case '-h':
                case '--help':
                    $this->printHelp();
                    exit(self::EXIT_SUCCESS);
                case 'include-path':
                    $this->arguments['include-path'] = $argument;
                    break;
                case '--limit':
                    $this->arguments['limit'] = $argument;
                    break;
                case '--self-upgrade':
                    $this->handleSelfUpgrade();
                    break;
                case '--version-check':
                    $this->handleVersionCheck();
                    break;
                case '--simulate':
                    $this->arguments['simulate'] = $argument;
                    break;
                case '-v':
                case '--verbose':
                    $this->arguments['verbose'] = true;
                    break;
                case '-V':
                case '--version':
                    $this->printVersionString();
                    exit(self::EXIT_SUCCESS);
            }
        }
    }

    /**
     * Try to find the configuration file.
     */
    protected function findConfiguration()
    {
        // check configuration argument
        // if configuration argument is a directory
        // check for default configuration files 'phpbu.xml' and 'phpbu.xml.dist'
        if (isset($this->arguments['configuration']) && is_file($this->arguments['configuration'])) {
            $this->arguments['configuration'] = realpath($this->arguments['configuration']);
        } elseif (isset($this->arguments['configuration']) && is_dir($this->arguments['configuration'])) {
            $this->findConfigurationInDir();
        } elseif (!isset($this->arguments['configuration'])) {
            // no configuration argument search for default configuration files
            // 'phpbu.xml' and 'phpbu.xml.dist' in current working directory
            $this->findConfigurationDefault();
        }
        // no config found, exit with some help output
        if (!isset($this->arguments['configuration'])) {
            $this->printLogo();
            $this->printHelp();
            exit(self::EXIT_EXCEPTION);
        }
    }

    /**
     * Check directory for default configuration files phpbu.xml, phpbu.xml.dist.
     *
     * @return void
     */
    protected function findConfigurationInDir()
    {
        $configurationFile = $this->arguments['configuration'] . '/phpbu.xml';

        if (file_exists($configurationFile)) {
            $this->arguments['configuration'] = realpath($configurationFile);
        } elseif (file_exists($configurationFile . '.dist')) {
            $this->arguments['configuration'] = realpath($configurationFile . '.dist');
        }
    }

    /**
     * Check default configuration files phpbu.xml, phpbu.xml.dist in current working directory.
     *
     * @return void
     */
    protected function findConfigurationDefault()
    {
        if (file_exists('phpbu.xml')) {
            $this->arguments['configuration'] = realpath('phpbu.xml');
        } elseif (file_exists('phpbu.xml.dist')) {
            $this->arguments['configuration'] = realpath('phpbu.xml.dist');
        }
    }

    /**
     * Create a application configuration.
     *
     * @param  \phpbu\App\Factory $factory
     * @return \phpbu\App\Configuration
     */
    protected function createConfiguration(Factory $factory)
    {
        $configLoader  = Configuration\Loader\Factory::createLoader($this->arguments['configuration']);
        $configuration = $configLoader->getConfiguration($factory);

        // command line arguments overrule the config file settings
        $this->overrideConfigWithArgument($configuration, 'verbose');
        $this->overrideConfigWithArgument($configuration, 'colors');
        $this->overrideConfigWithArgument($configuration, 'debug');
        $this->overrideConfigWithArgument($configuration, 'simulate');
        $this->overrideConfigWithArgument($configuration, 'bootstrap');

        // check for command line limit option
        $limitOption = Arr::getValue($this->arguments, 'limit');
        $configuration->setLimit(!empty($limitOption) ? explode(',', $limitOption) : []);

        // add a cli printer for some output
        $configuration->addLogger(
            new Result\PrinterCli(
                $configuration->getVerbose(),
                $configuration->getColors(),
                ($configuration->getDebug() || $configuration->isSimulation())
            )
        );
        return $configuration;
    }

    /**
     * Override configuration settings with command line arguments.
     *
     * @param \phpbu\App\Configuration $configuration
     * @param string                   $arg
     */
    protected function overrideConfigWithArgument(Configuration $configuration, string $arg)
    {
        $value = Arr::getValue($this->arguments, $arg);
        if (!empty($value)) {
            $setter = 'set' . ucfirst($arg);
            $configuration->{$setter}($value);
        }
    }

    /**
     * Handle the phar self-update.
     */
    protected function handleSelfUpgrade()
    {
        $this->printVersionString();

        // check if upgrade is necessary
        if (!$this->isPharOutdated($this->getLatestVersion())) {
            echo 'You already have the latest version of phpbu installed.' . PHP_EOL;
            exit(self::EXIT_SUCCESS);
        }

        $remoteFilename = 'http://phar.phpbu.de/phpbu.phar';
        $localFilename  = realpath($_SERVER['argv'][0]);
        $tempFilename   = basename($localFilename, '.phar') . '-temp.phar';

        echo 'Updating the phpbu PHAR ... ';

        $old  = error_reporting(0);
        $phar = file_get_contents($remoteFilename);
        error_reporting($old);
        if (!$phar) {
            echo ' failed' . PHP_EOL . 'Could not reach phpbu update site' . PHP_EOL;
            exit(self::EXIT_EXCEPTION);
        }
        file_put_contents($tempFilename, $phar);

        chmod($tempFilename, 0777 & ~umask());

        // check downloaded phar
        try {
            $phar = new Phar($tempFilename);
            unset($phar);
            // replace current phar with the new one
            rename($tempFilename, $localFilename);
        } catch (Exception $e) {
            // cleanup crappy phar
            unlink($tempFilename);
            echo 'failed' . PHP_EOL . $e->getMessage() . PHP_EOL;
            exit(self::EXIT_EXCEPTION);
        }

        echo 'done' . PHP_EOL;
        exit(self::EXIT_SUCCESS);
    }

    /**
     * Handle phar version-check.
     */
    protected function handleVersionCheck()
    {
        $this->printVersionString();

        $latestVersion = $this->getLatestVersion();
        if ($this->isPharOutdated($latestVersion)) {
            print 'You are not using the latest version of phpbu.' . PHP_EOL
                . 'Use "phpunit --self-upgrade" to install phpbu ' . $latestVersion . PHP_EOL;
        } else {
            print 'You are using the latest version of phpbu.' . PHP_EOL;
        }
        exit(self::EXIT_SUCCESS);
    }

    /**
     * Returns latest released phpbu version.
     *
     * @return string
     * @throws \RuntimeException
     */
    protected function getLatestVersion() : string
    {
        $old     = error_reporting(0);
        $version = file_get_contents('https://phar.phpbu.de/latest-version-of/phpbu');
        error_reporting($old);
        if (!$version) {
            echo 'Network-Error: Could not check latest version.' . PHP_EOL;
            exit(self::EXIT_EXCEPTION);
        }
        return $version;
    }

    /**
     * Check if current phar is outdated.
     *
     * @param  string $latestVersion
     * @return bool
     */
    protected function isPharOutdated(string $latestVersion) : bool
    {
        return version_compare($latestVersion, Version::id(), '>');
    }

    /**
     * Shows the current application version.
     */
    protected function printVersionString()
    {
        if ($this->isVersionStringPrinted) {
            return;
        }

        echo Version::getVersionString() . PHP_EOL . PHP_EOL;
        $this->isVersionStringPrinted = true;
    }

    /**
     * Show the phpbu logo
     */
    protected function printLogo()
    {
        echo self::$logo . PHP_EOL;
    }

    /**
     * Show the help message.
     */
    protected function printHelp()
    {
        $this->printVersionString();
        echo <<<EOT
Usage: phpbu [option]

  --bootstrap=<file>     A "bootstrap" PHP file that is included before the backup.
  --configuration=<file> A phpbu xml config file.
  --colors               Use colors in output.
  --debug                Display debugging information during backup generation.
  --limit=<subset>       Limit backup execution to a subset.
  --simulate             Perform a trial run with no changes made.
  -h, --help             Print this usage information.
  -v, --verbose          Output more verbose information.
  -V, --version          Output version information and exit.

EOT;
        if ($this->isPhar) {
            echo '  --version-check        Check whether phpbu is the latest version.' . PHP_EOL;
            echo '  --self-upgrade         Upgrade phpbu to the latest version.' . PHP_EOL;
        }
    }

    /**
     * Shows some given error message.
     *
     * @param string $message
     * @param bool   $hint
     */
    private function printError($message, $hint = false)
    {
        $help = $hint ? ', use "phpbu -h" for help' : '';
        $this->printVersionString();
        echo $message . $help . PHP_EOL;
        exit(self::EXIT_EXCEPTION);
    }

    /**
     * Main method, is called by phpbu command and the phar file.
     */
    public static function main()
    {
        $app = new static();
        $app->run($_SERVER['argv']);
    }
}
