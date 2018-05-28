<?php
define('BASE_PATH', dirname(__file__) . '/');
define('INCLUDES_DIR', BASE_PATH . 'includes/');
define('MODULES_DIR', BASE_PATH . 'modules/');

require_once INCLUDES_DIR . 'cli.php';

if (PHP_SAPI != "cli") {
    die("Management only supported from command-line\n");
}

class Manager extends Module
{
    public $prologue = "Developer tools for osTicket plugins";
    public $arguments = array(
        'action' => "Action to be managed",
    );
    public $usage = '$script action [options] [arguments]';
    public $autohelp = false;
    public function showHelp()
    {
        foreach (glob(MODULES_DIR . '*.php') as $script) {
            include_once $script;
        }

        global $registered_modules;
        $this->epilog =
            "Currently available modules follow. Use 'manage.php <module>
                --help' for usage regarding each respective module:";
        parent::showHelp();
        echo "\n";
        ksort($registered_modules);
        $width = max(array_map('strlen', array_keys($registered_modules)));
        foreach ($registered_modules as $name => $mod) {
            echo str_pad($name, $width + 2) . $mod->prologue . "\n";
        }

    }
    public function run($args, $options)
    {
        if ($options['help'] && !$args['action']) {
            $this->showHelp();
        } else {
            $action = $args['action'];
            global $argv;
            foreach ($argv as $idx => $val) {
                if ($val == $action) {
                    unset($argv[$idx]);
                }
            }

            require_once MODULES_DIR . "{$args['action']}.php";
            if (($module = Module::getInstance($action))) {
                return $module->_run($args['action']);
            }

            $this->stderr->write("Unknown action given\n");
            $this->showHelp();
        }
    }
}

$manager = new Manager();
$manager->_run(basename(__file__), false);
