<?php namespace ProcessWire;

/**
 * AdminBar - Frontend admin bar for ProcessWire
 *
 * Displays a fixed top bar for logged-in users with quick access
 * to edit the current page and navigate admin sections.
 *
 * @author Maxim Semenov <maxim@smnv.org> (smnv.org)
 * @copyright 2025 smnv.org
 * @license MIT
 * @version 1.2.0
 */
class JigsawAdminBarFeature extends WireData
{
    public static function getFeatureInfo(): array
    {
        return [
            'title'    => 'Admin Bar',
            'summary'  => 'Frontend admin bar for logged-in users: edit, page info, admin links.',
            'version'  => '1.2.0',
            'author'   => 'Maxim Semenov',
            'href'     => 'https://smnv.org',
            'singular' => true,
            'autoload' => true,
            'icon'     => 'bars',
        ];
    }

    public static function getDefaultData(): array
    {
        return [
            'show_for_roles'   => [],
            'show_edit_button' => 1,
            'show_admin_links' => 1,
            'show_page_info'   => 1,
            'placement'        => 'top',
            'position'         => 'fixed',
            'custom_css'       => '',
        ];
    }

    public function init(): void
    {
        $this->addHookAfter('Page::render', $this, 'hookRender');
    }

    // -------------------------------------------------------------------------
    // Render hook
    // -------------------------------------------------------------------------

    public function hookRender(HookEvent $event): void
    {
        $page = $event->object;
        if ($page->template == 'admin') return;

        $user = $this->wire('user');
        if (!$user->isLoggedIn()) return;

        $allowedRoles = $this->get('show_for_roles');
        if (empty($allowedRoles)) return;

        $hasRole = false;
        foreach ($allowedRoles as $roleName) {
            if ($user->hasRole($roleName)) { $hasRole = true; break; }
        }
        if (!$hasRole) return;

        $bar = $this->renderBar($page, $user);
        $event->return = str_replace('</body>', $bar . '</body>', $event->return);
    }

    // -------------------------------------------------------------------------
    // Bar HTML
    // -------------------------------------------------------------------------

    protected function renderBar(Page $page, User $user): string
    {
        $san       = $this->wire('sanitizer');
        $adminUrl  = $this->wire('config')->urls->admin;
        $editUrl   = $adminUrl . 'page/edit/?id=' . $page->id;
        $userUrl   = $adminUrl . 'profile/';
        $logoutUrl = $adminUrl . 'login/logout/?s=' . session_id();

        // Avatar
        $avatar = '';
        if ($user->hasField('profile_photo') && $user->profile_photo->count()) {
            $img    = $user->profile_photo->first()->size(64, 64);
            $avatar = '<img class="admin-bar__avatar" src="' . $img->url . '" alt="' . $san->entities($user->name) . '">';
        }

        // User info
        $userName = $san->entities($user->name);
        $roleName = '';
        foreach ($user->roles as $role) {
            if ($role->name !== 'guest') { $roleName = $san->entities($role->name); break; }
        }

        // Edit button
        $editButton = '';
        if ($this->get('show_edit_button') && $user->hasPermission('page-edit', $page)) {
            $editButton = '<a href="' . $editUrl . '" class="admin-bar__link admin-bar__link--highlight">'
                . $this->svgIcon('edit') . '<span>Edit</span></a>';
        }

        // Admin section links
        $adminLinks    = '';
        $dropdownLinks = '';
        if ($this->get('show_admin_links')) {
            foreach ($this->getAdminSections($adminUrl) as $section) {
                $label = $san->entities($section['label']);
                $adminLinks   .= '<a href="' . $section['url'] . '" class="admin-bar__link">'
                    . $this->svgIcon($section['icon']) . '<span>' . $label . '</span></a>';
                $dropdownLinks .= '<a href="' . $section['url'] . '" class="admin-bar__dropdown-link">'
                    . $this->svgIcon($section['icon']) . $label . '</a>';
            }
        }

        // Page info strip
        $pageInfo = '';
        if ($this->get('show_page_info')) {
            $isPublished = !($page->status & Page::statusHidden) && !($page->status & Page::statusUnpublished);
            $status      = $isPublished ? 'Published' : 'Hidden';
            $statusCls   = $isPublished ? 'admin-bar__badge--green' : 'admin-bar__badge--yellow';
            $modified    = date('Y-m-d H:i', $page->modified);
            $created     = date('Y-m-d', $page->created);
            $children    = $page->numChildren;
            $template    = $san->entities($page->template->name);
            $parentTitle = $san->entities($page->parent->title);

            $pageInfo = <<<HTML
<div class="admin-bar__info">
    <span class="admin-bar__badge $statusCls">$status</span>
    <span class="admin-bar__info-item" title="Template: $template">$template</span>
    <span class="admin-bar__info-item" title="Parent: $parentTitle">$parentTitle</span>
    <span class="admin-bar__info-item" title="Child pages">{$this->svgIcon('document')} $children</span>
    <span class="admin-bar__info-item" title="Modified: $modified | Created: $created">{$this->svgIcon('clock')} $modified</span>
</div>
HTML;
        }

        $css       = $this->getCSS();
        $position  = $this->get('position')  ?: 'fixed';
        $placement = $this->get('placement') ?: 'top';

        return <<<HTML
<style>$css</style>
<div class="admin-bar admin-bar--{$placement}" style="--admin-bar--position:{$position}">
    <div class="admin-bar__links">
        {$editButton}
        {$adminLinks}
    </div>
    {$pageInfo}
    <div class="admin-bar__user" tabindex="0">
        {$avatar}
        <div class="admin-bar__user-name">
            {$userName}
            <div class="admin-bar__user-role">{$roleName}</div>
        </div>
        {$this->svgIcon('angle-down')}
        <div class="admin-bar__dropdown">
            <a class="admin-bar__dropdown-link" href="{$userUrl}">
                {$this->svgIcon('user')} My Profile
            </a>
            {$dropdownLinks}
            <a class="admin-bar__dropdown-link" href="{$logoutUrl}">
                {$this->svgIcon('logout')} Logout
            </a>
        </div>
    </div>
</div>
HTML;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    protected function getAdminSections(string $adminUrl): array
    {
        $user = $this->wire('user');
        $candidates = [
            ['label' => 'Pages',   'url' => $adminUrl . 'page/',   'icon' => 'sitemap', 'perm' => 'page-edit'],
            ['label' => 'Access',  'url' => $adminUrl . 'access/', 'icon' => 'users',   'perm' => 'user-edit'],
            ['label' => 'Modules', 'url' => $adminUrl . 'module/', 'icon' => 'plug',    'perm' => 'module-admin'],
            ['label' => 'Setup',   'url' => $adminUrl . 'setup/',  'icon' => 'cog',     'perm' => 'template-admin'],
        ];
        return array_values(array_filter($candidates, fn($s) => $user->hasPermission($s['perm'])));
    }

    // -------------------------------------------------------------------------
    // Icons (Heroicons 2.x outline)
    // -------------------------------------------------------------------------

    protected function svgIcon(string $name): string
    {
        $icons = [
            'edit'       => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10"/></svg>',
            'user'       => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z"/></svg>',
            'logout'     => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15m3 0 3-3m0 0-3-3m3 3H9"/></svg>',
            'angle-down' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5"/></svg>',
            'sitemap'    => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 7.125C2.25 6.504 2.754 6 3.375 6h6c.621 0 1.125.504 1.125 1.125v3.75c0 .621-.504 1.125-1.125 1.125h-6a1.125 1.125 0 0 1-1.125-1.125v-3.75ZM14.25 8.625c0-.621.504-1.125 1.125-1.125h5.25c.621 0 1.125.504 1.125 1.125v8.25c0 .621-.504 1.125-1.125 1.125h-5.25a1.125 1.125 0 0 1-1.125-1.125v-8.25ZM3.75 16.125c0-.621.504-1.125 1.125-1.125h5.25c.621 0 1.125.504 1.125 1.125v2.25c0 .621-.504 1.125-1.125 1.125h-5.25a1.125 1.125 0 0 1-1.125-1.125v-2.25Z"/></svg>',
            'users'      => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z"/></svg>',
            'plug'       => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M14.25 6.087c0-.355.186-.676.401-.959.221-.29.349-.634.349-1.003 0-1.036-1.007-1.875-2.25-1.875s-2.25.84-2.25 1.875c0 .369.128.713.349 1.003.215.283.401.604.401.959v0a.64.64 0 0 1-.657.643 48.39 48.39 0 0 1-4.163-.3c.186 1.613.293 3.25.315 4.907a.656.656 0 0 1-.658.663v0c-.355 0-.676-.186-.959-.401a1.647 1.647 0 0 0-1.003-.349c-1.036 0-1.875 1.007-1.875 2.25s.84 2.25 1.875 2.25c.369 0 .713-.128 1.003-.349.283-.215.604-.401.959-.401v0c.31 0 .555.26.532.57a48.039 48.039 0 0 1-.642 5.056c1.518.19 3.058.309 4.616.354a.64.64 0 0 0 .657-.643v0c0-.355-.186-.676-.401-.959a1.647 1.647 0 0 1-.349-1.003c0-1.035 1.008-1.875 2.25-1.875 1.243 0 2.25.84 2.25 1.875 0 .369-.128.713-.349 1.003-.215.283-.4.604-.4.959v0c0 .333.277.599.61.58a48.1 48.1 0 0 0 5.427-.63 48.05 48.05 0 0 0 .582-4.717.532.532 0 0 0-.533-.57v0c-.355 0-.676.186-.959.401-.29.221-.634.349-1.003.349-1.035 0-1.875-1.007-1.875-2.25s.84-2.25 1.875-2.25c.37 0 .713.128 1.003.349.283.215.604.401.96.401v0a.656.656 0 0 0 .658-.663 48.422 48.422 0 0 0-.37-5.36c-1.886.342-3.81.574-5.766.689a.578.578 0 0 1-.61-.58v0Z"/></svg>',
            'cog'        => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/></svg>',
            'document'   => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z"/></svg>',
            'clock'      => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>',
        ];

        return $icons[$name]
            ?? '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 5.25h.008v.008H12v-.008Z"/></svg>';
    }

    // -------------------------------------------------------------------------
    // CSS
    // -------------------------------------------------------------------------

    protected function getCSS(): string
    {
        $custom = trim((string) $this->get('custom_css'));

        $css = <<<CSS
:root {
  --admin-bar--font-family: -apple-system, "system-ui", "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
  --admin-bar--base-font-size: 13px;
  --admin-bar--base-line-height: 1.2;
  --admin-bar--height: 44px;
  --admin-bar--z-index: 9999;
  --admin-bar--avatar-size: 28px;
  --admin-bar--border-radius: 4px;
  --admin-bar--position: fixed;
  --admin-bar--padding: 0 12px;
  --admin-bar--icon-size: 16px;
  --admin-bar--dropdown-border-radius: 4px;
}
.admin-bar {
  --admin-bar--color: var(--pw-text-color, #2b2b2b);
  --admin-bar--color--hover: var(--pw-main-color, #eb1d61);
  --admin-bar--background-color: var(--pw-blocks-background, #fff);
  --admin-bar--border-color: var(--pw-border-color, #ddd);
  --admin-bar--link-background-color--hover: var(--pw-button-hover-background, #c4174f);
}
.admin-bar {
  display: flex;
  align-items: center;
  font-family: var(--admin-bar--font-family);
  font-size: var(--admin-bar--base-font-size);
  line-height: var(--admin-bar--base-line-height);
  position: var(--admin-bar--position);
  top: 0; left: 0;
  width: 100%;
  min-height: var(--admin-bar--height);
  color: var(--admin-bar--color);
  background: var(--admin-bar--background-color);
  z-index: var(--admin-bar--z-index);
  padding: var(--admin-bar--padding);
  box-sizing: border-box;
  border-bottom: 1px solid var(--admin-bar--border-color);
  gap: 8px;
}
.admin-bar--bottom {
  top: auto;
  bottom: 0;
  border-bottom: none;
  border-top: 1px solid var(--admin-bar--border-color);
}
.admin-bar a { text-decoration: none; color: inherit; }
.admin-bar a:hover { color: var(--admin-bar--color--hover); }
.admin-bar__avatar {
  width: var(--admin-bar--avatar-size);
  height: var(--admin-bar--avatar-size);
  border-radius: var(--admin-bar--border-radius);
  object-fit: cover;
  flex-shrink: 0;
}
.admin-bar__links {
  display: flex;
  align-items: center;
  gap: 4px;
  flex-shrink: 0;
}
.admin-bar__link {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 5px 9px;
  border-radius: var(--admin-bar--border-radius);
  white-space: nowrap;
  background: none;
  border: none;
  cursor: pointer;
  font-family: inherit;
  font-size: inherit;
  color: inherit;
  line-height: inherit;
}
.admin-bar__link svg {
  width: var(--admin-bar--icon-size);
  height: var(--admin-bar--icon-size);
  stroke: currentColor;
  fill: none;
  flex-shrink: 0;
}
.admin-bar__link:hover { background: var(--pw-main-background, #eee); color: var(--admin-bar--color); }
.admin-bar__link--highlight {
  color: var(--pw-main-color, #eb1d61);
  background-color: transparent;
  box-shadow: inset 0 0 0 1.5px var(--pw-main-color, #eb1d61);
  font-weight: 600;
}
.admin-bar__link--highlight:hover {
  color: var(--pw-blocks-background, #fff) !important;
  background-color: var(--pw-main-color, #eb1d61);
}
.admin-bar__info {
  flex: 1;
  display: flex;
  align-items: center;
  gap: 10px;
  overflow: hidden;
  font-size: 0.85em;
  color: var(--pw-muted-color, #888);
  padding: 0 4px;
  min-width: 0;
}
.admin-bar__info-item {
  display: inline-flex;
  align-items: center;
  gap: 3px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.admin-bar__info-item svg {
  width: 13px;
  height: 13px;
  stroke: currentColor;
  fill: none;
  flex-shrink: 0;
}
.admin-bar__badge {
  display: inline-flex;
  align-items: center;
  padding: 1px 7px;
  border-radius: 99px;
  font-size: 0.8em;
  font-weight: 600;
  white-space: nowrap;
}
.admin-bar__badge--green  { background: var(--pw-alert-success, #c1e7cd); color: #15683a; }
.admin-bar__badge--yellow { background: var(--pw-alert-warning, #fff0be); color: #7a5c00; }
.admin-bar__user {
  position: relative;
  display: flex;
  align-items: center;
  gap: 6px;
  align-self: stretch;
  cursor: pointer;
  padding: 0 6px;
  flex-shrink: 0;
}
.admin-bar__user svg {
  width: var(--admin-bar--icon-size);
  height: var(--admin-bar--icon-size);
  stroke: currentColor;
  fill: none;
  flex-shrink: 0;
}
.admin-bar__user-name { font-weight: 500; white-space: nowrap; }
.admin-bar__user-role { font-size: 0.8em; opacity: 0.65; font-weight: 400; }
.admin-bar--bottom .admin-bar__user > svg:last-of-type { transform: rotate(180deg); }
.admin-bar__dropdown {
  display: none;
  flex-direction: column;
  position: absolute;
  right: 0; top: 100%;
  min-width: 160px;
  border: 1px solid var(--admin-bar--border-color);
  border-top: 0;
  border-radius: 0 0 var(--admin-bar--dropdown-border-radius) var(--admin-bar--dropdown-border-radius);
  background: var(--admin-bar--background-color);
  z-index: 1;
}
.admin-bar--bottom .admin-bar__dropdown {
  top: auto;
  bottom: 100%;
  border-top: 1px solid var(--admin-bar--border-color);
  border-bottom: 0;
  border-radius: var(--admin-bar--dropdown-border-radius) var(--admin-bar--dropdown-border-radius) 0 0;
}
.admin-bar__dropdown-link {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 8px 12px;
  white-space: nowrap;
  color: inherit;
  text-decoration: none;
}
.admin-bar__dropdown-link svg {
  width: var(--admin-bar--icon-size);
  height: var(--admin-bar--icon-size);
  stroke: currentColor;
  fill: none;
  flex-shrink: 0;
}
.admin-bar__dropdown-link:hover { background: var(--pw-main-background, #eee); }
.admin-bar__user:hover .admin-bar__dropdown,
.admin-bar__user:focus .admin-bar__dropdown,
.admin-bar__user:focus-within .admin-bar__dropdown { display: flex; }
@media (max-width: 640px) {
  .admin-bar__info { display: none; }
  .admin-bar__link span { display: none; }
}
CSS;

        if ($custom) $css .= "\n/* Custom */\n" . $custom;
        return $css;
    }

    // -------------------------------------------------------------------------
    // Module config
    // -------------------------------------------------------------------------

    public static function getConfigInputfields(array $data): \ProcessWire\InputfieldWrapper
    {
        $modules = wire('modules');
        $wrapper = new InputfieldWrapper();
        $data    = array_merge(self::getDefaultData(), $data);

        // --- Column left: toggles ---
        /** @var InputfieldFieldset $col1 */
        $col1 = $modules->get('InputfieldFieldset');
        $col1->label = 'Display';
        $col1->columnWidth = 50;

        $f = $modules->get('InputfieldCheckbox');
        $f->attr('name', 'show_edit_button');
        $f->label = 'Show "Edit" button';
        $f->attr('checked', !empty($data['show_edit_button']));
        $col1->add($f);

        $f = $modules->get('InputfieldCheckbox');
        $f->attr('name', 'show_admin_links');
        $f->label = 'Show admin section links';
        $f->notes = 'Pages, Access, Modules, Setup';
        $f->attr('checked', !empty($data['show_admin_links']));
        $col1->add($f);

        $f = $modules->get('InputfieldCheckbox');
        $f->attr('name', 'show_page_info');
        $f->label = 'Show page info';
        $f->notes = 'Status, template, parent, children, modified';
        $f->attr('checked', !empty($data['show_page_info']));
        $col1->add($f);

        $wrapper->add($col1);

        // --- Column right: position & roles ---
        /** @var InputfieldFieldset $col2 */
        $col2 = $modules->get('InputfieldFieldset');
        $col2->label = 'Position';
        $col2->columnWidth = 50;

        $f = $modules->get('InputfieldSelect');
        $f->attr('name', 'placement');
        $f->label = 'Placement';
        $f->addOption('top',    'Top');
        $f->addOption('bottom', 'Bottom');
        $f->attr('value', $data['placement'] ?: 'top');
        $col2->add($f);

        $f = $modules->get('InputfieldSelect');
        $f->attr('name', 'position');
        $f->label = 'CSS position';
        $f->addOption('fixed',    'Fixed');
        $f->addOption('absolute', 'Absolute');
        $f->addOption('sticky',   'Sticky');
        $f->attr('value', $data['position'] ?: 'fixed');
        $col2->add($f);

        $wrapper->add($col2);

        // --- Full width: roles ---
        $roles   = wire('roles')->find("name!=guest");
        $options = [];
        foreach ($roles as $role) $options[$role->name] = $role->name;
        $f = $modules->get('InputfieldCheckboxes');
        $f->attr('name', 'show_for_roles');
        $f->label = 'Show bar only for these roles';
        $f->description = 'Required. Bar is hidden for everyone until at least one role is selected.';
        $f->addOptions($options);
        $f->attr('value', $data['show_for_roles'] ?: []);
        $f->optionColumns = 3;
        $wrapper->add($f);

        // --- Full width: custom CSS ---
        $f = $modules->get('InputfieldTextarea');
        $f->attr('name', 'custom_css');
        $f->label = 'Custom CSS';
        $f->description = 'Override variables or add rules. Example: :root { --admin-bar--height: 40px; }';
        $f->attr('rows', 5);
        $f->attr('value', $data['custom_css'] ?: '');
        $f->collapsed = Inputfield::collapsedBlank;
        $wrapper->add($f);

        return $wrapper;
    }
}
