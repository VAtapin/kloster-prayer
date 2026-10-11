document.addEventListener('DOMContentLoaded', () => {
  const guide = document.querySelector('.klp-shortcode-help');
  if (!guide) return;
  guide.addEventListener('click', async event => {
    const button = event.target.closest('.klp-copy-shortcode');
    if (!button || !guide.contains(button)) return;
    const input = document.getElementById(button.dataset.target);
    if (!input || !guide.contains(input)) return;
    const status = guide.querySelector('.klp-copy-status');
    try {
      await navigator.clipboard.writeText(input.value);
      status.textContent = guide.dataset.copied;
    } catch (_) {
      input.focus();
      input.select();
      status.textContent = guide.dataset.copyFailed;
    }
  });
});
