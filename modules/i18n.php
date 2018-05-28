<?php
require_once INCLUDES_DIR . 'utils.php';

class i18n_Compiler extends Module
{
    public $prologue = "Manages translation files from Crowdin";
    public $arguments = array(
        "command" => array(
            'help' => "Action to be performed.",
            "options" => array(
                'make-pot' => 'Build the PO file for gettext translations',
            ),
        ),
    );
    public $options = array(
        'root' => array('-R', '--root', 'matavar' => 'path',
            'help' => 'Specify a root folder for `make-pot`'),
        'domain' => array('-D', '--domain', 'metavar' => 'name',
            'default' => '',
            'help' => 'Add a domain to the path/context of PO strings'),
    );
    public $epilog = "";

    public function run($args, $options)
    {
        switch (strtolower($args['command'])) {
            case 'make-pot':
                $this->_make_pot($options);
                break;
        }
    }

    public function __read_next_string($tokens)
    {
        $string = array();
        while (list(, $T) = each($tokens)) {
            switch ($T[0]) {
                case T_CONSTANT_ENCAPSED_STRING:
                    // Strip leading and trailing ' and " chars
                    $string['form'] = preg_replace(array("`^{$T[1][0]}`", "`{$T[1][0]}$`"), array("", ""), $T[1]);
                    $string['line'] = $T[2];
                    break;
                case T_DOC_COMMENT:
                case T_COMMENT:
                    switch ($T[1][0]) {
                        case '/':
                            if ($T[1][1] == '*') {
                                $text = trim($T[1], '/* ');
                            } else {
                                $text = ltrim($T[1], '/ ');
                            }

                            break;
                        case '#':
                            $text = ltrim($T[1], '# ');
                    }
                    $string['comments'][] = $text;
                    break;
                case T_WHITESPACE:
                    // noop
                    continue;
                case T_STRING_VARNAME:
                case T_NUM_STRING:
                case T_ENCAPSED_AND_WHITESPACE:
                case '.':
                    $string['constant'] = false;
                    break;
                case '[':
                    // Not intended to be translated — array index
                    return null;
                default:
                    return array($string, $T);
            }
        }
    }
    public function __read_args($tokens, $proto = false)
    {
        $args = array('forms' => array());
        $arg = null;
        $proto = $proto ?: array('forms' => 1);
        while (list($string, $T) = $this->__read_next_string($tokens)) {
            // Add context and forms
            if (isset($proto['context']) && !isset($args['context'])) {
                $args['context'] = $string['form'];
            } elseif (count($args['forms']) < $proto['forms'] && $string) {
                if (isset($string['constant']) && !$string['constant']) {
                    throw new Exception($string['form'] . ': Untranslatable string');
                }
                $args['forms'][] = $string['form'];
            } elseif ($string) {
                $this->stderr->write(sprintf("%s: %s: Too many arguments\n",
                    $string['line'] ?: '?', $string['form']));
            }
            // Add usage and comment info
            if (!isset($args['line']) && isset($string['line'])) {
                $args['line'] = $string['line'];
            }

            if (isset($string['comments'])) {
                $args['comments'] = array_merge(
                    @$args['comments'] ?: array(), $string['comments']);
            }

            // Handle the terminating token from ::__read_next_string()
            switch ($T[0]) {
                case ')':
                    return $args;
            }
        }
    }
    public function __get_func_args($tokens, $args)
    {
        while (list(, $T) = each($tokens)) {
            switch ($T[0]) {
                case T_WHITESPACE:
                    continue;
                case '(':
                    return $this->__read_args($tokens, $args);
                default:
                    // Not a function call
                    return false;
            }
        }
    }
    public function __find_strings($tokens, $funcs, $parens = 0)
    {
        $T_funcs = array();
        $funcdef = false;
        while (list(, $T) = each($tokens)) {
            switch ($T[0]) {
                case T_STRING:
                case T_VARIABLE:
                    if ($funcdef) {
                        break;
                    }

                    if ($T[1] == 'sprintf') {
                        foreach ($this->__find_strings($tokens, $funcs) as $i => $f) {
                            // Only the first on gets the php-format flag
                            if ($i == 0) {
                                $f['flags'] = array('php-format');
                            }

                            $T_funcs[] = $f;
                        }
                        break;
                    }
                    if (!isset($funcs[$T[1]])) {
                        continue;
                    }

                    $constants = $funcs[$T[1]];
                    if ($info = $this->__get_func_args($tokens, $constants)) {
                        $T_funcs[] = $info;
                    }

                    break;
                case T_COMMENT:
                case T_DOC_COMMENT:
                    $translate = false;
                    $hints = array();
                    if (preg_match('`^/\*+\s*@(\w+)`m', $T[1])) {
                        foreach (preg_split('`,\s*`m', $T[1]) as $command) {
                            $command = trim($command, " \n\r\t\"*/\\");
                            @list($command, $args) = explode(' ', $command, 2);
                            switch ($command) {
                                case '@context':
                                    $hints['context'] = trim($args, " \"*\n\t\r");
                                case '@trans':
                                    $translate = true;
                                default:
                                    continue;
                            }
                        }
                    }
                    if ($translate) {
                        // Find the next textual token
                        list($S, $T) = $this->__read_next_string($tokens);
                        $string = array('forms' => array($S['form']), 'line' => $S['line'])
                             + $hints;
                        if (isset($S['comments'])) {
                            $string['comments'] = $S['comments'];
                        }

                        $T_funcs[] = $string;
                    }
                    break;
                // Track function definitions of the gettext functions
                case T_FUNCTION:
                    $funcdef = true;
                    break;
                case '{';
                    $funcdef = false;
                case '(':
                    $parens++;
                    break;
                case ')':
                    // End of scope?
                    if (--$parens == 0) {
                        return $T_funcs;
                    }

            }
        }
        return $T_funcs;
    }
    public function __write_string($string)
    {
        // Unescape single quote (') and escape unescaped double quotes (")
        $string = preg_replace(array("`\\\(['$])`", '`(?<!\\\)"`'), array("$1", '\"'), $string);
        // Preserve embedded newlines -- preserve up to on
        $string = preg_replace("`\n`u", "\\n\n", $string);
        // Word-wrap long lines
        $string = rtrim(preg_replace('/(?=[\s\p{Ps}])(.{1,76})(\s|$|(\p{Ps}))/uS',
            "$1$2\n", $string), "\n");
        $strings = array_filter(explode("\n", $string));
        if (count($strings) > 1) {
            array_unshift($strings, "");
        }

        foreach ($strings as $line) {
            print "\"{$line}\"\n";
        }
    }
    public function __write_pot_header()
    {
        $lines = array(
            'msgid ""',
            'msgstr ""',
            '"POT-Create-Date: ' . date('Y-m-d H:i O') . '\n"',
            '"Language: en_US\n"',
            '"MIME-Version: 1.0\n"',
            '"Content-Type: text/plain; charset=UTF-8\n"',
            '"Content-Transfer-Encoding: 8bit\n"',
            '"X-Generator: osTicket i18n CLI\n"',
        );
        print implode("\n", $lines);
        print "\n";
    }
    public function __write_pot($strings)
    {
        $this->__write_pot_header();
        foreach ($strings as $S) {
            print "\n";
            if ($c = @$S['comments']) {
                foreach ($c as $comment) {
                    foreach (explode("\n", $comment) as $line) {
                        if ($line = trim($line)) {
                            print "#. {$line}\n";
                        }

                    }
                }
            }
            foreach ($S['usage'] as $ref) {
                print "#: " . $ref . "\n";
            }
            if ($f = @$S['flags']) {
                print "#, " . implode(', ', $f) . "\n";
            }
            if (isset($S['context'])) {
                print "msgctxt ";
                $this->__write_string($S['context']);
            }
            print "msgid ";
            $this->__write_string($S['forms'][0]);
            if (count($S['forms']) == 2) {
                print "msgid_plural ";
                $this->__write_string($S['forms'][1]);
                print 'msgstr[0] ""' . "\n";
                print 'msgstr[1] ""' . "\n";
            } else {
                print 'msgstr ""' . "\n";
            }
        }
    }
    public function find_strings($options)
    {
        error_reporting(E_ALL);
        $funcs = array(
            '__' => array('forms' => 1),
            '$__' => array('forms' => 1),
            '_S' => array('forms' => 1),
            '_N' => array('forms' => 2),
            '$_N' => array('forms' => 2),
            '_NS' => array('forms' => 2),
            '_P' => array('context' => 1, 'forms' => 1),
            '_NP' => array('context' => 1, 'forms' => 2),
            // This is an error
            '_' => array('forms' => 0),
        );
        $root = realpath($options['root'] ?: ROOT_DIR);
        $domain = $options['domain'] ? '(' . $options['domain'] . ')/' : '';
        $files = glob_recursive($root . '/*.php');
        $strings = array();
        foreach ($files as $f) {
            $F = str_replace($root . '/', $domain, $f);
            $this->stderr->write("$F\n");
            $tokens = new ArrayObject(token_get_all(fread(fopen($f, 'r'), filesize($f))));
            foreach ($this->__find_strings($tokens, $funcs, 1) as $call) {
                self::__addString($strings, $call, $F);
            }
        }
        return array_merge($strings, $this->__getAllJsPhrases($root));
    }
    public function _make_pot($options)
    {
        $strings = $this->find_strings($options);
        $this->__write_pot($strings);
    }
    public static function __addString(&$strings, $call, $file = false)
    {
        if (!($forms = @$call['forms']))
        // Transation of non-constant
        {
            return;
        }

        $primary = $forms[0];
        // Normalize the $primary string
        $primary = preg_replace(array("`\\\(['$])`", '`(?<!\\\)"`'), array("$1", '\"'), $primary);
        if (isset($call['context'])) {
            $primary = $call['context'] . "\x04" . $primary;
        }

        if (!isset($strings[$primary])) {
            $strings[$primary] = array('forms' => $forms);
        }
        $E = &$strings[$primary];
        if (isset($call['line']) && $file) {
            $E['usage'][] = "{$file}:{$call['line']}";
        }

        if (isset($call['flags'])) {
            $E['flags'] = array_unique(array_merge(@$E['flags'] ?: array(), $call['flags']));
        }

        if (isset($call['comments'])) {
            $E['comments'] = array_merge(@$E['comments'] ?: array(), $call['comments']);
        }

        if (isset($call['context'])) {
            $E['context'] = $call['context'];
        }

    }
    public function __getAllJsPhrases($root = ROOT_DIR)
    {
        $strings = array();
        $root = rtrim($root, '/') . '/';
        $funcs = array('__' => array('forms' => 1));
        foreach (glob_recursive($root . "*.js") as $s) {
            $script = file_get_contents($s);
            $s = str_replace($root, '', $s);
            $this->stderr->write($s . "\n");
            $calls = array();
            preg_match_all('/(?:function\s+)?__\(\s*[^\'"]*(([\'"])(?:(?<!\\\\)\2|.)+\2)\s*[^)]*\)/',
                $script, $calls, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
            foreach ($calls as $c) {
                if (!($call = $this->__find_strings(token_get_all('<?php ' . $c[0][0]), $funcs, 0))) {
                    continue;
                }

                $call = $call[0];
                list($lhs) = str_split($script, $c[1][1]);
                $call['line'] = strlen($lhs) - strlen(str_replace("\n", "", $lhs)) + 1;
                self::__addString($strings, $call, $s);
            }
        }
        return $strings;
    }
}

Module::register('i18n', 'i18n_Compiler');
