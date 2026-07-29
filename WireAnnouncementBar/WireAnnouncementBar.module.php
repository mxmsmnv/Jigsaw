<?php namespace ProcessWire;

/**
 * WireAnnouncementBar
 *
 * Displays a dismissible announcement banner for site visitors.
 * Ships with Tailwind CSS, Bootstrap 5, and UIkit 3 themes.
 *
 * @author  Maxim Semenov <maxim@smnv.org> (smnv.org)
 * @version 1.0.0
 * @license MIT
 *
 * @property int    $banner_enabled
 * @property string $banner_title
 * @property string $banner_message
 * @property string $banner_type
 * @property string $banner_framework
 * @property int    $banner_closeable
 * @property string $banner_link_url
 * @property string $banner_link_label
 * @property int    $banner_link_newtab
 * @property string $banner_cookie_name
 * @property int    $banner_cookie_days
 */
class WireAnnouncementBar extends WireData implements Module, ConfigurableModule {

    // -------------------------------------------------------------------------
    // Module info
    // -------------------------------------------------------------------------

    public static function getModuleInfo(): array {
        return [
            'title'    => 'Wire Announcement Bar',
            'summary'  => 'Dismissible announcement banner with Tailwind, Bootstrap and UIkit themes.',
            'version'  => '1.0.0',
            'author'   => 'Maxim Semenov',
            'href'     => 'https://smnv.org',
            'icon'     => 'bullhorn',
            'singular' => true,
            'autoload' => true,
            'requires' => 'ProcessWire>=3.0.0',
        ];
    }

    // -------------------------------------------------------------------------
    // Defaults
    // -------------------------------------------------------------------------

    public static function getDefaultConfig(): array {
        return [
            'banner_enabled'     => 1,
            'banner_title'       => '',
            'banner_message'     => '',
            'banner_type'        => 'warning',
            'banner_framework'   => 'tailwind',
            'banner_closeable'   => 1,
            'banner_link_url'    => '',
            'banner_link_label'  => 'Learn more',
            'banner_link_newtab' => 0,
            'banner_cookie_name' => 'announcement_bar_closed',
            'banner_cookie_days' => 1,
        ];
    }

    public function __construct() {
        parent::__construct();
        foreach (self::getDefaultConfig() as $key => $value) {
            $this->set($key, $value);
        }
    }

    // -------------------------------------------------------------------------
    // Init
    // -------------------------------------------------------------------------

    public function init(): void {
        $this->wire->set('announcementBar', $this);

        $themePath = __DIR__ . '/themes/';
        foreach (['ThemeBase', 'TailwindTheme', 'BootstrapTheme', 'UIkitTheme'] as $class) {
            $file = $themePath . $class . '.php';
            if (file_exists($file)) require_once $file;
        }
    }

    // -------------------------------------------------------------------------
    // Theme registry
    // -------------------------------------------------------------------------

    protected function getThemeRegistry(): array {
        return [
            'tailwind'  => TailwindTheme::class,
            'bootstrap' => BootstrapTheme::class,
            'uikit'     => UIkitTheme::class,
        ];
    }

    protected function resolveTheme(string $framework): ThemeBase {
        $registry = $this->getThemeRegistry();

        if (!array_key_exists($framework, $registry)) {
            throw new \InvalidArgumentException(
                "WireAnnouncementBar: unknown framework \"{$framework}\". " .
                'Available: ' . implode(', ', array_keys($registry)) . '.'
            );
        }

        $class = $registry[$framework];
        return new $class();
    }

    // -------------------------------------------------------------------------
    // Render
    // -------------------------------------------------------------------------

    /**
     * Render the announcement banner.
     *
     * @param array $options {
     *     @type int    $banner_enabled      1 to show, 0 to hide
     *     @type string $banner_title        Bold headline text
     *     @type string $banner_message      Supporting message text
     *     @type string $banner_type         Variant: warning | info | success | danger
     *     @type string $banner_framework    Theme: tailwind | bootstrap | uikit
     *     @type int    $banner_closeable    1 to show close button
     *     @type string $banner_link_url     Optional call-to-action URL
     *     @type string $banner_link_label   Link anchor text (default: "Learn more")
     *     @type int    $banner_link_newtab  1 to open link in a new tab
     *     @type string $banner_cookie_name  Cookie name for dismissed state
     *     @type int    $banner_cookie_days  Days to remember dismissal
     * }
     * @return string
     */
    public function render(array $options = []): string {

        $config = array_merge(
            self::getDefaultConfig(),
            [
                'banner_enabled'     => (int)    $this->banner_enabled,
                'banner_title'       => (string) $this->banner_title,
                'banner_message'     => (string) $this->banner_message,
                'banner_type'        => (string) $this->banner_type,
                'banner_framework'   => (string) $this->banner_framework,
                'banner_closeable'   => (int)    $this->banner_closeable,
                'banner_link_url'    => (string) $this->banner_link_url,
                'banner_link_label'  => (string) $this->banner_link_label,
                'banner_link_newtab' => (int)    $this->banner_link_newtab,
                'banner_cookie_name' => (string) $this->banner_cookie_name,
                'banner_cookie_days' => (int)    $this->banner_cookie_days,
            ],
            $options
        );

        if (empty($config['banner_enabled'])) return '';
        if (empty($config['banner_title']) && empty($config['banner_message'])) return '';

        $framework  = $config['banner_framework'];
        $type       = $config['banner_type'];
        $closeable  = !empty($config['banner_closeable']);
        $cookieName = $this->wire->sanitizer->name($config['banner_cookie_name']);
        $cookieDays = (int) $config['banner_cookie_days'];

        $theme    = $this->resolveTheme($framework);
        $variants = $theme->getVariants();
        $variant  = $variants[$type] ?? reset($variants);

        // Sanitize content
        $title   = $this->wire->sanitizer->entities($config['banner_title']);
        $message = $this->wire->sanitizer->entities($config['banner_message']);
        $linkUrl  = $this->wire->sanitizer->url($config['banner_link_url']);
        $linkLabel = $this->wire->sanitizer->entities(
            $config['banner_link_label'] ?: 'Learn more'
        );
        $linkNewTab = !empty($config['banner_link_newtab']);

        // Content blocks
        $titleHtml = $title
            ? "<span class=\"{$variant['title_color']}\">{$title}</span>"
            : '';

        $messageHtml = $message
            ? "<span class=\"{$variant['text_color']}\">{$message}</span>"
            : '';

        $spacer = ($title && $message) ? ' ' : '';

        // Link block
        $linkHtml = $linkUrl
            ? $theme->renderLink($linkUrl, $linkLabel, $variant, $linkNewTab)
            : '';

        // Alpine.js x-data
        $alpineData = $closeable
            ? "x-data=\"announcementBar('{$cookieName}', {$cookieDays})\""
            : 'x-data="{ bannerClosed: false }"';

        // Close button
        $closeButton = $closeable
            ? $theme->renderCloseButton($variant, 'closeBanner()')
            : '';

        $script = $this->renderScript();
        $banner = $theme->renderBanner(
            $variant,
            $titleHtml,
            $messageHtml,
            $linkHtml,
            $spacer,
            $alpineData,
            $closeButton
        );

        return $script . "\n" . '<!-- WireAnnouncementBar -->' . "\n" . $banner;
    }

    /**
     * Shorthand render for inline template usage.
     *
     * @param string $title
     * @param string $message
     * @param string $type
     * @param string $framework
     * @param string $linkUrl
     * @param string $linkLabel
     * @return string
     */
    public function show(
        string $title     = '',
        string $message   = '',
        string $type      = '',
        string $framework = '',
        string $linkUrl   = '',
        string $linkLabel = ''
    ): string {
        $options = [];
        if ($title)     $options['banner_title']      = $title;
        if ($message)   $options['banner_message']    = $message;
        if ($type)      $options['banner_type']       = $type;
        if ($framework) $options['banner_framework']  = $framework;
        if ($linkUrl)   $options['banner_link_url']   = $linkUrl;
        if ($linkLabel) $options['banner_link_label'] = $linkLabel;
        return $this->render($options);
    }

    // -------------------------------------------------------------------------
    // Alpine.js component script
    // -------------------------------------------------------------------------

    protected function renderScript(): string {
        static $rendered = false;
        if ($rendered) return '';
        $rendered = true;

        return <<<'HTML'
        <script>
            (function () {
                'use strict';

                if (typeof window.announcementBar !== 'undefined') return;

                /**
                 * Alpine.js data component for WireAnnouncementBar.
                 *
                 * @param {string} cookieName
                 * @param {number} cookieDays
                 * @returns {object}
                 */
                window.announcementBar = function (cookieName, cookieDays) {
                    return {
                        bannerClosed: false,

                        init() {
                            this.bannerClosed = this.getCookie(cookieName) === '1';
                        },

                        closeBanner() {
                            this.bannerClosed = true;
                            this.setCookie(cookieName, '1', cookieDays);
                        },

                        setCookie(name, value, days) {
                            const date = new Date();
                            date.setTime(date.getTime() + days * 24 * 60 * 60 * 1000);
                            document.cookie = [
                                name + '=' + encodeURIComponent(value),
                                'expires=' + date.toUTCString(),
                                'path=/',
                                'SameSite=Lax'
                            ].join('; ');
                        },

                        getCookie(name) {
                            const match = document.cookie.match(
                                new RegExp('(?:^|; )' + name.replace(/([.$?*|{}()[\]\\/+^])/g, '\\$1') + '=([^;]*)')
                            );
                            return match ? decodeURIComponent(match[1]) : null;
                        }
                    };
                };
            })();
        </script>
        HTML;
    }

    // -------------------------------------------------------------------------
    // Admin configuration fields
    // -------------------------------------------------------------------------

    public static function getModuleConfigInputfields(array $data): InputfieldWrapper {
        $modules  = wire('modules');
        $defaults = self::getDefaultConfig();
        $data     = array_merge($defaults, $data);

        /** @var InputfieldWrapper $fields */
        $fields = new InputfieldWrapper();

        // Enable
        /** @var InputfieldCheckbox $f */
        $f = $modules->get('InputfieldCheckbox');
        $f->attr('name', 'banner_enabled');
        $f->label       = 'Enable banner';
        $f->description = 'Uncheck to hide the banner site-wide without removing content.';
        $f->attr('checked', !empty($data['banner_enabled']) ? 'checked' : '');
        $f->columnWidth = 100;
        $fields->add($f);

        // Framework
        /** @var InputfieldSelect $f */
        $f = $modules->get('InputfieldSelect');
        $f->attr('name', 'banner_framework');
        $f->label = 'CSS framework';
        $f->addOptions([
            'tailwind'  => 'Tailwind CSS',
            'bootstrap' => 'Bootstrap 5',
            'uikit'     => 'UIkit 3',
        ]);
        $f->attr('value', $data['banner_framework']);
        $f->columnWidth = 50;
        $fields->add($f);

        // Type
        /** @var InputfieldSelect $f */
        $f = $modules->get('InputfieldSelect');
        $f->attr('name', 'banner_type');
        $f->label = 'Color variant';
        $f->addOptions([
            'warning' => '⚠️  Warning',
            'info'    => 'ℹ️  Info',
            'success' => '✅  Success',
            'danger'  => '🚨  Danger',
        ]);
        $f->attr('value', $data['banner_type']);
        $f->columnWidth = 50;
        $fields->add($f);

        // Title
        /** @var InputfieldText $f */
        $f = $modules->get('InputfieldText');
        $f->attr('name', 'banner_title');
        $f->label       = 'Banner title';
        $f->description = 'Short, bold headline displayed first.';
        $f->attr('value', $data['banner_title']);
        $f->columnWidth = 100;
        $fields->add($f);

        // Message
        /** @var InputfieldTextarea $f */
        $f = $modules->get('InputfieldTextarea');
        $f->attr('name', 'banner_message');
        $f->label       = 'Banner message';
        $f->description = 'Supporting text shown after the title.';
        $f->attr('value', $data['banner_message']);
        $f->rows        = 3;
        $f->columnWidth = 100;
        $fields->add($f);

        // ---- Link fieldset ----
        /** @var InputfieldFieldset $fs */
        $fs = $modules->get('InputfieldFieldset');
        $fs->label = 'Call-to-action link (optional)';

        // Link URL
        /** @var InputfieldURL $f */
        $f = $modules->get('InputfieldURL');
        $f->attr('name', 'banner_link_url');
        $f->label       = 'Link URL';
        $f->description = 'Leave empty to show no link.';
        $f->attr('value', $data['banner_link_url']);
        $f->columnWidth = 50;
        $fs->add($f);

        // Link label
        /** @var InputfieldText $f */
        $f = $modules->get('InputfieldText');
        $f->attr('name', 'banner_link_label');
        $f->label       = 'Link label';
        $f->description = 'Anchor text for the link.';
        $f->attr('value', $data['banner_link_label'] ?: 'Learn more');
        $f->columnWidth = 30;
        $fs->add($f);

        // New tab
        /** @var InputfieldCheckbox $f */
        $f = $modules->get('InputfieldCheckbox');
        $f->attr('name', 'banner_link_newtab');
        $f->label = 'Open in a new tab';
        $f->attr('checked', !empty($data['banner_link_newtab']) ? 'checked' : '');
        $f->columnWidth = 20;
        $fs->add($f);

        $fields->add($fs);

        // Closeable
        /** @var InputfieldCheckbox $f */
        $f = $modules->get('InputfieldCheckbox');
        $f->attr('name', 'banner_closeable');
        $f->label       = 'Allow visitors to dismiss the banner';
        $f->description = 'Displays a close (×) button. The dismissed state is stored in a cookie.';
        $f->attr('checked', !empty($data['banner_closeable']) ? 'checked' : '');
        $f->columnWidth = 50;
        $fields->add($f);

        // Cookie days
        /** @var InputfieldInteger $f */
        $f = $modules->get('InputfieldInteger');
        $f->attr('name', 'banner_cookie_days');
        $f->label       = 'Dismissal cookie lifetime (days)';
        $f->description = 'How many days to remember that a visitor closed the banner.';
        $f->attr('value', $data['banner_cookie_days']);
        $f->columnWidth = 50;
        $fields->add($f);

        // Cookie name
        /** @var InputfieldText $f */
        $f = $modules->get('InputfieldText');
        $f->attr('name', 'banner_cookie_name');
        $f->label       = 'Cookie name';
        $f->description = 'Change after updating banner content so it reappears for all visitors.';
        $f->attr('value', $data['banner_cookie_name']);
        $f->columnWidth = 100;
        $fields->add($f);

        return $fields;
    }
}
