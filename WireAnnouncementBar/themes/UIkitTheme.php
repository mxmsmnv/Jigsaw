<?php namespace ProcessWire;

/**
 * UIkitTheme
 *
 * WireAnnouncementBar theme built with UIkit 3 classes.
 *
 * @author  Maxim Semenov <maxim@smnv.org> (smnv.org)
 * @license MIT
 */
class UIkitTheme extends ThemeBase {

    public function getVariants(): array {
        return [
            'warning' => [
                'wrapper'     => 'uk-alert uk-alert-warning uk-margin-remove',
                'icon_color'  => '',
                'title_color' => 'uk-text-bold',
                'text_color'  => '',
                'link_color'  => 'uk-link uk-text-bold',
                'btn_color'   => 'uk-alert-close',
            ],
            'info' => [
                'wrapper'     => 'uk-alert uk-alert-primary uk-margin-remove',
                'icon_color'  => '',
                'title_color' => 'uk-text-bold',
                'text_color'  => '',
                'link_color'  => 'uk-link uk-text-bold',
                'btn_color'   => 'uk-alert-close',
            ],
            'success' => [
                'wrapper'     => 'uk-alert uk-alert-success uk-margin-remove',
                'icon_color'  => '',
                'title_color' => 'uk-text-bold',
                'text_color'  => '',
                'link_color'  => 'uk-link uk-text-bold',
                'btn_color'   => 'uk-alert-close',
            ],
            'danger' => [
                'wrapper'     => 'uk-alert uk-alert-danger uk-margin-remove',
                'icon_color'  => '',
                'title_color' => 'uk-text-bold',
                'text_color'  => '',
                'link_color'  => 'uk-link uk-text-bold',
                'btn_color'   => 'uk-alert-close',
            ],
        ];
    }

    public function renderBanner(
        array  $variant,
        string $titleHtml,
        string $messageHtml,
        string $linkHtml,
        string $spacer,
        string $alpineData,
        string $closeButton
    ): string {
        $this->validateVariant($variant);

        $icon = $this->renderIcon('');

        return <<<HTML
        <div
            {$alpineData}
            x-show="!bannerClosed"
            x-cloak
            x-transition:enter="transition ease-out duration-300"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            x-transition:leave="transition ease-in duration-200"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            class="{$variant['wrapper']}"
            role="alert"
            aria-live="polite"
        >
            <div class="uk-container uk-flex uk-flex-middle uk-flex-between uk-flex-wrap" style="gap:.75rem;">
                <div class="uk-flex uk-flex-middle" style="gap:.5rem;flex:1;min-width:0;">
                    {$icon}
                    <p class="uk-margin-remove uk-text-small">
                        {$titleHtml}
                        {$spacer}
                        {$messageHtml}
                    </p>
                    {$linkHtml}
                </div>
                {$closeButton}
            </div>
        </div>
        HTML;
    }

    public function renderCloseButton(array $variant, string $action): string {
        return <<<HTML
        <button
            @click="{$action}"
            type="button"
            class="{$variant['btn_color']}"
            aria-label="Close banner"
            uk-close
        ></button>
        HTML;
    }

    public function renderLink(string $url, string $label, array $variant, bool $newTab): string {
        $target = $newTab ? 'target="_blank" rel="noopener noreferrer"' : '';
        return <<<HTML
        <a
            href="{$url}"
            class="uk-text-small uk-text-nowrap {$variant['link_color']}"
            {$target}
        >{$label}</a>
        HTML;
    }

    public function renderIcon(string $type): string {
        return '<span uk-icon="warning" class="uk-flex-none" aria-hidden="true"></span>';
    }
}
