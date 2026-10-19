const observer = new IntersectionObserver((entries) => {
    entries.forEach(entry => { if (entry.isIntersecting) entry.target.classList.add('in'); });
  }, { threshold: 0.12, rootMargin: '0px 0px -60px 0px' });
  document.querySelectorAll('.reveal').forEach(el => observer.observe(el));

const navToggle = document.querySelector('.nav-toggle');
const navLinks = document.querySelector('.nav-links');
if (navToggle && navLinks) {
  navToggle.addEventListener('click', () => {
    const isOpen = navLinks.classList.toggle('open');
    navToggle.classList.toggle('open', isOpen);
    navToggle.setAttribute('aria-expanded', isOpen);
  });
  navLinks.querySelectorAll('a').forEach(link => {
    link.addEventListener('click', () => {
      navLinks.classList.remove('open');
      navToggle.classList.remove('open');
      navToggle.setAttribute('aria-expanded', 'false');
    });
  });
}

/* ---------- Booking form ---------- */
const bookingForm = document.querySelector('#booking-form');
if (bookingForm) {
  const status = bookingForm.querySelector('.form-status');
  const button = bookingForm.querySelector('button[type="submit"]');
  const stamp  = bookingForm.querySelector('input[name="ts"]');
  const label  = button ? button.innerHTML : '';

  // Lets the server tell humans from bots that post instantly.
  if (stamp) stamp.value = String(Date.now());

  const say = (msg, ok) => {
    if (!status) return;
    status.textContent = msg;
    status.className = 'form-status ' + (ok ? 'is-ok' : 'is-error');
  };

  bookingForm.addEventListener('submit', async (e) => {
    // Without fetch the form still posts normally to send.php.
    if (!window.fetch) return;
    e.preventDefault();

    if (!bookingForm.reportValidity()) return;

    button.disabled = true;
    button.innerHTML = 'Sending...';
    say('', true);
    status.className = 'form-status';

    try {
      const res = await fetch(bookingForm.action, {
        method: 'POST',
        body: new FormData(bookingForm),
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
      });
      const data = await res.json().catch(() => ({
        ok: false,
        message: 'Unexpected response from the server. Please email us directly.'
      }));

      if (data.ok) {
        bookingForm.reset();
        if (stamp) stamp.value = String(Date.now());
        say(data.message || 'Thank you, we will be in touch within 24 hours.', true);
      } else {
        say(data.message || 'Something went wrong. Please try again.', false);
      }
    } catch (err) {
      say('Could not reach the server. Please email flashinglightsphotobooth11@gmail.com directly.', false);
    } finally {
      button.disabled = false;
      button.innerHTML = label;
    }
  });
}
