<?php namespace ProcessWire;

/**
 * TailwindTheme
 *
 * WireAnnouncementBar theme built with Tailwind CSS utility classes.
 * Requires Alpine.js for open/close transitions.
 *
 * @author  Maxim Semenov <maxim@smnv.org> (smnv.org)
 * @license MIT
 */
class TailwindTheme extends ThemeBase {

    public function getVariants(): array {
        return [
            'warning' => [
                'wrapper'     => 'bg-yellow-50 border-t border-yellow-200',
                'icon_color'  => 'text-yellow-700',
                'title_color' => 'text-yellow-900',
                'text_color'  => 'text-yellow-800',
                'link_color'  => 'text-yellow-900 underline font-semibold hover:text-yellow-700',
                'btn_color'   => 'text-yellow-700 hover:text-yellow-900',
            ],
            'info' => [
                'wrapper'     => 'bg-blue-50 border-t border-blue-200',
                'icon_color'  => 'text-blue-700',
                'title_color' => 'text-blue-900',
                'text_color'  => 'text-blue-800',
                'link_color'  => 'text-blue-900 underline font-semibold hover:text-blue-700',
                'btn_color'   => 'text-blue-700 hover:text-blue-900',
            ],
            'success' => [
                'wrapper'     => 'bg-green-50 border-t border-green-200',
                'icon_color'  => 'text-green-700',
                'title_color' => 'text-green-900',
                'text_color'  => 'text-green-800',
                'link_color'  => 'text-green-900 underline font-semibold hover:text-green-700',
                'btn_color'   => 'text-green-700 hover:text-green-900',
            ],
            'danger' => [
                'wrapper'     => 'bg-red-50 border-t border-red-200',
                'icon_color'  => 'text-red-700',
                'title_color' => 'text-red-900',
                'text_color'  => 'text-red-800',
                'link_color'  => 'text-red-900 underline font-semibold hover:text-red-700',
                'btn_color'   => 'text-red-700 hover:text-red-900',
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
            x-transition:enter-start="opacity-0 -translate-y-2"
            x-transition:enter-end="opacity-100 translate-y-0"
            x-transition:leave="transition ease-in duration-200"
            x-transition:leave-start="opacity-100 translate-y-0"
            x-transition:leave-end="opacity-0 -translate-y-2"
            class="{$variant['wrapper']}"
            role="alert"
            aria-live="polite"
        >
            <div class="mx-auto max-w-screen-xl px-3 sm:px-6 py-3 flex items-center justify-between gap-2">
                <div class="flex-1 flex items-center gap-2 sm:gap-3 min-w-0">
                    {$icon}
                    <div class="flex-1 min-w-0 text-left sm:text-center">
                        <p class="text-sm md:text-base leading-tight">
                            {$titleHtml}
                            {$spacer}
                            {$messageHtml}
                        </p>
                    </div>
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
            class="flex-shrink-0 p-1 transition {$variant['btn_color']}"
            aria-label="Close banner"
        >
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M6 18L18 6M6 6l12 12" />
            </svg>
        </button>
        HTML;
    }

    public function renderLink(string $url, string $label, array $variant, bool $newTab): string {
        $target = $newTab ? 'target="_blank" rel="noopener noreferrer"' : '';
        return <<<HTML
        <a
            href="{$url}"
            class="flex-shrink-0 text-sm md:text-base whitespace-nowrap {$variant['link_color']}"
            {$target}
        >{$label}</a>
        HTML;
    }

    public function renderIcon(string $type): string {
        return '<svg class="w-5 h-5 md:w-6 md:h-6 flex-shrink-0 {{COLOR}}" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
                    <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                </svg>';
    }
}
