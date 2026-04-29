$path = getcwd() . '/codeception-config/codeception.yml';
if (!is_file($path)) {
    fwrite(STDERR, "ERROR: codeception-config/codeception.yml not found at $path\n");
    exit(1);
}
$autoload = getcwd() . '/../../../vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "ERROR: Magento vendor/autoload.php not found at $autoload\n");
    exit(1);
}
require $autoload;
if (!class_exists('Symfony\Component\Yaml\Yaml')) {
    fwrite(STDERR, "ERROR: Symfony YAML component not installed in Magento vendor\n");
    exit(1);
}
$config = Symfony\Component\Yaml\Yaml::parseFile($path);
if (!is_array($config)) {
    $config = [];
}
if (!isset($config['modules']) || !is_array($config['modules'])) {
    $config['modules'] = [];
}
if (!isset($config['modules']['config']) || !is_array($config['modules']['config'])) {
    $config['modules']['config'] = [];
}
if (!isset($config['modules']['config']['WebDriver']) || !is_array($config['modules']['config']['WebDriver'])) {
    $config['modules']['config']['WebDriver'] = [];
}
$wd = &$config['modules']['config']['WebDriver'];
$wd['connection_timeout'] = %d;
$wd['request_timeout'] = %d;
$wd['pageload_timeout'] = %d;
$wd['wait'] = %d;
if (!isset($wd['capabilities']) || !is_array($wd['capabilities'])) {
    $wd['capabilities'] = [];
}
if (!isset($wd['capabilities']['goog:chromeOptions']) || !is_array($wd['capabilities']['goog:chromeOptions'])) {
    $wd['capabilities']['goog:chromeOptions'] = [];
}
if (!isset($wd['capabilities']['goog:chromeOptions']['prefs']) || !is_array($wd['capabilities']['goog:chromeOptions']['prefs'])) {
    $wd['capabilities']['goog:chromeOptions']['prefs'] = [];
}
$wd['capabilities']['goog:chromeOptions']['prefs']['download.default_directory'] = '/home/seluser/Downloads';
$wd['capabilities']['goog:chromeOptions']['prefs']['download.prompt_for_download'] = false;
$wd['capabilities']['goog:chromeOptions']['prefs']['download.directory_upgrade'] = true;
$wd['capabilities']['goog:chromeOptions']['prefs']['plugins.always_open_pdf_externally'] = true;
if (!isset($config['extensions']) || !is_array($config['extensions'])) {
    $config['extensions'] = [];
}
if (!isset($config['extensions']['enabled']) || !is_array($config['extensions']['enabled'])) {
    $config['extensions']['enabled'] = [];
}
$allureClass = 'Qameta\Allure\Codeception\AllureCodeception';
if (!in_array($allureClass, $config['extensions']['enabled'])) {
    $config['extensions']['enabled'][] = $allureClass;
}
if (!isset($config['extensions']['config']) || !is_array($config['extensions']['config'])) {
    $config['extensions']['config'] = [];
}
$config['extensions']['config'][$allureClass]['outputDirectory'] = '%s';
file_put_contents($path, Symfony\Component\Yaml\Yaml::dump($config, 10, 2));
