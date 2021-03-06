<?php
// Google+ extension for Bolt

namespace Bolt\Extension\DanielKulbe\Internationalisation;

use Bolt\Application;
use Bolt\BaseExtension;
use Bolt\Content;
use Bolt\Controllers\Async;
use Bolt\Controllers\Backend;
use Bolt\Controllers\Routing;
use Bolt\Library as Lib;
use Bolt\Translation\Translator as Trans;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Intl\Intl;
use Symfony\Component\Intl\ResourceBundle\LanguageBundleInterface;

class Extension extends BaseExtension
{
    const FLAG_CONTENTTYPE_I18N = 'i18n';

    /**
     * Extension name
     *
     * @var string
     */
    protected $default_locale;

    /** @var SessionInterface */
    protected $session;

    /** @var LanguageBundleInterface */
    protected $language;

    public $locale = null;

    private $request;


    /**
     * Add Twig settings in 'frontend' environment
     *
     * @return void
     */
    public function initialize()
    {
        $this->request = Request::createFromGlobals();
        $this->language = Intl::getLanguageBundle();


        // Add Extension template path
        $this->app['twig.loader.filesystem']->addPath(__DIR__ . '/assets');

        // and register the included Twig functions and filters
        $this->addTwigFunction('i18n_lang', 'languageNameFromLocale');
        $this->addTwigFunction('i18n_link', 'languageLink');
        $this->addTwigFunction('i18n_menu', 'localizedMenu');
        $this->addTwigFunction('i18n_langmenu', 'languageMenu');
        $this->addTwigFunction('i18n_attribute', 'localizedField');

        $this->addTwigFilter('i18n_lang', 'languageNameFromLocale');
        $this->addTwigFilter('i18n_link', 'languageLink');
        $this->addTwigFilter('i18n_attribute', 'localizedField');


        // Handle locale setup
        $this->default_locale = $this->app['config']->get('general/locale', Application::DEFAULT_LOCALE);
        $this->session = $this->app['session'];
        $this->saveLocale($this->getLocale());

        // Indicate content menu shall be fetched from translation
        if ($this->locale == $this->default_locale) {
            $this->app['i18n'] = $this->locale;
        }

        // Make language change in administration menu available
        $this->languageMenuAdmin();

        // Add i18n Content fields
        $this->i18nContent();
    }


    /**
     * Get the extension's human readable name
     *
     * @return string
     */
    public function getName()
    {
        return 'i18n';
    }


    /**
     * Set the defaults for configuration parameters
     *
     * @return array
     */
    protected function getDefaultConfig()
    {
        preg_match("/^[a-z]{2}/", Application::DEFAULT_LOCALE, $lang);
        preg_match("/[a-z]+$/", $_SERVER['SERVER_NAME'], $domain);
        return array(
            'locales' => array(
                Application::DEFAULT_LOCALE => array(
                    'query' => $lang[0],
                    'domain' => $domain[0],
                ),
            ),
            'detection' => 'query'
        );
    }


    /**
     * Receive the requested locale, match against default
     *
     * @return string
     */
    private function getLocale ()
    {
        // else iterate over possibilities
        $locale = null;

        // weight: 1 - by selected detection
        if('domain' == $this->config['detection']) {
            foreach ($this->config['locales'] as $key => $detection) {
                if (preg_match("/\\." . $detection['domain'] . "$/", $key)) {
                    $locale = $key;
                }
            }
        } else if ('query' == $this->config['detection']) {
            $lang = $this->request->query->get('lang');
            foreach ($this->config['locales'] as $key => $detection) {
                if (substr($key, 0, 2) === $lang) {
                    $locale = $key;
                }
            }
        }
        // weight: 2 - from session
        if (empty($locale)) {
            $locale = $this->session->get('locale');
        }
        // weight: 3 - from cookie
        if (empty($locale)) {
            $locale = $this->request->cookies->get('bolt_locale');
        }
        // weight: 4 - default / validation
        if(empty($locale) || !array_key_exists($locale, $this->config['locales'])) {
            $locale = $this->default_locale;
        }

        return $locale;
    }


    /**
     * Set the defaults for configuration parameters
     *
     * @param  $locale  string  The locale identifer, defined by [ISO 639.1]_[ISO 3166]
     * @return void
     */
    private function saveLocale ($locale)
    {
        $this->locale = $locale;
        if ($this->locale != $this->default_locale) {
            $this->app['config']->set('general/locale', $this->locale);
            // Set default locale
            // @hint: do not use initLocale / initMountpoints to keep impact as low as possible
            $this->app['locale'] = $this->locale;

            $locale = array(
                $this->locale . '.UTF-8',
                $this->locale . '.utf8',
                $this->locale,
                Application::DEFAULT_LOCALE . '.UTF-8',
                Application::DEFAULT_LOCALE . '.utf8',
                Application::DEFAULT_LOCALE,
                substr(Application::DEFAULT_LOCALE, 0, 2)
            );
            setlocale(LC_ALL, $locale);

            // All these run before initExtensions(), some Controller Classes have locale
            // dependencies in setup, so we need to mount them again in case the new locale
            // is different then the Application default
            $this->app->mount($this->app['config']->get('general/branding/path'), new Backend());
            $this->app->mount('/async', new Async());
            $this->app->mount('', new Routing());
        }

        # remember locale in session
        $in_session = $this->session->get('locale');
        if (empty($in_session) || $in_session !== $this->locale) {
            $this->session->set('locale', $this->locale);
        }

        # remember locale in cookie
        $in_cookie = $this->request->cookies->get('bolt_locale');
        if (empty($in_cookie) || $in_cookie !== $this->locale) {
            setcookie(
                'bolt_locale',
                $this->locale,
                time() + $this->app['config']->get('general/cookies_lifetime'),
                '/',
                $this->app['config']->get('general/cookies_domain'),
                $this->app['config']->get('general/cookies_https_only'),
                true
            );
        }
    }


    /**
     * i18n_lang Twig function/filter callback
     *
     * @param $locale
     *
     * @return null
     */
    public function languageNameFromLocale($locale)
    {
        if (!isset($locale)) {
            $locale = $this->locale;
        }

        return $this->language->getLanguageName(substr($locale, 0, 2));
    }


    /**
     * i18n_link Twig function/filter callback
     *
     * @param $locale
     *
     * @return null
     */
    public function languageLink ($locale)
    {
        if (!isset($locale)) {
            $locale = $this->locale;
        }

        switch ($this->config['detection']) {
            case 'query':
            default:
                $param = $this->config['locales'][$locale]['query'];
                $link = $this->app['paths']['current'].'?lang='.$param;
                break;
            case 'domain':
                $param = $this->config['locales'][$locale]['domain'];
                $link = preg_replace('/[a-z]$/', $param, $this->app['paths']['hosturl']) . $this->app['paths']['current'];
                break;
        }

        return $this->fixPath($link);
    }


    /**
     * i18n_langmenu Twig function callback
     *
     * @return null
     */
    public function languageMenu ()
    {
        $twigValues = array('locales' => array_keys($this->config['locales']), 'current' => $this->locale);

        $str = $this->app['render']->render('_sub_langmenu.twig', $twigValues);

        return new \Twig_Markup($str, 'UTF-8');
    }


    /**
     * Create i18n switch in Backend
     *
     * @return void
     */
    private function languageMenuAdmin ()
    {
        foreach ($this->config['locales'] as $locale => $values) {
            if ($locale != $this->locale) {
                $this->addMenuOption(
                    Trans::__(
                        'Switch to %language%',
                        ['%language%' => $this->languageNameFromLocale($locale)]
                    ),
                    $this->languageLink($locale),
                    "fa:flag"
                );
            }
        }
    }


    /**
     * Add localized field to each content type configuration. Use "i18n: true" with each
     * contenttype field, which to define localized alternative.
     *
     * @return void
     */
    private function i18nContent()
    {
        $types = $this->app['config']->get('contenttypes');
        $localisedTypes = [];

        foreach ($types as $type => $config) {
            $localisedTypes[$type] = $config;
            $localisedTypes[$type]['fields'] = [];

            foreach ($config['fields'] as $fieldName => $properties) {
                $localisationEnabled = array_key_exists(self::FLAG_CONTENTTYPE_I18N, $properties)
                    && $properties[self::FLAG_CONTENTTYPE_I18N];

                foreach ($this->config['locales'] as $locale => $unused) {
                    $hasLocalizedDependencies = false;
                    $localeSuffix             = '_' . substr($locale, 0, 2);
                    $localisedProperties = $properties;
                    $localisedLabel = array_key_exists('label', $properties)
                        ? $properties['label']
                        : ucfirst($fieldName);

                    if (array_key_exists('uses', $localisedProperties)) {
                        $localisedUses = array_map(function($usedField) use ($localeSuffix, $config) {
                            return array_key_exists($usedField, $config['fields'])
                                && array_key_exists(self::FLAG_CONTENTTYPE_I18N, $config['fields'][$usedField])
                                && $config['fields'][$usedField][self::FLAG_CONTENTTYPE_I18N]
                                ? $usedField . $localeSuffix
                                : $usedField;
                        }, $properties['uses']);
                        $hasLocalizedDependencies = !empty(array_diff($localisedUses, $properties['uses']));
                        $localisedProperties['uses'] = $localisedUses;
                    }

                    if ($localisationEnabled || $hasLocalizedDependencies) {
                        $localisedFieldName = $fieldName . $localeSuffix;
                        $localisedProperties['label'] = sprintf(
                            '%s (%s)',
                            $localisedLabel,
                            $this->languageNameFromLocale($locale)
                        );
                    } else {
                        $localisedFieldName = $fieldName;
                        $localisedProperties['label'] = $localisedLabel;
                    }

                    if (!array_key_exists($localisedFieldName, $localisedTypes[$type]['fields'])) {
                        $localisedTypes[$type]['fields'][$localisedFieldName] = $localisedProperties;
                    }
                }
            }
        }

        $this->app['config']->set('contenttypes', $localisedTypes);
    }


    /**
     * Recursively scans the passed array to ensure everything gets the menuHelper() treatment.
     *
     * @param  array $menu
     * @return array
     */
    private function menuBuilder($menu)
    {
        foreach ($menu as $key => $item) {
            $menu[$key] = $this->menuHelper($item);
            if (isset($item['submenu'])) {
                $menu[$key]['submenu'] = $this->menuBuilder($item['submenu']);
            }
        }

        return $menu;
    }


    /**
     * Updates a menu item to have at least a 'link' key.
     *
     * @param  array $item
     * @return array Keys 'link' and possibly 'label', 'title' and 'path'
     */
    private function menuHelper($item)
    {
        if (isset($item['submenu']) && is_array($item['submenu'])) {
            $item['submenu'] = $this->menuHelper($item['submenu']);
        }

        if (isset($item['path']) && $item['path'] == "homepage") {
            $item['link'] = $this->app['paths']['root'];
        } elseif (isset($item['route'])) {
            $param = empty($item['param']) ? array() : $item['param'];
            $add = empty($item['add']) ? '' : $item['add'];

            $item['link'] = Lib::path($item['route'], $param, $add);
        } elseif (isset($item['path'])) {
            // if the item is like 'content/1', get that content.
            if (preg_match('#^([a-z0-9_-]+)/([a-z0-9_-]+)$#i', $item['path'])) {
                $content = $this->app['storage']->getContent($item['path']);
            }

            if (!empty($content) && is_object($content) && $content instanceof Content) {
                // We have content.
                if (empty($item['label'])) {
                    $item['label'] = !empty($content->values['title']) ? $content->values['title'] : "";
                }
                if (empty($item['title'])) {
                    $item['title'] = !empty($content->values['subtitle']) ? $content->values['subtitle'] : "";
                }

                /** START: i18n */
                $i18n_token = '_' . substr($this->locale, 0, 2);
                if (isset($content->values['title'.$i18n_token]) && (!isset($item['label'.$i18n_token]) || empty($item['label'.$i18n_token]))) {
                    $item['label'.$i18n_token] = !empty($content->values['title'.$i18n_token]) ? $content->values['title'.$i18n_token] : "";
                }
                if (isset($content->values['subtitle'.$i18n_token]) && (!isset($item['title'.$i18n_token]) || empty($item['title'.$i18n_token]))) {
                    $item['title'.$i18n_token] = !empty($content->values['subtitle'.$i18n_token]) ? $content->values['subtitle'.$i18n_token] : "";
                }
                /** END */

                if (is_object($content)) {
                    $item['link'] = $content->link();
                }

                $item['record'] = $content;

            } else {
                // we assume the user links to this on purpose.
                $item['link'] = $this->fixPath($this->app['paths']['root'] . $item['path']);
            }

        }

        return $item;
    }


    /**
     * Output a localized menu.
     *
     * @param  string $identifier Identifier for a particular menu
     * @param  string $template   The template to use.
     * @param  array  $params     Extra parameters to pass on to the menu template.
     * @return null               Or rendered menu markup
     */
    public function localizedMenu($identifier = '', $template = '_sub_menu.twig', $params = array())
    {
        $menus = $this->app['config']->get('menu');

        if (!empty($identifier) && isset($menus[$identifier])) {
            $name = strtolower($identifier);
            $menu = $menus[$identifier];
        } else {
            $name = strtolower(array_keys($menus)[0]);
            $menu = array_values($menus)[0];
        }

        // If the menu loaded is null, replace it with an empty array instead of
        // throwing an error.
        if (!is_array($menu)) {
            $menu = array();
        }

        $menu = $this->menuBuilder($menu);

        $twigvars = array(
            'name' => $name,
            'menu' => $menu
        );

        // If $params is not empty, merge it with twigvars.
        if (!empty($params) && is_array($params)) {
            $twigvars = $twigvars + $params;
        }

        $str = $this->app['render']->render($template, $twigvars);

        return new \Twig_Markup($str, 'UTF-8');
    }


    /**
     * Will render the localized field attribute alternatives, defined in i18nContent() when
     * addressing original field names.
     *
     * @param  object $record    The Content / Menu record object
     * @param  string $fieldname The name of the field you want to output localized
     * @return null              Or the rendered Twig_Markup
     */
    public function localizedField ($record, $fieldname)
    {
        $i18nField = $this->localizeFieldName($fieldname);

        if ($record instanceof Content) {
            return !empty($record->getDecodedValue($i18nField))
                ? $record->getDecodedValue($i18nField)
                : $record->getDecodedValue($fieldname);
        }

        return null;
    }

    private function localizeFieldName($fieldname, $locale = null)
    {
        if(is_null($locale)) {
            $locale = substr($this->locale, 0, 2);
        }

        return $fieldname . '_' . $locale;
    }


    /**
     * Cleans up/fixes a relative paths.
     *
     * As an example '/site/pivotx/../index.php' becomes '/site/index.php'.
     * In addition (non-leading) double slashes are removed.
     *
     * @param  string $path
     * @param  bool   $nodoubleleadingslashes
     *
     * @return string
     */
    public static function fixPath($path, $nodoubleleadingslashes = true)
    {
        $path = str_replace("\\", "/", rtrim($path, '/'));

        // Handle double leading slash (that shouldn't be removed).
        if (!$nodoubleleadingslashes && (strpos($path, '//') === 0)) {
            $lead = '//';
            $path = substr($path, 2);
        } else {
            $lead = '';
        }

        $patharray = explode('/', preg_replace('#/+#', '/', $path));
        $newPath   = [];

        foreach ($patharray as $item) {
            if ($item == '..') {
                // remove the previous element
                @array_pop($newPath);
            } elseif ($item == 'http:') {
                // Don't break for URLs with http:// scheme
                $newPath[] = 'http:/';
            } elseif ($item == 'https:') {
                // Don't break for URLs with https:// scheme
                $newPath[] = 'https:/';
            } elseif (($item != '.')) {
                $newPath[] = $item;
            }
        }

        return $lead . implode('/', $newPath);
    }
}
