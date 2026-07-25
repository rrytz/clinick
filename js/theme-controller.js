/**
 * CLINICK Global Theme Controller
 * Handles dark/light theme switching, system preferences, localStorage persistence, and FOUC prevention.
 */
(function() {
    function getPreferredTheme() {
        const saved = localStorage.getItem('clinick-theme');
        if (saved) return saved;
        return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    }

    function applyTheme(theme) {
        if (theme === 'dark') {
            document.documentElement.setAttribute('data-theme', 'dark');
        } else {
            document.documentElement.removeAttribute('data-theme');
        }
    }

    // Apply immediately on load to prevent Flash of Unstyled Content (FOUC)
    applyTheme(getPreferredTheme());

    window.ClinickTheme = {
        get: getPreferredTheme,
        set: function(theme) {
            localStorage.setItem('clinick-theme', theme);
            applyTheme(theme);
            this.updateToggleButtons();
        },
        toggle: function() {
            const current = document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
            const next = current === 'dark' ? 'light' : 'dark';
            this.set(next);
        },
        updateToggleButtons: function() {
            const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
            document.querySelectorAll('.theme-toggle-btn, .theme-toggle').forEach(btn => {
                const icon = btn.querySelector('i');
                if (icon) {
                    icon.className = isDark ? 'fa-solid fa-sun' : 'fa-solid fa-moon';
                } else {
                    btn.innerHTML = isDark ? '<i class="fa-solid fa-sun"></i>' : '<i class="fa-solid fa-moon"></i>';
                }
                btn.setAttribute('title', isDark ? 'Switch to Light Mode' : 'Switch to Dark Mode');
            });
        }
    };

    // Listen for system preference changes if user has no saved preference
    if (window.matchMedia) {
        window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function(e) {
            if (!localStorage.getItem('clinick-theme')) {
                applyTheme(e.matches ? 'dark' : 'light');
                window.ClinickTheme.updateToggleButtons();
            }
        });
    }

    document.addEventListener('DOMContentLoaded', function() {
        window.ClinickTheme.updateToggleButtons();
    });
})();
