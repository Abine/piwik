<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik;

use Exception;
use Piwik\AssetManager\UIAssetCacheBuster;
use Piwik\Plugins\UsersManager\API as APIUsersManager;
use Piwik\View\ViewInterface;
use Twig_Environment;

/**
 * Transition for pre-Piwik 0.4.4
 */
if (!defined('PIWIK_USER_PATH')) {
    define('PIWIK_USER_PATH', PIWIK_INCLUDE_PATH);
}

/**
 * Encapsulates and manages a [Twig](http://twig.sensiolabs.org/) template.
 *
 * View lets you set properties that will be passed on to a Twig template.
 * View will also set several properties that will be available in all Twig
 * templates, including:
 *
 * - **currentModule**: The value of the **module** query parameter.
 * - **currentAction**: The value of the **action** query parameter.
 * - **userLogin**: The current user login name.
 * - **sites**: List of site data for every site the current user has at least
 *              view access for.
 * - **url**: The current URL (sanitized).
 * - **token_auth**: The current user's token auth.
 * - **userHasSomeAdminAccess**: True if the user has admin access to at least
 *                               one site, false if otherwise.
 * - **userIsSuperUser**: True if the user is the superuser, false if otherwise.
 * - **latest_version_available**: The latest version of Piwik available.
 * - **isWidget**: The value of the 'widget' query parameter.
 * - **show_autocompleter**: Whether the site selector should be shown or not.
 * - **loginModule**: The name of the currently used authentication module.
 * - **userAlias**: The alias of the current user.
 *
 * ### Template Naming Convention
 *
 * Template files should be named after the controller method they are used in.
 * If they are used in more than one controller method or are included by another
 * template, they should describe the output they generate and be prefixed with
 * an underscore, eg, **_dataTable.twig**.
 *
 * ### Twig
 *
 * Twig templates must exist in the **templates** folder in a plugin's root
 * folder.
 *
 * The following filters are available to twig templates:
 *
 * - **translate**: Outputs internationalized text using a translation token, eg,
 *                  `{{ 'General_Date'|translate }}`. sprintf parameters can be passed
 *                  to the filter.
 * - **urlRewriteWithParameters**: Modifies the current query string with the given
 *                                 set of parameters, eg,
 *
 *                                     {{ {'module':'MyPlugin', 'action':'index'} | urlRewriteWithParameters }}
 *
 * - **sumTime**: Pretty formats an number of seconds.
 * - **money**: Formats a numerical value as a monetary value using the currency
 *              of the supplied site (second arg is site ID).
 *              eg, `{{ 23|money(site.idsite)|raw }}
 * - **truncate**: Truncates the text to certain length (determined by first arg.)
 *                 eg, `{{ myReallyLongText|truncate(80) }}`
 * - **implode**: Calls `implode`.
 * - **ucwords**: Calls `ucwords`.
 *
 * The following functions are available to twig templates:
 *
 * - **linkTo**: Modifies the current query string with the given set of parameters,
 *               eg `{{ linkTo({'module':'MyPlugin', 'action':'index'}) }}`.
 * - **sparkline**: Outputs a sparkline image HTML element using the sparkline image
 *                  src link. eg, `{{ sparkline(sparklineUrl) }}`.
 * - **postEvent**: Posts an event that allows event observers to add text to a string
 *                  which is outputted in the template, eg, `{{ postEvent('MyPlugin.event') }}`
 * - **isPluginLoaded**: Returns true if the supplied plugin is loaded, false if otherwise.
 *                       `{% if isPluginLoaded('Goals') %}...{% endif %}`
 *
 * ### Examples
 *
 * **Basic usage**
 *
 *     // a controller method
 *     public function myView()
 *     {
 *         $view = new View("@MyPlugin/myView");
 *         $view->property1 = "a view property";
 *         $view->property2 = "another view property";
 *         return $view->render();
 *     }
 *
 *
 * @api
 */
class View implements ViewInterface
{
    private $template = '';

    /**
     * Instance
     * @var Twig_Environment
     */
    private $twig;
    protected $templateVars = array();
    private $contentType = 'text/html; charset=utf-8';
    private $xFrameOptions = null;

    /**
     * Constructor.
     *
     * @param string $templateFile The template file to load. Must be in the following format:
     *                             `"@MyPlugin/templateFileName"`. Note the absence of .twig
     *                             from the end of the name.
     */
    public function __construct($templateFile)
    {
        $templateExt = '.twig';
        if (substr($templateFile, -strlen($templateExt)) !== $templateExt) {
            $templateFile .= $templateExt;
        }
        $this->template = $templateFile;

        $this->initializeTwig();

        $this->piwik_version = Version::VERSION;
        $this->userLogin = Piwik::getCurrentUserLogin();
        $this->isSuperUser = Access::getInstance()->hasSuperUserAccess();

        try {
            $this->piwikUrl = SettingsPiwik::getPiwikUrl();
        } catch (Exception $ex) {
            // pass (occurs when DB cannot be connected to, perhaps piwik URL cache should be stored in config file...)
        }
    }

    /**
     * Returns the template filename.
     *
     * @return string
     */
    public function getTemplateFile()
    {
        return $this->template;
    }

    /**
     * Returns the variables to bind to the template when rendering.
     *
     * @param array $override Template variable override values. Mainly useful
     *                        when including View templates in other templates.
     * @return array
     */
    public function getTemplateVars($override = array())
    {
        return $override + $this->templateVars;
    }

    /**
     * Directly assigns a variable to the view script.
     * Variable names may not be prefixed with '_'.
     *
     * @param string $key The variable name.
     * @param mixed $val The variable value.
     */
    public function __set($key, $val)
    {
        $this->templateVars[$key] = $val;
    }

    /**
     * Retrieves an assigned variable.
     * Variable names may not be prefixed with '_'.
     *
     * @param string $key The variable name.
     * @return mixed The variable value.
     */
    public function &__get($key)
    {
        return $this->templateVars[$key];
    }

    /**
     * Returns true if a template variable has been set or not.
     *
     * @param $name The name of the template variable.
     * @return bool
     */
    public function __isset($name)
    {
        return isset($this->templateVars[$name]);
    }

    private function initializeTwig()
    {
        $piwikTwig = new Twig();
        $this->twig = $piwikTwig->getTwigEnvironment();
    }

    /**
     * Renders the current view. Also sends the stored 'Content-Type' HTML header.
     * See {@link setContentType()}.
     *
     * @return string Generated template.
     */
    public function render()
    {
        try {
            $this->currentModule = Piwik::getModule();
            $this->currentAction = Piwik::getAction();

            $this->url = Common::sanitizeInputValue(Url::getCurrentUrl());
            $this->token_auth = Piwik::getCurrentUserTokenAuth();
            $this->userHasSomeAdminAccess = Piwik::isUserHasSomeAdminAccess();
            $this->userIsSuperUser = Piwik::hasUserSuperUserAccess();
            $this->latest_version_available = UpdateCheck::isNewestVersionAvailable();
            $this->disableLink = Common::getRequestVar('disableLink', 0, 'int');
            $this->isWidget = Common::getRequestVar('widget', 0, 'int');
            $this->cacheBuster = UIAssetCacheBuster::getInstance()->piwikVersionBasedCacheBuster();

            $this->loginModule = Piwik::getLoginPluginName();

            $user = APIUsersManager::getInstance()->getUser($this->userLogin);
            $this->userAlias = $user['alias'];
        } catch (Exception $e) {
            Log::verbose($e);

            // can fail, for example at installation (no plugin loaded yet)
        }

        try {
            $this->totalTimeGeneration = Registry::get('timer')->getTime();
            $this->totalNumberOfQueries = Profiler::getQueryCount();
        } catch (Exception $e) {
            $this->totalNumberOfQueries = 0;
        }

        ProxyHttp::overrideCacheControlHeaders('no-store');

        @header('Content-Type: ' . $this->contentType);
        // always sending this header, sometimes empty, to ensure that Dashboard embed loads (which could call this header() multiple times, the last one will prevail)
        @header('X-Frame-Options: ' . (string)$this->xFrameOptions);

        return $this->renderTwigTemplate();
    }

    protected function renderTwigTemplate()
    {
        try {
            $output = $this->twig->render($this->getTemplateFile(), $this->getTemplateVars());
        } catch (Exception $ex) {
            // twig does not rethrow exceptions, it wraps them so we log the cause if we can find it
            $cause = $ex->getPrevious();
            Log::debug($cause === null ? $ex : $cause);

            throw $ex;
        }

        $output = $this->applyFilter_cacheBuster($output);

        $helper = new Theme;
        $output = $helper->rewriteAssetsPathToTheme($output);
        return $output;
    }

    protected function applyFilter_cacheBuster($output)
    {
        $assetManager = AssetManager::getInstance();

        $stylesheet = $assetManager->getMergedStylesheetAsset();
        if ($stylesheet->exists()) {
            $content = $stylesheet->getContent();
        } else {
            $content = $assetManager->getMergedStylesheet()->getContent();
        }

        $cacheBuster = UIAssetCacheBuster::getInstance();
        $tagJs       = 'cb=' . $cacheBuster->piwikVersionBasedCacheBuster();
        $tagCss      = 'cb=' . $cacheBuster->md5BasedCacheBuster($content);

        $pattern = array(
            '~<script type=[\'"]text/javascript[\'"] src=[\'"]([^\'"]+)[\'"]>~',
            '~<script src=[\'"]([^\'"]+)[\'"] type=[\'"]text/javascript[\'"]>~',
            '~<link rel=[\'"]stylesheet[\'"] type=[\'"]text/css[\'"] href=[\'"]([^\'"]+)[\'"] ?/?>~',
            // removes the double ?cb= tag
            '~(src|href)=\"index.php\?module=([A-Za-z0-9_]+)&action=([A-Za-z0-9_]+)\?cb=~',
        );

        $replace = array(
            '<script type="text/javascript" src="$1?' . $tagJs . '">',
            '<script type="text/javascript" src="$1?' . $tagJs . '">',
            '<link rel="stylesheet" type="text/css" href="$1?' . $tagCss . '" />',
            '$1="index.php?module=$2&amp;action=$3&amp;cb=',
        );

        return preg_replace($pattern, $replace, $output);
    }

    /**
     * Set stored value used in the Content-Type HTTP header field. The header is
     * set just before rendering.
     *
     * @param string $contentType
     */
    public function setContentType($contentType)
    {
        $this->contentType = $contentType;
    }

    /**
     * Set X-Frame-Options field in the HTTP response. The header is set just
     * before rendering.
     *
     * _Note: setting this allows you to make sure the View **cannot** be
     * embedded in iframes. Learn more [here](https://developer.mozilla.org/en-US/docs/HTTP/X-Frame-Options)._
     *
     * @param string $option ('deny' or 'sameorigin')
     */
    public function setXFrameOptions($option = 'deny')
    {
        if ($option === 'deny' || $option === 'sameorigin') {
            $this->xFrameOptions = $option;
        }
        if ($option == 'allow') {
            $this->xFrameOptions = null;
        }
    }

    /**
     * Add form to view
     *
     * @param QuickForm2 $form
     * @ignore
     */
    public function addForm(QuickForm2 $form)
    {

        // assign array with form data
        $this->assign('form_data', $form->getFormData());
        $this->assign('element_list', $form->getElementList());
    }

    /**
     * Assign value to a variable for use in a template
     * @param string|array $var
     * @param mixed $value
     * @ignore
     */
    public function assign($var, $value = null)
    {
        if (is_string($var)) {
            $this->$var = $value;
        } elseif (is_array($var)) {
            foreach ($var as $key => $value) {
                $this->$key = $value;
            }
        }
    }

    /**
     * Clear compiled Twig templates
     * @ignore
     */
    public static function clearCompiledTemplates()
    {
        $twig = new Twig();
        $twig->getTwigEnvironment()->clearTemplateCache();
    }

    /**
     * Creates a View for and then renders the single report template.
     *
     * Can be used for pages that display only one report to avoid having to create
     * a new template.
     *
     * @param string $title The report title.
     * @param string $reportHtml The report body HTML.
     * @return string|void The report contents if `$fetch` is true.
     */
    public static function singleReport($title, $reportHtml)
    {
        $view = new View('@CoreHome/_singleReport');
        $view->title = $title;
        $view->report = $reportHtml;
        return $view->render();
    }
}
