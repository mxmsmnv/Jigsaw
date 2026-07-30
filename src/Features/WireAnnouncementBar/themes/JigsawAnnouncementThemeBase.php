<?php namespace ProcessWire;

/**
 * JigsawAnnouncementThemeBase
 *
 * Abstract base class for WireAnnouncementBar themes.
 * All themes must extend this class and implement the required methods.
 *
 * @author  Maxim Semenov <maxim@smnv.org> (smnv.org)
 * @license MIT
 */
abstract class JigsawAnnouncementThemeBase {

    /**
     * Return all available color variants for this theme.
     *
     * @return array<string, array<string, string>>
     */
    abstract public function getVariants(): array;

    /**
     * Render the complete banner HTML.
     *
     * @param array  $variant      Resolved variant array from getVariants()
     * @param string $titleHtml    Sanitized title markup
     * @param string $messageHtml  Sanitized message markup
     * @param string $linkHtml     Call-to-action link markup or empty string
     * @param string $spacer       Spacer between title and message
     * @param string $alpineData   Alpine.js x-data attribute string
     * @param string $closeButton  Close button HTML or empty string
     * @return string
     */
    abstract public function renderBanner(
        array  $variant,
        string $titleHtml,
        string $messageHtml,
        string $linkHtml,
        string $spacer,
        string $alpineData,
        string $closeButton
    ): string;

    /**
     * Render the close button for this theme.
     *
     * @param array  $variant  Resolved variant array
     * @param string $action   Alpine.js click action, e.g. "closeBanner()"
     * @return string
     */
    abstract public function renderCloseButton(array $variant, string $action): string;

    /**
     * Render the call-to-action link for this theme.
     *
     * @param string $url     Link href
     * @param string $label   Link text
     * @param array  $variant Resolved variant array
     * @param bool   $newTab  Open in a new tab
     * @return string
     */
    abstract public function renderLink(string $url, string $label, array $variant, bool $newTab): string;

    /**
     * Return the icon markup. Use {{COLOR}} as a color class placeholder.
     *
     * @param string $type Variant key: warning | info | success | danger
     * @return string
     */
    abstract public function renderIcon(string $type): string;

    /**
     * Keys that every variant array must contain.
     *
     * @return string[]
     */
    public function getRequiredKeys(): array {
        return ['icon_color', 'title_color', 'text_color', 'link_color'];
    }

    /**
     * Validate that a variant contains all required keys.
     *
     * @param  array $variant
     * @throws \InvalidArgumentException
     */
    protected function validateVariant(array $variant): void {
        foreach ($this->getRequiredKeys() as $key) {
            if (!array_key_exists($key, $variant)) {
                throw new \InvalidArgumentException(
                    static::class . ": variant is missing required key \"{$key}\"."
                );
            }
        }
    }
}
