document.addEventListener('DOMContentLoaded', () => {
    const signUpButton = document.getElementById('signUp');
    const signInButton = document.getElementById('signIn');
    const container = document.getElementById('container');
    
    // Mobile toggles
    const toSignUpMobile = document.getElementById('toSignUpMobile');
    const toSignInMobile = document.getElementById('toSignInMobile');

    if (signUpButton && signInButton && container) {
        signUpButton.addEventListener('click', () => {
            container.classList.add('right-panel-active');
            clearAlerts();
        });

        signInButton.addEventListener('click', () => {
            container.classList.remove('right-panel-active');
            clearAlerts();
        });
    }

    // Mobile layout toggling
    if (toSignUpMobile) {
        toSignUpMobile.addEventListener('click', (e) => {
            e.preventDefault();
            container.classList.add('right-panel-active');
        });
    }

    if (toSignInMobile) {
        toSignInMobile.addEventListener('click', (e) => {
            e.preventDefault();
            container.classList.remove('right-panel-active');
        });
    }

    // Client-side password matching validation
    const registerForm = document.querySelector('.sign-up-container form');
    if (registerForm) {
        registerForm.addEventListener('submit', (e) => {
            const password = document.getElementById('reg-password').value;
            const confirmPassword = document.getElementById('reg-confirm-password').value;

            if (password !== confirmPassword) {
                e.preventDefault();
                showError('Passwords do not match.', '.sign-up-container');
            }
        });
    }

    function clearAlerts() {
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(alert => alert.remove());
    }

    function showError(message, parentSelector) {
        clearAlerts();
        const parent = document.querySelector(parentSelector);
        const heading = parent.querySelector('h2');
        
        const alertDiv = document.createElement('div');
        alertDiv.className = 'alert alert-danger';
        alertDiv.innerHTML = `<i class="fa-solid fa-triangle-exclamation"></i> <span>${message}</span>`;
        
        // Insert alert right after the heading
        if (heading && heading.nextSibling) {
            parent.insertBefore(alertDiv, heading.nextSibling);
        }
    }
});
