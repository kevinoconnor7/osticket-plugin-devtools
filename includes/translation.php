<?php

/**
 * Provides a simple gettext replacement that works independently from
 * the system's gettext abilities.
 * It can read MO files and use them for translating strings.
 * The files are passed to gettext_reader as a Stream (see streams.php)
 *
 * This version has the ability to cache all strings and translations to
 * speed up the string lookup.
 * While the cache is enabled by default, it can be switched off with the
 * second parameter in the constructor (e.g. whenusing very large MO files
 * that you don't want to keep in memory)
 */
class gettext_reader {
    //public:
     var $error = 0; // public variable that holds error code (0 if no error)
     //private:
    var $BYTEORDER = 0;        // 0: low endian, 1: big endian
    var $STREAM = NULL;
    var $short_circuit = false;
    var $enable_cache = false;
    var $originals = NULL;      // offset of original table
    var $translations = NULL;    // offset of translation table
    var $pluralheader = NULL;    // cache header field for plural forms
    var $total = 0;          // total string count
    var $table_originals = NULL;  // table for original strings (offsets)
    var $table_translations = NULL;  // table for translated strings (offsets)
    var $cache_translations = NULL;  // original -> translation mapping
    /* Methods */
    /**
     * Reads a 32bit Integer from the Stream
     *
     * @access private
     * @return Integer from the Stream
     */
    function readint() {
        if ($this->BYTEORDER == 0) {
          // low endian
          $input=unpack('V', $this->STREAM->read(4));
          return array_shift($input);
        } else {
          // big endian
          $input=unpack('N', $this->STREAM->read(4));
          return array_shift($input);
        }
      }
    function read($bytes) {
      return $this->STREAM->read($bytes);
    }
    /**
     * Reads an array of Integers from the Stream
     *
     * @param int count How many elements should be read
     * @return Array of Integers
     */
    function readintarray($count) {
      if ($this->BYTEORDER == 0) {
          // low endian
          return unpack('V'.$count, $this->STREAM->read(4 * $count));
        } else {
          // big endian
          return unpack('N'.$count, $this->STREAM->read(4 * $count));
        }
    }
    /**
     * Constructor
     *
     * @param object Reader the StreamReader object
     * @param boolean enable_cache Enable or disable caching of strings (default on)
     */
    function __construct($Reader, $enable_cache = true) {
      // If there isn't a StreamReader, turn on short circuit mode.
      if (! $Reader || isset($Reader->error) ) {
        $this->short_circuit = true;
        return;
      }
      // Caching can be turned off
      $this->enable_cache = $enable_cache;
      $MAGIC1 = "\x95\x04\x12\xde";
      $MAGIC2 = "\xde\x12\x04\x95";
      $this->STREAM = $Reader;
      $magic = $this->read(4);
      if ($magic == $MAGIC1) {
        $this->BYTEORDER = 1;
      } elseif ($magic == $MAGIC2) {
        $this->BYTEORDER = 0;
      } else {
        $this->error = 1; // not MO file
        return false;
      }
      // FIXME: Do we care about revision? We should.
      $this->revision = $this->readint();
      $this->total = $this->readint();
      $this->originals = $this->readint();
      $this->translations = $this->readint();
    }
    /**
     * Loads the translation tables from the MO file into the cache
     * If caching is enabled, also loads all strings into a cache
     * to speed up translation lookups
     *
     * @access private
     */
    function load_tables() {
      if (is_array($this->cache_translations) &&
        is_array($this->table_originals) &&
        is_array($this->table_translations))
        return;
      /* get original and translations tables */
      if (!is_array($this->table_originals)) {
        $this->STREAM->seekto($this->originals);
        $this->table_originals = $this->readintarray($this->total * 2);
      }
      if (!is_array($this->table_translations)) {
        $this->STREAM->seekto($this->translations);
        $this->table_translations = $this->readintarray($this->total * 2);
      }
      if ($this->enable_cache) {
        $this->cache_translations = array ();
        /* read all strings in the cache */
        for ($i = 0; $i < $this->total; $i++) {
          $this->STREAM->seekto($this->table_originals[$i * 2 + 2]);
          $original = $this->STREAM->read($this->table_originals[$i * 2 + 1]);
          $this->STREAM->seekto($this->table_translations[$i * 2 + 2]);
          $translation = $this->STREAM->read($this->table_translations[$i * 2 + 1]);
          $this->cache_translations[$original] = $translation;
        }
      }
    }
    /**
     * Returns a string from the "originals" table
     *
     * @access private
     * @param int num Offset number of original string
     * @return string Requested string if found, otherwise ''
     */
    function get_original_string($num) {
      $length = $this->table_originals[$num * 2 + 1];
      $offset = $this->table_originals[$num * 2 + 2];
      if (! $length)
        return '';
      $this->STREAM->seekto($offset);
      $data = $this->STREAM->read($length);
      return (string)$data;
    }
    /**
     * Returns a string from the "translations" table
     *
     * @access private
     * @param int num Offset number of original string
     * @return string Requested string if found, otherwise ''
     */
    function get_translation_string($num) {
      $length = $this->table_translations[$num * 2 + 1];
      $offset = $this->table_translations[$num * 2 + 2];
      if (! $length)
        return '';
      $this->STREAM->seekto($offset);
      $data = $this->STREAM->read($length);
      return (string)$data;
    }
    /**
     * Binary search for string
     *
     * @access private
     * @param string string
     * @param int start (internally used in recursive function)
     * @param int end (internally used in recursive function)
     * @return int string number (offset in originals table)
     */
    function find_string($string, $start = -1, $end = -1) {
      if (($start == -1) or ($end == -1)) {
        // find_string is called with only one parameter, set start end end
        $start = 0;
        $end = $this->total;
      }
      if (abs($start - $end) <= 1) {
        // We're done, now we either found the string, or it doesn't exist
        $txt = $this->get_original_string($start);
        if ($string == $txt)
          return $start;
        else
          return -1;
      } else if ($start > $end) {
        // start > end -> turn around and start over
        return $this->find_string($string, $end, $start);
      } else {
        // Divide table in two parts
        $half = (int)(($start + $end) / 2);
        $cmp = strcmp($string, $this->get_original_string($half));
        if ($cmp == 0)
          // string is exactly in the middle => return it
          return $half;
        else if ($cmp < 0)
          // The string is in the upper half
          return $this->find_string($string, $start, $half);
        else
          // The string is in the lower half
          return $this->find_string($string, $half, $end);
      }
    }
    /**
     * Translates a string
     *
     * @access public
     * @param string string to be translated
     * @return string translated string (or original, if not found)
     */
    function translate($string) {
      if ($this->short_circuit)
        return $string;
      $this->load_tables();
      if ($this->enable_cache) {
        // Caching enabled, get translated string from cache
        if (array_key_exists($string, $this->cache_translations))
          return $this->cache_translations[$string];
        else
          return $string;
      } else {
        // Caching not enabled, try to find string
        $num = $this->find_string($string);
        if ($num == -1)
          return $string;
        else
          return $this->get_translation_string($num);
      }
    }
    /**
     * Sanitize plural form expression for use in PHP eval call.
     *
     * @access private
     * @return string sanitized plural form expression
     */
    function sanitize_plural_expression($expr) {
      // Get rid of disallowed characters.
      $expr = preg_replace('@[^a-zA-Z0-9_:;\(\)\?\|\&=!<>+*/\%-]@', '', $expr);
      // Add parenthesis for tertiary '?' operator.
      $expr .= ';';
      $res = '';
      $p = 0;
      for ($i = 0, $k = strlen($expr); $i < $k; $i++) {
        $ch = $expr[$i];
        switch ($ch) {
        case '?':
          $res .= ' ? (';
          $p++;
          break;
        case ':':
          $res .= ') : (';
          break;
        case ';':
          $res .= str_repeat( ')', $p) . ';';
          $p = 0;
          break;
        default:
          $res .= $ch;
        }
      }
      return $res;
    }
    /**
     * Parse full PO header and extract only plural forms line.
     *
     * @access private
     * @return string verbatim plural form header field
     */
    function extract_plural_forms_header_from_po_header($header) {
      $regs = array();
      if (preg_match("/(^|\n)plural-forms: ([^\n]*)\n/i", $header, $regs))
        $expr = $regs[2];
      else
        $expr = "nplurals=2; plural=n == 1 ? 0 : 1;";
      return $expr;
    }
    /**
     * Get possible plural forms from MO header
     *
     * @access private
     * @return string plural form header
     */
    function get_plural_forms() {
      // lets assume message number 0 is header
      // this is true, right?
      $this->load_tables();
      // cache header field for plural forms
      if (! is_string($this->pluralheader)) {
        if ($this->enable_cache) {
          $header = $this->cache_translations[""];
        } else {
          $header = $this->get_translation_string(0);
        }
        $expr = $this->extract_plural_forms_header_from_po_header($header);
        $this->pluralheader = $this->sanitize_plural_expression($expr);
      }
      return $this->pluralheader;
    }
    /**
     * Detects which plural form to take
     *
     * @access private
     * @param n count
     * @return int array index of the right plural form
     */
    function select_string($n) {
        // Expression reads
        // nplurals=X; plural= n != 1
        if (!isset($this->plural_expression)) {
            $matches = array();
            if (!preg_match('`nplurals\s*=\s*(\d+)\s*;\s*plural\s*=\s*(.+$)`',
                    $this->get_plural_forms(), $matches))
                return 1;
            $this->plural_expression = create_function('$n',
                sprintf('return %s;', str_replace('n', '($n)', $matches[2])));
            $this->plural_total = (int) $matches[1];
        }
        $func = $this->plural_expression;
        $plural = $func($n);
        return ($plural > $this->plural_total)
            ? $this->plural_total - 1
            : $plural;
    }
    /**
     * Plural version of gettext
     *
     * @access public
     * @param string single
     * @param string plural
     * @param string number
     * @return translated plural form
     */
    function ngettext($single, $plural, $number) {
      if ($this->short_circuit) {
        if ($number != 1)
          return $plural;
        else
          return $single;
      }
      // find out the appropriate form
      $select = $this->select_string($number);
      // this should contains all strings separated by NULLs
      $key = $single . chr(0) . $plural;
      if ($this->enable_cache) {
        if (! array_key_exists($key, $this->cache_translations)) {
          return ($number != 1) ? $plural : $single;
        } else {
          $result = $this->cache_translations[$key];
          $list = explode(chr(0), $result);
          return $list[$select];
        }
      } else {
        $num = $this->find_string($key);
        if ($num == -1) {
          return ($number != 1) ? $plural : $single;
        } else {
          $result = $this->get_translation_string($num);
          $list = explode(chr(0), $result);
          return $list[$select];
        }
      }
    }
    function pgettext($context, $msgid) {
      $key = $context . chr(4) . $msgid;
      $ret = $this->translate($key);
      if (strpos($ret, "\004") !== FALSE) {
        return $msgid;
      } else {
        return $ret;
      }
    }
    function npgettext($context, $singular, $plural, $number) {
      $key = $context . chr(4) . $singular;
      $ret = $this->ngettext($key, $plural, $number);
      if (strpos($ret, "\004") !== FALSE) {
        return $singular;
      } else {
        return $ret;
      }
    }
  }

class Translation extends gettext_reader
{
    const META_HEADER = 0;

    public static function buildHashFile($mofile, $outfile = false, $return = false)
    {
        if (!$outfile) {
            $stream = fopen('php://stdout', 'w');
        } elseif (is_string($outfile)) {
            $stream = fopen($outfile, 'w');
        } elseif (is_resource($outfile)) {
            $stream = $outfile;
        }
        if (!$stream) {
            throw new InvalidArgumentException(
                'Expected a filename or valid resource');
        }

        if (!$mofile instanceof FileReader) {
            $mofile = new FileReader($mofile);
        }

        $reader = new parent($mofile, true);
        if ($reader->short_circuit || $reader->error) {
            throw new Exception('Unable to initialize MO input file');
        }

        $reader->load_tables();
        // Get basic table
        if (!($table = $reader->cache_translations)) {
            throw new Exception('Unable to read translations from file');
        }

        // Transcode the table to UTF-8
        $header = $table[""];
        $info = array();
        preg_match('/^content-type: (.*)$/im', $header, $info);
        $charset = false;
        if ($content_type = $info[1]) {
            // Find the charset property
            $settings = explode(';', $content_type);
            foreach ($settings as $v) {
                @list($prop, $value) = explode('=', trim($v), 2);
                if (strtolower($prop) == 'charset') {
                    $charset = trim($value);
                    break;
                }
            }
        }
        if ($charset && strcasecmp($charset, 'utf-8') !== 0) {
            foreach ($table as $orig => $trans) {
                $table[Charset::utf8($orig, $charset)] =
                Charset::utf8($trans, $charset);
                unset($table[$orig]);
            }
        }
        // Add in some meta-data
        $table[self::META_HEADER] = array(
            'Revision' => $reader->revision, // From the MO
            'Total-Strings' => $reader->total, // From the MO
            'Table-Size' => count($table), // Sanity check for later
            'Build-Timestamp' => gmdate(DATE_RFC822),
            'Format-Version' => 'A', // Support future formats
            'Encoding' => 'UTF-8',
        );
        // Serialize the PHP array and write to output
        $contents = sprintf('<?php return %s;', var_export($table, true));
        if ($return) {
            return $contents;
        } else {
            fwrite($stream, $contents);
        }

    }
}

class FileReader
{
    public $_pos;
    public $_fd;
    public $_length;
    public function __construct($filename)
    {
        if (is_resource($filename)) {
            $this->_length = strlen(stream_get_contents($filename));
            rewind($filename);
            $this->_fd = $filename;
        } elseif (file_exists($filename)) {
            $this->_length = filesize($filename);
            $this->_fd = fopen($filename, 'rb');
            if (!$this->_fd) {
                $this->error = 3; // Cannot read file, probably permissions
                return false;
            }
        } else {
            $this->error = 2; // File doesn't exist
            return false;
        }
        $this->_pos = 0;
    }
    public function read($bytes)
    {
        if ($bytes) {
            fseek($this->_fd, $this->_pos);
            // PHP 5.1.1 does not read more than 8192 bytes in one fread()
            // the discussions at PHP Bugs suggest it's the intended behaviour
            $data = '';
            while ($bytes > 0) {
                $chunk = fread($this->_fd, $bytes);
                $data .= $chunk;
                $bytes -= strlen($chunk);
            }
            $this->_pos = ftell($this->_fd);
            return $data;
        } else {
            return '';
        }

    }
    public function seekto($pos)
    {
        fseek($this->_fd, $pos);
        $this->_pos = ftell($this->_fd);
        return $this->_pos;
    }
    public function currentpos()
    {
        return $this->_pos;
    }
    public function length()
    {
        return $this->_length;
    }
    public function close()
    {
        fclose($this->_fd);
    }
}
