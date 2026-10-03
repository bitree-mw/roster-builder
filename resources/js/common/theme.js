/**
 * Light / dark theme switch (top bar button). The choice is stored in a "theme" cookie so the server can
 * render <html data-theme> straight away (no flash on the next page); without a choice the system setting
 * applies. Colours themselves live in resources/css/common/tokens.css as light-dark() pairs.
 */

/** The theme currently shown: the saved choice, or the system preference. */
function currentTheme() {
    const saved = document.documentElement.dataset.theme;
    if (saved === 'light' || saved === 'dark') return saved;
    return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
}

/** Show the icon and label for the theme the button will switch to. */
function render(button) {
    const theme = currentTheme();
    const next = theme === 'dark' ? 'light' : 'dark';
    button.setAttribute('aria-label', `Switch to ${next} theme`);
    button.title = `Switch to ${next} theme`;
    for (const icon of button.querySelectorAll('[data-theme-icon]')) icon.toggleAttribute('hidden', icon.dataset.themeIcon !== (next === 'dark' ? 'moon' : 'sun'));
}

/** Wire the theme button; follows system changes until the user picks a theme. */
export function initThemeToggle() {
    const button = document.querySelector('#theme-toggle');
    if (!button) return;
    render(button);
    button.addEventListener('click', () => {
        const next = currentTheme() === 'dark' ? 'light' : 'dark';
        document.documentElement.dataset.theme = next;
        const secure = window.location.protocol === 'https:' ? '; Secure' : '';
        document.cookie = `theme=${next}; path=/; max-age=31536000; SameSite=Lax${secure}`;
        render(button);
    });
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => render(button));
}
