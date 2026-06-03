<?php
/**
 * PHPUnit bootstrap file for Joomla Component testing
 */

// Define Joomla constants
if (!defined('_JEXEC')) {
    define('_JEXEC', 1);
}

if (!defined('JPATH_BASE')) {
    define('JPATH_BASE', __DIR__ . '/../../src');
}

if (!defined('JPATH_ROOT')) {
    define('JPATH_ROOT', JPATH_BASE);
}

if (!defined('JPATH_ADMINISTRATOR')) {
    define('JPATH_ADMINISTRATOR', JPATH_BASE . '/administrator');
}

if (!defined('JPATH_COMPONENT')) {
    define('JPATH_COMPONENT', JPATH_ADMINISTRATOR . '/components/com_translations');
}

// Composer autoloader
require_once __DIR__ . '/../../vendor/autoload.php';

// Mock Joomla base classes BEFORE loading component files
if (!class_exists('Joomla\CMS\MVC\Model\AdminModel')) {
    class AdminModel {
        protected function loadForm($name, $source = null, $options = [], $clear = false, $xpath = '') {
            return new Form();
        }

        protected function getItem($pk = null) {
            return new stdClass();
        }

        protected function loadFormData() {
            return [];
        }
    }
    class_alias('AdminModel', 'Joomla\CMS\MVC\Model\AdminModel');
}

if (!class_exists('Joomla\CMS\MVC\Model\FormModel')) {
    class FormModel {
        protected function loadForm($name, $source = null, $options = [], $clear = false, $xpath = '') {
            return new Form();
        }

        public function getForm($data = [], $loadData = true) {
            return new Form();
        }
    }
    class_alias('FormModel', 'Joomla\CMS\MVC\Model\FormModel');
}

if (!class_exists('Joomla\CMS\MVC\Model\ListModel')) {
    class ListModel {
        protected $state;

        public function __construct($config = [], $factory = null) {
            $this->state = new Registry();
        }

        protected function getDatabase() {
            return null;
        }

        protected function getItems() {
            return [];
        }

        protected function getTable($name = '', $prefix = '', $options = []) {
            return new Table();
        }

        protected function getState($property = null, $default = null) {
            if ($property === null) {
                return $this->state;
            }
            return $this->state->get($property, $default);
        }

        protected function setState($property, $value = null) {
            $this->state->set($property, $value);
        }

        protected function getUserStateFromRequest($key, $request, $default = null, $type = 'none') {
            return $default;
        }

        protected function populateState($ordering = null, $direction = 'asc') {
            // Mock implementation
        }

        protected function getListQuery() {
            return null;
        }
    }
    class_alias('ListModel', 'Joomla\CMS\MVC\Model\ListModel');
}

if (!class_exists('Joomla\CMS\Http\HttpFactory')) {
    class HttpFactory {}
    class_alias('HttpFactory', 'Joomla\CMS\Http\HttpFactory');
}

// Mock Form class
if (!class_exists('Joomla\CMS\Form\Form')) {
    class Form {}
    class_alias('Form', 'Joomla\CMS\Form\Form');
}

// Mock Application class
if (!class_exists('Joomla\CMS\Application\CMSApplication')) {
    class CMSApplication {
        public function getUserState($key, $default = null) {
            return $default;
        }
    }
    class_alias('CMSApplication', 'Joomla\CMS\Application\CMSApplication');
}

// Mock Http class with get and post methods
if (!class_exists('Joomla\CMS\Http\Http')) {
    class Http {
        public function get($url, $headers = [], $timeout = null) {
            return (object)[
                'body' => '[]',
                'code' => 200
            ];
        }

        public function post($url, $data, $headers = [], $timeout = null) {
            return (object)[
                'body' => '{"success": true}',
                'code' => 200
            ];
        }
    }
    class_alias('Http', 'Joomla\CMS\Http\Http');
}

// Mock Registry class
if (!class_exists('Joomla\Registry\Registry')) {
    class Registry {
        private $data = [];

        public function __construct($data = []) {
            $this->data = (array)$data;
        }

        public function get($path, $default = null) {
            return $this->data[$path] ?? $default;
        }

        public function set($path, $value) {
            $this->data[$path] = $value;
        }
    }
    class_alias('Registry', 'Joomla\Registry\Registry');
}

// Mock Table class
if (!class_exists('Joomla\CMS\Table\Table')) {
    class Table {}
    class_alias('Table', 'Joomla\CMS\Table\Table');
}

// Mock Database classes
if (!class_exists('Joomla\Database\DatabaseDriver')) {
    class DatabaseDriver {
        public function getQuery($new = false) {
            return new QueryInterface();
        }

        public function quoteName($name, $alias = null) {
            return $alias ? "$name AS $alias" : $name;
        }

        public function escape($text, $extra = false) {
            return $text;
        }

        public function quote($text, $escape = true) {
            return "'$text'";
        }
    }
    class_alias('DatabaseDriver', 'Joomla\Database\DatabaseDriver');
}

if (!class_exists('Joomla\Database\QueryInterface')) {
    class QueryInterface {
        public function select($columns = null) { return $this; }
        public function from($tables = null) { return $this; }
        public function where($conditions = null) { return $this; }
        public function order($columns = null) { return $this; }
    }
    class_alias('QueryInterface', 'Joomla\Database\QueryInterface');
}

// Mock Joomla's Factory class
if (!class_exists('Joomla\CMS\Factory')) {
    class_alias('MockFactory', 'Joomla\CMS\Factory');
}

// Mock Factory class
class MockFactory
{
    public static $application = null;
    public static $database = null;
    public static $user = null;

    public static function getApplication()
    {
        return self::$application;
    }

    public static function getDbo()
    {
        return self::$database;
    }

    public static function getUser()
    {
        return self::$user;
    }
}

// Mock UnitTestCase if not available
if (!class_exists('Joomla\Tests\Unit\UnitTestCase')) {
    class UnitTestCase extends \PHPUnit\Framework\TestCase
    {
        // Base test case functionality
    }

    // Create alias in Joomla namespace
    class_alias('UnitTestCase', 'Joomla\Tests\Unit\UnitTestCase');
}

// NOW load component classes after mocks are in place
$componentSrcPath = __DIR__ . '/../../src/administrator/components/com_translations/src';
$componentFiles = [
    'Model/QueueModel.php',
    'Model/EditorModel.php',
];

foreach ($componentFiles as $file) {
    $filePath = $componentSrcPath . '/' . $file;
    if (file_exists($filePath)) {
        require_once $filePath;
    }
}