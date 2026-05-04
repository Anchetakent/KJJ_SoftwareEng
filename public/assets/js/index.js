document.addEventListener('DOMContentLoaded', () => {
    const resendForms = document.querySelectorAll('[data-resend-form]');
    resendForms.forEach((form) => {
        const button = form.querySelector('[data-resend-button]');
        if (!button) return;

        form.addEventListener('submit', () => {
            const cooldownSeconds = parseInt(button.getAttribute('data-cooldown-seconds') || '60', 10);
            let remaining = Number.isNaN(cooldownSeconds) ? 60 : cooldownSeconds;
            const originalText = button.textContent;

            button.disabled = true;
            button.textContent = `Resend in ${remaining}s`;

            const timer = setInterval(() => {
                remaining -= 1;
                if (remaining <= 0) {
                    clearInterval(timer);
                    button.disabled = false;
                    button.textContent = originalText;
                    return;
                }
                button.textContent = `Resend in ${remaining}s`;
            }, 1000);
        });
    });
});
