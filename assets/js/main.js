(() => {
  // ----- Mobile nav toggle -----
  const toggle = document.querySelector('.nav-toggle');
  const nav    = document.getElementById('main-nav');
  if (toggle && nav) {
    toggle.addEventListener('click', () => {
      const open = nav.classList.toggle('open');
      toggle.setAttribute('aria-expanded', String(open));
    });
    nav.querySelectorAll('a').forEach(a => a.addEventListener('click', () => {
      nav.classList.remove('open');
      toggle.setAttribute('aria-expanded', 'false');
    }));
  }

  // ----- Pricing tier switcher -----
  const tierBtns   = document.querySelectorAll('.tier-btn');
  const tierPanels = document.querySelectorAll('.tier-panel');
  tierBtns.forEach(btn => btn.addEventListener('click', () => {
    const target = btn.dataset.tier;
    tierBtns.forEach(b => {
      const on = b === btn;
      b.classList.toggle('active', on);
      b.setAttribute('aria-selected', String(on));
    });
    tierPanels.forEach(p => {
      p.classList.toggle('active', p.dataset.panel === target);
    });
  }));

  // ----- Legal section switcher -----
  const legalBtns   = document.querySelectorAll('.legal-nav button');
  const legalPanels = document.querySelectorAll('.legal-panel');
  legalBtns.forEach(btn => btn.addEventListener('click', () => {
    const target = btn.dataset.legal;
    legalBtns.forEach(b => b.classList.toggle('active', b === btn));
    legalPanels.forEach(p => p.classList.toggle('active', p.dataset.legalPanel === target));
    // Scroll into view on small screens
    if (window.innerWidth < 800) {
      document.querySelector('.legal-content')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
  }));

  // ----- Contact form: client-side feedback -----
  const form  = document.getElementById('contactForm');
  const alert = document.getElementById('formAlert');
  if (form && alert) {
    form.addEventListener('submit', async (e) => {
      // Let the browser validate first
      if (!form.checkValidity()) return;

      e.preventDefault();
      alert.innerHTML = '';
      const btn = form.querySelector('button[type="submit"]');
      const originalText = btn.textContent;
      btn.disabled = true;
      btn.textContent = 'Sending...';

      try {
        const res = await fetch(form.action, {
          method: 'POST',
          body: new FormData(form),
          headers: { 'X-Requested-With': 'fetch' },
          redirect: 'follow',
        });
        if (res.ok) {
          alert.innerHTML = '<div class="alert alert-success">Thanks &mdash; we\'ve got your message and will be in touch shortly.</div>';
          form.reset();
        } else {
          throw new Error('bad response');
        }
      } catch {
        alert.innerHTML = '<div class="alert alert-error">Couldn\'t send right now. Please email admin@wifiber.co.za or call us directly.</div>';
      } finally {
        btn.disabled = false;
        btn.textContent = originalText;
      }
    });
  }
})();
