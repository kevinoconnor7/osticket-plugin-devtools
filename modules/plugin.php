<?php

define('LIB_DIR', BASE_PATH . 'lib/');

class PluginBuilder extends Module
{
    public $prologue = 'Inspects, tests, and builds a plugin PHAR file';

    public $arguments = array(
        'action' => array(
            'help' => 'What to do with the plugin',
            'options' => array(
                'build' => 'Compile a PHAR file for a plugin',
                'hydrate' => 'Prep plugin folders for embedding in osTicket directly',
                'list' => 'List the contents of a phar file',
                'unpack' => 'Unpack a PHAR file (similar to unzip)',
            ),
        ),
        'plugin' => array(
            'help' => "Plugin to be compiled",
            'required' => false,
        ),
    );

    public $options = array(
        'sign' => array('-S', '--sign', 'metavar' => 'KEY', 'help' =>
            'Sign the compiled PHAR file with the provided OpenSSL private
            key file'),
        'verbose' => array('-v', '--verbose', 'help' =>
            'Be more verbose', 'default' => false, 'action' => 'store_true'),
        'compress' => array('-z', '--compress', 'help' =>
            'Compress source files when hydrading and building. Useful for
            saving space when building PHAR files',
            'action' => 'store_true', 'default' => false),
        'crowdin_key' => array('-k', '--key', 'metavar' => 'API-KEY',
            'help' => 'Crowdin project API key.'),
        'crowdin_project' => array('-p', '--project', 'metavar' => 'PROJECT',
            'help' => 'Crowdin project name.'),
    );

    static $crowdin_api_url = 'https://api.crowdin.com/api/project/{project}/{command}';

    public function run($args, $options)
    {
        $this->crowdin_key = $options['crowdin_key'];
        if (!$this->crowdin_key && getenv('CROWDIN_API_KEY')) {
            $this->crowdin_key = getenv('CROWDIN_API_KEY');
        }

        $this->crowdin_project = $options['crowdin_project'];
        if (!$this->crowdin_project && getenv('CROWDIN_PROJECT')) {
            $this->crowdin_project = getenv('CROWDIN_PROJECT');
        }

        switch (strtolower($args['action'])) {
            case 'build':
                $plugin = $args['plugin'];

                if (!file_exists($plugin)) {
                    $this->fail("Plugin folder '$plugin' does not exist");
                }

                $this->_build($plugin, $options);
                break;

            case 'hydrate':
                $this->_hydrate($options);
                break;
            case 'list':
                $P = new Phar($args[1]);
                $base = realpath($args[1]);
                foreach (new RecursiveIteratorIterator($P) as $finfo) {
                    $name = str_replace('phar://' . $base . '/', '', $finfo->getPathname());
                    $this->stdout->write($name . "\n");
                }
                break;

            case 'list':
                $plugin = $args['plugin'];
                if (!file_exists($plugin)) {
                    $this->fail("PHAR file '$plugin' does not exist");
                }

                $p = new Phar($plugin);
                $total = 0;
                foreach (new RecursiveIteratorIterator($p) as $info) {
                    $this->stdout->write(sprintf(
                        "% 10.10d  %s  %s\n",
                        $info->getSize(),
                        strftime('%x %X', $info->getMTime()),
                        str_replace(
                            array('phar://', realpath($plugin) . '/'),
                            array('', ''),
                            (string) $info)));
                    $total += $info->getSize();
                }
                $this->stdout->write("---------------------------------------\n");
                $this->stdout->write(sprintf("% 10.10d\n", $total));
                break;

            default:
                $this->fail("Unsupported MAKE action. See help");
        }
    }

    public function _build($plugin, $options)
    {
        $plugin_name = basename($plugin);
        @unlink($plugin_name . '.phar');
        $phar = new Phar($plugin_name . '.phar');
        $phar->startBuffering();

        if ($options['sign']) {
            if (!function_exists('openssl_get_privatekey')) {
                $this->fail('OpenSSL extension required for signing');
            }

            $private = openssl_get_privatekey(
                file_get_contents($options['sign']));
            $pkey = '';
            openssl_pkey_export($private, $pkey);
            $phar->setSignatureAlgorithm(Phar::OPENSSL, $pkey);
        }

        $plugin_file = $plugin . '/plugin.php';

        // Read plugin info
        $info = (include $plugin_file);

        $this->resolveDependencies(array($plugin_file), false);

        $phar->buildFromDirectory($plugin);

        // Add library dependencies
        if (isset($info['requires'])) {
            $includes = array();
            foreach ($info['requires'] as $lib => $info) {
                if (!isset($info['map'])) {
                    continue;
                }

                foreach ($info['map'] as $lib => $local) {
                    $phar_path = trim($local, '/') . '/';
                    $full = rtrim(LIB_DIR . $lib, '/') . '/';
                    $files = new RecursiveIteratorIterator(
                        new RecursiveDirectoryIterator($full),
                        RecursiveIteratorIterator::SELF_FIRST);
                    foreach ($files as $f) {
                        if (file_exists("$plugin/$phar_path"))
                        // Hydrated
                        {
                            continue;
                        } elseif ($f->isDir())
                        // Unnecessary
                        {
                            continue;
                        } elseif (preg_match('`/tests?/`i', $f->getPathname()))
                        // Don't package tests
                        // XXX: Add a option to override this
                        {
                            continue;
                        }

                        $content = '';
                        $local = str_replace($full, $phar_path, $f->getPathname());
                        if ($options['compress'] && fnmatch('*.php', $f->getPathname())) {
                            $p = popen('php -w ' . realpath($f->getPathname()), 'r');
                            while ($b = fread($p, 8192)) {
                                $content .= $b;
                            }

                            fclose($p);
                            $phar->addFromString($local, $content);
                        } else {
                            $phar->addFile($f->getPathname(), $local);
                        }
                    }
                }
            }
        }

        // Add language files
        if (@$this->crowdin_key) {
            foreach ($this->getLanguageFiles($plugin_name) as $name => $content) {
                $name = ltrim($name, '/');
                if (!$content) {
                    continue;
                }

                $phar->addFromString("i18n/{$name}", $content);
            }
        } else {
            $this->stderr->write("Specify Crowdin API key to integrate language files\n");
        }

        $phar->setStub('<?php __HALT_COMPILER();');
        $phar->stopBuffering();
    }

    public function _hydrate($options)
    {
        $plugins = glob(dirname(__file__) . '/*/plugin.php');
        $this->resolveDependencies($plugins);

        // Move things into place
        foreach ($plugins as $plugin) {
            $p = (include $plugin);
            if (!isset($p['requires']) || !is_array($p['requires'])) {
                continue;
            }

            foreach ($p['requires'] as $lib => $info) {
                if (!isset($info['map']) || !is_array($info['map'])) {
                    continue;
                }

                foreach ($info['map'] as $lib => $local) {
                    $source = LIB_DIR . $lib;
                    $dest = dirname($plugin) . '/' . $local;
                    if ($this->options['verbose']) {
                        $left = str_replace(dirname(__file__) . '/', '', $source);
                        $right = str_replace(dirname(__file__) . '/', '', $dest);
                        $this->stdout->write("Hydrating :: $left => $right\n");
                    }
                    if (is_file($source)) {
                        copy($left, $right);
                        continue;
                    }
                    foreach (
                        $iterator = new RecursiveIteratorIterator(
                            new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
                            RecursiveIteratorIterator::SELF_FIRST) as $item
                    ) {
                        if ($item->isDir()) {
                            continue;
                        }

                        $target = $dest . DIRECTORY_SEPARATOR . $iterator->getSubPathName();
                        $parent = dirname($target);
                        if (!file_exists($parent)) {
                            mkdir($parent, 0777, true);
                        }

                        // Compress PHP files
                        if ($options['compress'] && fnmatch('*.php', $item)) {
                            $p = popen('php -w ' . realpath($item), 'r');
                            $T = fopen($target, 'w');
                            while ($b = fread($p, 8192)) {
                                fwrite($T, $b);
                            }

                            fclose($p);
                            fclose($T);
                        } else {
                            copy($item, $target);
                        }
                    }
                }
                // TODO: Fetch language files for this plugin
            }
        }
    }

    public function _http_get($url)
    {
        #curl post
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_USERAGENT, 'osTicket-cli');
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $result = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return array($code, $result);
    }

    public function _crowdin($command, $args = array())
    {

        $url = str_replace(array('{command}', '{project}'),
            array($command, $this->crowdin_project),
            self::$crowdin_api_url);

        $args += array('key' => $this->crowdin_key);
        foreach ($args as &$a) {
            $a = urlencode($a);
        }

        unset($a);
        $url .= '?' . http_build_query($args);

        return $this->_http_get($url);
    }

    public function getTranslations()
    {
        error_reporting(E_ALL);
        list($code, $body) = $this->_crowdin('status');
        $langs = array();

        if ($code != 200) {
            $this->stderr->write($code . ": Bad status from Crowdin fetching translations\n");
            return $langs;
        }
        $d = new DOMDocument();
        $d->loadXML($body);

        $xp = new DOMXpath($d);
        foreach ($xp->query('//language') as $c) {
            $name = $code = '';
            foreach ($c->childNodes as $n) {
                switch (strtolower($n->nodeName)) {
                    case 'name':
                        $name = $n->textContent;
                        break;
                    case 'code':
                        $code = $n->textContent;
                        break;
                }
            }
            if (!$code) {
                continue;
            }

            $langs[] = $code;
        }
        return $langs;
    }

    public function getLanguageFiles($plugin_name)
    {
        $files = array();

        foreach ($this->getTranslations() as $lang) {
            list($code, $stuff) = $this->_crowdin("download/$lang.zip");
            if ($code != 200) {
                $this->stdout->write("$lang: Unable to download language files\n");
                continue;
            }

            $lang = str_replace('-', '_', $lang);

            // Extract a few files from the zip archive
            $temp = tempnam('/tmp', 'osticket-cli');
            $f = fopen($temp, 'w');
            fwrite($f, $stuff);
            fclose($f);
            $zip = new ZipArchive();
            $zip->open($temp);
            unlink($temp);

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $info = $zip->statIndex($i);
                if (strpos($info['name'], $plugin_name) === 0) {
                    $name = substr($info['name'], strlen($plugin_name));
                    $name = ltrim($name, '/');
                    if (substr($name, -3) == '.po') {
                        $content = $this->buildMo($zip->getFromIndex($i));
                        $name = substr($name, 0, -3) . '.mo.php';
                    } else {
                        $content = $zip->getFromIndex($i);
                    }
                    // Files in the plugin are laid out by (lang)/(file),
                    // where (file) has the plugin name removed. Files on
                    // Crowdin are organized by (plugin)/file
                    $this->stderr->write("$lang: Writing to output.\n");
                    $files["$lang/{$name}"] = $content;
                }
            }
            $zip->close();
        }
        return $files;
    }

    public function buildMo($po_contents)
    {
        require_once INCLUDES_DIR . 'translation.php';
        $pipes = array();
        $msgfmt = proc_open('msgfmt -o- -',
            array(0 => array('pipe', 'r'), 1 => array('pipe', 'w')),
            $pipes);
        if (is_resource($msgfmt)) {
            fwrite($pipes[0], $po_contents);
            fclose($pipes[0]);
            $mo_input = fopen('php://temp', 'r+b');
            fwrite($mo_input, stream_get_contents($pipes[1]));
            rewind($mo_input);
            $mo = Translation::buildHashFile($mo_input, false, true);
            fclose($mo_input);
        }
        return $mo;
    }

    public function ensureComposer()
    {
        if (file_exists(dirname(__file__) . '/composer.phar')) {
            return true;
        }

        return static::getComposer();
    }

    public function getComposer()
    {
        list($code, $phar) = $this->_http_get('https://getcomposer.org/composer.phar');

        if (!($fp = fopen(dirname(__file__) . '/composer.phar', 'wb'))) {
            $this->fail('Cannot install composer: Unable to write "composer.phar"');
        }

        fwrite($fp, $phar);
        fclose($fp);
    }

    public function resolveDependencies($plugins, $autoupdate = true)
    {
        // Build dependency list
        $requires = array();

        foreach ($plugins as $plugin) {
            $p = (include $plugin);
            if (isset($p['requires'])) {
                foreach ($p['requires'] as $lib => $info) {
                    $requires[$lib] = $info['version'];
                }
            }
        }

        // Write composer.json file
        $composer = <<<EOF
{
    "name": "osticket/plugin-devtools",
    "require": %s,
    "config": {
        "vendor-dir": "lib"
    }
}
EOF;
        $composer = sprintf($composer, json_encode($requires));

        if (!($fp = fopen('composer.json', 'w'))) {
            $this->fail('Unable to save "composer.json"');
        }

        fwrite($fp, $composer);
        fclose($fp);

        $this->ensureComposer();

        $php = defined('PHP_BINARY') ? PHP_BINARY : 'php';
        if (file_exists(dirname(__file__) . "/composer.lock")) {
            if ($autoupdate) {
                passthru($php . " " . dirname(__file__) . "/composer.phar -v update");
            }

        } else {
            passthru($php . " " . dirname(__file__) . "/composer.phar -v install");
        }

    }
}

Module::register('plugin', 'PluginBuilder');
