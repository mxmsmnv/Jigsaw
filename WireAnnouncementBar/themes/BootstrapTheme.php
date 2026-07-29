<?php namespace ProcessWire;

/**
 * BootstrapTheme
 *
 * WireAnnouncementBar theme built with Bootstrap 5 classes.
 *
 * @author  Maxim Semenov <maxim@smnv.org> (smnv.org)
 * @license MIT
 */
class BootstrapTheme extends ThemeBase {

    public function getVariants(): array {
        return [
            'warning' => [
                'wrapper'     => 'alert alert-warning border-0 border-top border-warning rounded-0 mb-0 py-2',
                'icon_color'  => 'text-warning',
                'title_color' => 'text-warning-emphasis fw-semibold',
                'text_color'  => 'text-warning-emphasis',
                'link_color'  => 'alert-link text-warning-emphasis',
                'btn_color'   => 'btn-close',
            ],
            'info' => [
                'wrapper'     => 'alert alert-info border-0 border-top border-info rounded-0 mb-0 py-2',
                'icon_color'  => 'text-info',
                'title_color' => 'text-info-emphasis fw-semibold',
                'text_color'  => 'text-info-emphasis',
                'link_color'  => 'alert-link text-info-emphasis',
                'btn_color'   => 'btn-close',
            ],
            'success' => [
                'wrapper'     => 'alert alert-success border-0 border-top border-success rounded-0 mb-0 py-2',
                'icon_color'  => 'text-success',
                'title_color' => 'text-success-emphasis fw-semibold',
                'text_color'  => 'text-success-emphasis',
                'link_color'  => 'alert-link text-success-emphasis',
                'btn_color'   => 'btn-close',
            ],
            'danger' => [
                'wrapper'     => 'alert alert-danger border-0 border-top border-danger rounded-0 mb-0 py-2',
                'icon_color'  => 'text-danger',
                'title_color' => 'text-danger-emphasis fw-semibold',
                'text_color'  => 'text-danger-emphasis',
                'link_color'  => 'alert-link text-danger-emphasis',
                'btn_color'   => 'btn-close',
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

        $icon = str_replace('{{COLOR}}', $variant['icon_color'], $this->renderIcon(''));

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
            <div class="container-xl d-flex align-items-center justify-content-between gap-2 flex-wrap">
                <div class="d-flex align-items-center gap-2 flex-grow-1">
                    {$icon}
                    <p class="mb-0 small">
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
            class="flex-shrink-0 {$variant['btn_color']}"
            aria-label="Close banner"
        ></button>
        HTML;
    }

    public function renderLink(string $url, string $label, array $variant, bool $newTab): string {
        $target = $newTab ? 'target="_blank" rel="noopener noreferrer"' : '';
        return <<<HTML
        <a
            href="{$url}"
            class="small text-nowrap fw-semibold {$variant['link_color']}"
            {$target}
        >{$label}</a>
        HTML;
    }

    public function renderIcon(string $type): string {
        return '<svg class="flex-shrink-0 {{COLOR}}" style="width:1.25rem;height:1.25rem;" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
                    <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                </svg>';
    }
}
